<?php
declare(strict_types=1);

/**
 * Ruft den ACME-Marker jeder Domain über HTTP ab – parallel, mit kurzen Zeitgrenzen.
 *
 * Geprüft wird derselbe Weg, den Let's Encrypt für die http-01-Prüfung nimmt:
 * http://<domain>/.well-known/acme-challenge/<datei>. Stimmt der Inhalt, ist belegt,
 * dass der Name auf diesen Rechner zeigt, Port 80 aus dem Netz erreichbar ist und der
 * ACME-Pfad trotz Verzeichnisschutz ausgeliefert wird. Damit taugt derselbe Test als
 * Erreichbarkeitsanzeige der Domain überhaupt.
 *
 * Grenze dieses Tests, die er nicht überwinden kann: Die Anfrage kommt von diesem
 * Rechner selbst. Läuft sie über die eigene Leitung zurück (Hairpin-NAT), kann sie
 * gelingen, obwohl ein fremdes Netz den Port nicht erreicht – und umgekehrt kann sie
 * scheitern, obwohl von aussen alles stimmt. Ein "erreichbar" ist deshalb ein starkes
 * Indiz, keine Garantie; ein "fremder Server" dagegen ist eine verlässliche Aussage:
 * dann antwortet nachweislich nicht dieser Rechner.
 *
 * Absichtlich ohne Weiterleitungen: Der ACME-Pfad muss direkt antworten. Bei
 * eingeschaltetem HTTPS leitet der Port-80-Block alles andere um, den ACME-Pfad aber
 * nicht (location ^~ hat Vorrang) – eine Weiterleitung hier wäre also ein Fehler.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:15
 */

namespace VhostAdmin\Ssl;

final class CurlAcmeReachability implements AcmeReachabilityInterface
{
	/** Dateiname des Markers unterhalb von /.well-known/acme-challenge/. */
	public const MARKER = 'vhost-admin-health';

	/**
	 * @param int $connectTimeout Sekunden für den Verbindungsaufbau
	 * @param int $totalTimeout   Sekunden für die gesamte Anfrage
	 */
	public function __construct(
		private readonly int $connectTimeout = 3,
		private readonly int $totalTimeout = 5,
	) {
	}

	/**
	 * URL, unter der der Marker einer Domain erreichbar sein muss.
	 */
	public static function url(string $domain): string
	{
		return 'http://' . $domain . '/.well-known/acme-challenge/' . self::MARKER;
	}

	/**
	 * @param array<string, string> $targets
	 * @return array<string, ReachabilityResult>
	 */
	public function checkMany(array $targets): array
	{
		if ($targets === []) {
			return [];
		}
		$multi = curl_multi_init();
		$handles = [];
		foreach ($targets as $domain => $expected) {
			$handle = curl_init(self::url($domain));
			curl_setopt_array($handle, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
				CURLOPT_TIMEOUT => $this->totalTimeout,
				// Kein Zwischenspeicher irgendwo unterwegs: der Test soll den
				// tatsächlichen Zustand zeigen, nicht den von vorhin.
				CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
				CURLOPT_USERAGENT => 'vhost-admin Erreichbarkeitstest',
			]);
			curl_multi_add_handle($multi, $handle);
			$handles[$domain] = $handle;
		}

		$running = null;
		do {
			curl_multi_exec($multi, $running);
			if ($running > 0) {
				curl_multi_select($multi, 0.2);
			}
		} while ($running > 0);

		// Fehler einzelner Anfragen stehen NICHT in curl_errno($handle), sondern im Feld
		// "result" der Nachrichten von curl_multi_info_read(). Ohne diesen Schritt sah
		// eine nicht auflösbare Domain wie eine leere HTTP-Antwort aus und wurde als
		// "fremder Server" gemeldet statt als fehlender DNS-Eintrag (im Smoke-Test
		// aufgefallen, 2026-09-20).
		$errorByHandle = [];
		while (($message = curl_multi_info_read($multi)) !== false) {
			$errorByHandle[(int)$message['handle']] = (int)$message['result'];
		}

		$results = [];
		foreach ($handles as $domain => $handle) {
			$body = (string)curl_multi_getcontent($handle);
			$code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$errno = $errorByHandle[(int)$handle] ?? curl_errno($handle);
			$error = $errno !== 0 ? (curl_error($handle) ?: curl_strerror($errno) ?? 'unbekannter Fehler') : '';
			curl_multi_remove_handle($multi, $handle);
			curl_close($handle);
			$results[$domain] = $this->interpret($domain, $targets[$domain], $body, $code, $errno, $error);
		}
		curl_multi_close($multi);
		return $results;
	}

	/**
	 * Übersetzt die curl-Ausgabe in ein Ergebnis mit verwertbarem Hinweis.
	 */
	private function interpret(string $domain, string $expected, string $body, int $code, int $errno, string $error): ReachabilityResult
	{
		if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
			return new ReachabilityResult(
				ReachabilityStatus::DnsFailed,
				"\"$domain\" löst nicht auf. Ein A- bzw. AAAA-Eintrag auf diesen Server fehlt."
			);
		}
		if ($errno !== 0) {
			return new ReachabilityResult(
				ReachabilityStatus::Unreachable,
				"\"$domain\" antwortet auf Port 80 nicht ($error). Zeigt der DNS-Eintrag auf diesen"
				. ' Server, und ist Port 80 aus dem Internet freigegeben?'
			);
		}
		if ($code === 200 && trim($body) === trim($expected)) {
			return new ReachabilityResult(
				ReachabilityStatus::Ok,
				"Der ACME-Pfad von \"$domain\" ist erreichbar und liefert die erwartete Kennung.",
				$code
			);
		}
		// Gar keine HTTP-Antwort und trotzdem kein gemeldeter Fehler: dann ist nichts
		// angekommen. "Nicht erreichbar" ist hier die wahrheitsgemässe Aussage – "fremder
		// Server" würde behaupten, es habe jemand geantwortet.
		if ($code === 0) {
			return new ReachabilityResult(
				ReachabilityStatus::Unreachable,
				"\"$domain\" hat nicht geantwortet. Zeigt der DNS-Eintrag auf diesen Server,"
				. ' und ist Port 80 aus dem Internet freigegeben?'
			);
		}
		if ($code >= 300 && $code < 400) {
			return new ReachabilityResult(
				ReachabilityStatus::WrongServer,
				"\"$domain\" leitet den ACME-Pfad um (HTTP $code). Let's Encrypt braucht dort eine"
				. ' unmittelbare Antwort – vermutlich antwortet ein anderer Server oder ein Proxy.',
				$code
			);
		}
		return new ReachabilityResult(
			ReachabilityStatus::WrongServer,
			"Auf \"$domain\" antwortet ein Server, aber nicht dieser (HTTP $code"
			. ($body === '' ? ', leere Antwort' : ', unerwarteter Inhalt')
			. "). Der DNS-Eintrag zeigt wahrscheinlich auf einen anderen Rechner.",
			$code
		);
	}
}
