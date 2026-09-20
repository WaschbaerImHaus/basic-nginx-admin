<?php
declare(strict_types=1);

/**
 * Wertobjekt: das über die Oberfläche gepflegte nginx-Snippet eines vHosts.
 *
 * Geprüft wird gegen eine Sperrliste (Nutzerentscheidung vom 2026-09-20): erlaubt ist
 * alles, was nicht ausdrücklich aus dem vHost herausführt. Damit lässt sich ein Fragment
 * aus der Konfiguration eines anderen Projekts per copy-paste einsetzen – eine
 * Positivliste hatte das verhindert, weil ein solches Fragment regelmäßig "if",
 * "fastcgi_pass", "include" oder "deny" enthält.
 *
 * Eine Sperrliste ist prinzipiell schwächer als eine Positivliste: nginx hat hunderte
 * Direktiven, Module bringen weitere mit, und was hier nicht aufgezählt ist, ist erlaubt.
 * Zweite Verteidigungslinie bleibt deshalb "nginx -t" vor jedem Reload samt Rücknahme
 * der Datei bei einem Fehlschlag (VhostService::setSnippet()). Gesperrt ist genau das,
 * was den vHost verlässt: fremde Pfade, der Verzeichnisschutz selbst, Ziele auf diesem
 * Rechner und alles, was Code im nginx-Prozess ausführt.
 *
 * Wichtigste Prüfung bleibt die Klammerbilanz – ohne sie könnte eine schließende
 * Klammer den umgebenden server-Block beenden und danach ein eigener server-Block
 * mit beliebigen Direktiven folgen.
 *
 * Token-Anfang ("$atTokenStart"): nginx behandelt "#" (Kommentarbeginn) sowie '"'
 * und "'" (Zeichenkette) nur dann als Sonderzeichen, wenn sie am Anfang eines
 * Tokens stehen (Textbeginn, nach Leerraum oder nach ; { }). Mitten in einem Wort
 * sind es für nginx gewöhnliche Zeichen, und alles danach bleibt aktive
 * Konfiguration. Ein Tokenizer, der stattdessen jedes "#"/'"'/"'" immer als
 * Sonderzeichen behandelt (wie die vorige Fassung), kann dadurch getäuscht
 * werden: Er hält einen Teil des Textes für einen Kommentar bzw. eine
 * Zeichenkette und prüft ihn deshalb gar nicht gegen die Positivliste, während
 * nginx genau diesen Teil als eigene, unter Umständen verbotene Direktive
 * ausführt. Statt den nginx-Tokenizer bei "#"/'"'/"'" exakt nachzubilden (inkl.
 * Fortsetzung der Prüfung ab der echten Wortgrenze), wählt diese Klasse die
 * strengere, einfachere Variante: Jedes "#", '"' oder "'", das nicht am Anfang
 * eines Tokens steht, führt zur Ablehnung. Das lehnt einzelne, in der Praxis
 * seltene Direktivenwerte mit eingebettetem "#"/Anführungszeichen ohne
 * umschließende Wortgrenze ab, vermeidet dafür aber jedes Risiko einer erneut
 * abweichenden Nachbildung des nginx-Tokenizers.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 21:25
 */

namespace VhostAdmin\Value;

final class NginxSnippet
{
	/**
	 * Direktiven, die den Verzeichnisschutz betreffen. Der wird ausschließlich über die
	 * Oberfläche verwaltet; "auth_basic off;" im Snippet würde ihn aufheben.
	 */
	private const PROTECTION_DIRECTIVES = ['auth_basic', 'auth_basic_user_file', 'satisfy'];

	/**
	 * Direktiven, die Code im nginx-Prozess ausführen oder Module nachladen könnten.
	 * Geprüft wird zusätzlich über Präfixe (siehe FORBIDDEN_PREFIXES).
	 */
	private const CODE_DIRECTIVES = ['load_module', 'perl', 'perl_set', 'perl_modules', 'perl_require'];

	/** Präfixe, unter denen alles gesperrt ist: Lua, njs und Perl-Einbettung. */
	private const FORBIDDEN_PREFIXES = ['lua_', 'js_', 'perl_'];

	/** Direktiven, deren Name auf eingebetteten Code hindeutet (z.B. content_by_lua_block). */
	private const CODE_INFIXES = ['_by_lua', '_by_njs'];

	/** Schreibzugriff über HTTP – hat in einem ausgelieferten Docroot nichts zu suchen. */
	private const WRITE_DIRECTIVES = ['dav_methods', 'dav_access', 'dav_ext_methods'];

	/**
	 * Direktiven, deren Argument ein Pfad innerhalb des vHosts sein muss, samt dem
	 * Ordner, der die Grenze bildet ("base", "conf" oder "logs").
	 */
	private const PATH_DIRECTIVES = [
		'root' => 'base',
		'alias' => 'base',
		'disable_symlinks' => null,
		'include' => 'conf',
		'access_log' => 'logs',
		'error_log' => 'logs',
		'fastcgi_temp_path' => 'base',
		'client_body_temp_path' => 'base',
		'proxy_temp_path' => 'base',
	];

	/**
	 * Direktiven, die auf einen Server verweisen. Das Ziel darf nicht dieser Rechner
	 * sein (die Oberfläche läuft ohne eigene Anmeldung) und als Unix-Socket nur der
	 * eigene FPM-Socket des vHosts.
	 */
	private const PASS_DIRECTIVES = [
		'proxy_pass', 'fastcgi_pass', 'uwsgi_pass', 'scgi_pass', 'grpc_pass', 'memcached_pass',
	];

	/** Direktiven, die Dateien ausserhalb des vHosts einlesen würden. */
	private const FORBIDDEN_DIRECTIVES = [
		'ssl_certificate', 'ssl_certificate_key', 'ssl_trusted_certificate', 'ssl_client_certificate',
		'ssl_password_file', 'ssl_dhparam',
	];

	/**
	 * fastcgi_param & Co. mit diesen Namen bestimmen, welche Datei PHP ausführt bzw.
	 * als Docroot sieht – zeigen sie aus dem vHost heraus, liesse sich damit der
	 * PHP-Code der Oberfläche ausführen.
	 */
	private const PATH_PARAMS = ['SCRIPT_FILENAME', 'DOCUMENT_ROOT', 'SCRIPT_NAME'];

	/**
	 * Direktiven, die die Bindung oder Identität des vHosts verändern würden.
	 *
	 * "listen" ist der wichtigste Fall: damit könnte ein localhost-Host zusätzlich
	 * öffentlich lauschen ("listen 0.0.0.0:8081;") und die zugesicherte Eigenschaft
	 * "localhost-Hosts sind nie über das Internet erreichbar" wäre aufgehoben.
	 * "server_name" liesse einen Host die Anfragen eines anderen abfangen.
	 * Die übrigen gehören in den Hauptteil von nginx.conf, nicht in einen server-Block.
	 */
	private const IDENTITY_DIRECTIVES = [
		'listen', 'server_name', 'server', 'user', 'worker_processes', 'pid', 'events', 'http', 'stream', 'upstream',
	];

	/**
	 * "allow" würde in einem location-Block die geerbten Regeln des Verzeichnisschutzes
	 * ersetzen und – zusammen mit dem "satisfy any" des Schutzes – den Zugang ohne
	 * Passwort öffnen. IP-Freigaben gehören deshalb in die Oberfläche, die sie verwaltet.
	 * "deny" bleibt erlaubt: es kann nur einschränken.
	 */
	private const ACCESS_DIRECTIVES = ['allow'];

	/** Direktiven, die einen Block öffnen dürfen. */
	private const BLOCK_DIRECTIVES = ['location', 'if', 'limit_except'];

	private const MAX_BYTES = 65536;
	private const MAX_DEPTH = 2;

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Prüft den Text und erzeugt das Wertobjekt.
	 *
	 * Tokenizer: Durchläuft den Text zeichenweise, respektiert Anführungszeichen,
	 * Kommentare und Escapes, und validiert jede Anweisung auf Direktivenliste,
	 * Klammern und Verschachtelungstiefe.
	 *
	 * @param ?SnippetScope $scope Pfadgrenzen des vHosts; ohne Bezugsrahmen werden
	 *                             pfadgebundene Direktiven grundsätzlich abgelehnt
	 * @throws \InvalidArgumentException mit Zeilennummer und beanstandeter Direktive
	 */
	public static function fromString(string $text, ?SnippetScope $scope = null): self
	{
		if (strlen($text) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Das Snippet ist größer als 64 KB.');
		}

		$len = strlen($text);
		$pos = 0;
		$line = 1;
		$depth = 0;
		$token = '';
		$tokenLine = 1;
		$inQuote = null;
		$escaped = false;
		$tokenHasContent = false;
		// Stehen wir am Anfang eines Tokens (Textbeginn, nach Leerraum oder nach ; { })?
		// Nur dort dürfen "#", '"' und "'" ihre Sonderbedeutung entfalten (siehe Klassen-DocBlock).
		$atTokenStart = true;

		while ($pos < $len) {
			$char = $text[$pos];

			// Zeichenweise durchgehen; Zeilennummern mitlaufen
			if ($char === "\n") {
				$line++;
			}

			// Innerhalb von Anführungszeichen: nur Escapes beachten
			if ($inQuote !== null) {
				$token .= $char;
				$tokenHasContent = true;
				$atTokenStart = false;
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === $inQuote) {
					$inQuote = null;
				}
				$pos++;
				continue;
			}

			// Außerhalb von Anführungszeichen
			if ($char === '"' || $char === "'") {
				if (!$atTokenStart) {
					throw new \InvalidArgumentException(
						"Zeile $line: ein Anführungszeichen ist nur am Anfang eines Tokens erlaubt, nicht mitten im Wort."
					);
				}
				$inQuote = $char;
				$token .= $char;
				$tokenHasContent = true;
				$atTokenStart = false;
				$pos++;
				continue;
			}

			// Kommentar: ab # bis zum Zeilenende (nur am Anfang eines Tokens, siehe DocBlock)
			if ($char === '#') {
				if (!$atTokenStart) {
					throw new \InvalidArgumentException(
						"Zeile $line: \"#\" ist nur am Anfang eines Tokens ein Kommentarzeichen, nicht mitten im Wort."
					);
				}
				while ($pos < $len && $text[$pos] !== "\n") {
					$pos++;
				}
				continue;
			}

			// Anweisung beenden bei Semikolon oder Block-Öffner/Schließer
			if ($char === ';' || $char === '{' || $char === '}') {
				$token = trim($token);

				if ($char === '}') {
					// Schließende Klammer: zuerst prüfen, ob noch Token offen ist
					if ($token !== '') {
						throw new \InvalidArgumentException(
							"Zeile $tokenLine: unvollständige Anweisung vor schließender Klammer (fehlendes Semikolon?)."
						);
					}
					// Tiefe verringern und prüfen
					if ($depth === 0) {
						throw new \InvalidArgumentException(
							"Zeile $line: schließende Klammer ohne geöffneten Block – das würde den server-Block verlassen."
						);
					}
					$depth--;
				} else {
					// Semikolon oder Block-Öffner: Token prüfen
					if ($token !== '') {
						$parts = preg_split('/[\s]/', $token, 2);
						$directive = strtolower((string)($parts[0] ?? ''));
						$argument = trim((string)($parts[1] ?? ''));
						if ($directive !== '') {
							if ($char === '{') {
								// Erst die namensbasierten Sperren, damit z.B.
								// "content_by_lua_block" den passenden Grund nennt und nicht
								// nur "darf keinen Block öffnen".
								self::assertDirectiveNameAllowed($directive, $tokenLine);
								// Block-Direktive
								if (!in_array($directive, self::BLOCK_DIRECTIVES, true)) {
									throw new \InvalidArgumentException(
										"Zeile $tokenLine: \"$directive\" darf keinen Block öffnen – erlaubt sind "
										. implode(', ', self::BLOCK_DIRECTIVES) . '.'
									);
								}
								$depth++;
								if ($depth > self::MAX_DEPTH) {
									throw new \InvalidArgumentException(
										"Zeile $tokenLine: Blöcke dürfen höchstens " . self::MAX_DEPTH . " Ebenen tief verschachtelt sein."
									);
								}
							} else {
								// Normale Direktive (Semikolon)
								self::assertDirectiveAllowed($directive, $argument, $tokenLine, $scope);
							}
						}
					} elseif ($char === ';') {
						// Leeres Semikolon (nur Leerraum voraus) – das ist akzeptabel
					} elseif ($char === '{' && $token === '') {
						// Öffnende Klammer ohne Direktive: Fehler
						throw new \InvalidArgumentException(
							"Zeile $line: öffnende Klammer ohne Direktive (erlaubt sind "
							. implode(', ', self::BLOCK_DIRECTIVES) . ').'
						);
					}
				}
				$token = '';
				$tokenHasContent = false;
				// Nach ; { } beginnt zwingend ein neues Token.
				$atTokenStart = true;
			} else {
				// Token-Text sammeln; Zeile des Token-Anfangs merken (nur bei echtem Inhalt)
				$isWhitespace = $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
				if (!$tokenHasContent && !$isWhitespace) {
					$tokenLine = $line;
					$tokenHasContent = true;
				}
				$token .= $char;
				// Leerraum beendet das aktuelle (Teil-)Token; jedes andere Zeichen befindet
				// sich entweder schon mitten im Token oder beginnt gerade erst eins.
				$atTokenStart = $isWhitespace;
			}

			$pos++;
		}

		// Am Ende: offene Anführungszeichen prüfen
		if ($inQuote !== null) {
			throw new \InvalidArgumentException(
				"Zeile $tokenLine: offenes Anführungszeichen, nicht geschlossen."
			);
		}

		// Unabgeschlossene Anweisung (kein abschließendes Semikolon)
		if (trim($token) !== '') {
			throw new \InvalidArgumentException(
				"Zeile $tokenLine: unvollständige Anweisung – fehlendes Semikolon."
			);
		}

		// Offene Blöcke
		if ($depth !== 0) {
			throw new \InvalidArgumentException('Es wurde ein Block geöffnet, aber nicht geschlossen.');
		}

		return new self($text);
	}

	/**
	 * I3 (Abschlussreview 2026-09-20): proxy_pass bleibt erlaubt, das Ziel darf aber
	 * nicht auf den eigenen Rechner zeigen. Die Oberfläche selbst läuft auf
	 * 127.0.0.1:8080 ohne eigene Anmeldung; ein proxy_pass auf sie würde die
	 * Oberfläche über jede beliebige öffentliche Domain erreichbar machen, weil
	 * nginx den Port beim server_name-Vergleich abschneidet.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und Grund
	 */
	private static function assertProxyPassTargetAllowed(string $argument, int $line): void
	{
		if ($argument === '') {
			throw new \InvalidArgumentException("Zeile $line: \"proxy_pass\" ohne Ziel.");
		}
		// Eine Variable im Ziel (z.B. $backend) steht erst zur Laufzeit fest und lässt
		// sich hier nicht prüfen – deshalb sicherheitshalber ablehnen statt durchlassen.
		if (str_contains($argument, '$')) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"proxy_pass\" mit einer Variable im Ziel ist nicht erlaubt, das Ziel muss beim Speichern feststehen."
			);
		}
		$target = $argument;
		if (($target[0] === '"' || $target[0] === "'") && strlen($target) >= 2 && str_ends_with($target, $target[0])) {
			$target = substr($target, 1, -1);
		}
		if (stripos($target, 'unix:') === 0) {
			throw new \InvalidArgumentException("Zeile $line: \"proxy_pass\" auf einen Unix-Socket ist nicht erlaubt.");
		}
		$host = self::proxyPassTargetHost($target);
		if ($host !== null) {
			self::assertHostNotOwnMachine($host, $line);
		}
	}

	/**
	 * Host-Teil eines proxy_pass-Ziels, auch ohne Schema (z.B. "127.0.0.1:8080").
	 */
	private static function proxyPassTargetHost(string $target): ?string
	{
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $target)) {
			$target = 'http://' . $target;
		}
		$host = parse_url($target, PHP_URL_HOST);
		return is_string($host) && $host !== '' ? $host : null;
	}

	/**
	 * Befund 1 (Re-Review 2026-09-20): Der bisherige Vergleich prüfte den Host als
	 * Zeichenkette gegen eine Handvoll bekannter Schreibweisen ("127.", "localhost",
	 * "0.0.0.0", "::", "::1"). Nachgewiesene Umgehungen: dezimale ("2130706433"),
	 * oktale ("0177.0.0.1") und hexadezimale ("0x7f000001") Schreibweisen einer
	 * IPv4-Adresse, die kurze Form "0" statt "0.0.0.0", ein abschließender Punkt
	 * ("localhost."), die IPv4-mapped-IPv6-Form ("::ffff:127.0.0.1") sowie
	 * ausgeschriebene/aufgefüllte IPv6-Loopback-Varianten ("0:0:0:0:0:0:0:1",
	 * "::0001"). Alle akzeptiert PHPs eigener Host-Parser bzw. die Namensauflösung,
	 * ohne dass der alte Zeichenkettenvergleich sie erkannt hätte.
	 *
	 * Deshalb wird jetzt auf der tatsächlichen Adresse geprüft:
	 *  1. "localhost" (und *.localhost) wird als Name erkannt, unabhängig von
	 *     Groß-/Kleinschreibung und einem abschließenden Punkt – und zwar OHNE
	 *     Namensauflösung, denn der lokale Resolver kann für "localhost" beliebige,
	 *     u.U. irreführende Ergebnisse liefern (in dieser Umgebung z.B. eine
	 *     AAAA-Antwort für "localhost.me").
	 *  2. Ist der Host selbst schon eine IP-Literale (inet_pton() gelingt, auch nach
	 *     Entfernen umschließender IPv6-Klammern), wird genau diese Adresse geprüft.
	 *  3. Sonst wird der Name aufgelöst: gethostbynamel() für IPv4 – das übernimmt
	 *     nebenbei auch die von PHP/der libc akzeptierten numerischen Formen
	 *     (dezimal, oktal, "127.1" u.ä.), weil PHP sie an denselben Resolver
	 *     durchreicht – und, falls verfügbar, dns_get_record(..., DNS_AAAA) für
	 *     IPv6. Jede zurückgegebene Adresse wird geprüft.
	 *  4. Lässt sich der Name NICHT auflösen, wird nur abgelehnt, wenn er wie eine
	 *     Zahl/Adresse aussieht (siehe looksLikeNumericHost()) – ein "richtiger"
	 *     Domainname, der hier nur mangels Netzanbindung nicht auflöst, bleibt
	 *     erlaubt, sonst würden harmlose, tatsächlich erreichbare Ziele blockiert.
	 *
	 * @throws \InvalidArgumentException wenn das Ziel auf den eigenen Rechner zeigt
	 *         oder sich nicht auflösen lässt, obwohl es wie eine Adresse aussieht
	 */
	private static function assertHostNotOwnMachine(string $host, int $line): void
	{
		$normalized = rtrim(strtolower(trim($host, '[]')), '.');
		if ($normalized === '') {
			return;
		}

		if ($normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
			throw new \InvalidArgumentException(self::ownMachineMessage($line, $host));
		}

		if (@inet_pton($normalized) !== false) {
			if (self::isForbiddenAddress($normalized)) {
				throw new \InvalidArgumentException(self::ownMachineMessage($line, $host));
			}
			return;
		}

		$addresses = self::resolveHostAddresses($normalized);
		if ($addresses === []) {
			if (self::looksLikeNumericHost($normalized)) {
				throw new \InvalidArgumentException(
					"Zeile $line: \"proxy_pass\"-Ziel (\"$host\") lässt sich nicht auflösen und könnte auf "
					. 'den eigenen Rechner zeigen – das Ziel muss beim Speichern feststehen und geprüft werden können.'
				);
			}
			return;
		}

		foreach ($addresses as $address) {
			if (self::isForbiddenAddress($address)) {
				throw new \InvalidArgumentException(self::ownMachineMessage($line, $host, $address));
			}
		}
	}

	private static function ownMachineMessage(int $line, string $host, ?string $resolvedAs = null): string
	{
		$target = $resolvedAs !== null && $resolvedAs !== $host ? "\"$host\" -> \"$resolvedAs\"" : "\"$host\"";
		return "Zeile $line: \"proxy_pass\" auf den eigenen Rechner ($target) ist nicht erlaubt – "
			. 'das würde die Oberfläche über diese Domain erreichbar machen.';
	}

	/**
	 * Löst einen Namen in seine Adresse(n) auf: IPv4 über gethostbynamel(), IPv6
	 * (falls verfügbar) über dns_get_record() mit DNS_AAAA. Beides wird mit "@"
	 * unterdrückt: ein Fehlschlag ist hier kein Programmfehler, sondern der
	 * normale Fall bei einem unbekannten oder (noch) nicht erreichbaren Namen.
	 *
	 * @return list<string>
	 */
	private static function resolveHostAddresses(string $host): array
	{
		$addresses = [];
		$ipv4 = @gethostbynamel($host);
		if (is_array($ipv4)) {
			$addresses = $ipv4;
		}
		if (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					if (isset($record['ipv6']) && is_string($record['ipv6'])) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}
		return $addresses;
	}

	/**
	 * Sieht der (nicht auflösbare) Host wie eine Zahl bzw. Adresse aus – also wie
	 * dezimale ("2130706433"), oktale ("0177.0.0.1") oder hexadezimale
	 * ("0x7f000001") Schreibweisen, die PHP/die libc als Adresse akzeptieren
	 * würden, wäre der Resolver erreichbar? Ein regulärer Domainname wie
	 * "backend.example.com" enthält immer mindestens ein Segment, das weder aus
	 * reinen Ziffern noch aus einer "0x"-Hex-Zahl besteht, und fällt damit nicht
	 * hierunter.
	 */
	private static function looksLikeNumericHost(string $host): bool
	{
		return (bool)preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host);
	}

	/**
	 * Zeigt die Adresse auf "diesen Rechner" (Loopback oder unspezifiziert)?
	 * Vergleicht numerisch über die von inet_pton() gelieferten Bytes, nicht als
	 * Zeichenkette – abgelehnt werden 127.0.0.0/8, 0.0.0.0/8, ::1, :: sowie die
	 * IPv4-mapped-Gegenstücke ::ffff:127.0.0.0/104 und ::ffff:0.0.0.0/104.
	 *
	 * Die unspezifizierte Adresse gehört dazu, weil ein connect() darauf beim
	 * Betriebssystem auf dem Loopback landet: In dieser Umgebung nachgemessen
	 * erreichten "::ffff:0.0.0.0" und "::ffff:0:0" den Listener auf
	 * 127.0.0.1:8080 tatsächlich (nachgetragen 2026-09-20, zweite Fix-Runde;
	 * die erste Fassung prüfte beim IPv4-mapped-Fall nur auf 127).
	 */
	private static function isForbiddenAddress(string $address): bool
	{
		$packed = @inet_pton($address);
		if ($packed === false) {
			// Kann bei den Aufrufstellen nicht vorkommen (die Adresse stammt selbst aus
			// inet_pton()/gethostbynamel()/dns_get_record()); im Zweifel ablehnen.
			return true;
		}
		if (strlen($packed) === 4) {
			// IPv4: 127.0.0.0/8 (Loopback) und 0.0.0.0/8 (unspezifiziert/"diese Maschine").
			$firstOctet = ord($packed[0]);
			return $firstOctet === 127 || $firstOctet === 0;
		}
		// ::1 (Loopback) und :: (unspezifiziert) – beide bezeichnen diesen Rechner.
		if ($packed === (string)inet_pton('::1') || $packed === (string)inet_pton('::')) {
			return true;
		}
		// ::ffff:0:0/96 ist der IPv4-mapped-Bereich: erste 12 Byte sind das Präfix, die
		// letzten 4 die eingebettete IPv4-Adresse. Für die gilt genau dieselbe Regel wie
		// oben (erstes Oktett 127 oder 0), sonst wäre "::ffff:0.0.0.0" erlaubt, obwohl es
		// nachgemessen auf dem Loopback ankommt.
		$v4MappedPrefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
		if (substr($packed, 0, 12) !== $v4MappedPrefix) {
			return false;
		}
		$embeddedFirstOctet = ord($packed[12]);
		return $embeddedFirstOctet === 127 || $embeddedFirstOctet === 0;
	}

	/**
	 * Enthält das Snippet außer Leerraum nichts?
	 */
	public function isEmpty(): bool
	{
		return trim($this->value) === '';
	}

	/**
	 * Der geprüfte Text, wie er in die Datei geschrieben wird.
	 */
	public function __toString(): string
	{
		return $this->value;
	}

	/**
	 * Kern der Sperrliste: erlaubt ist alles, was hier nicht beanstandet wird.
	 *
	 * @param ?SnippetScope $scope Pfadgrenzen; null = pfadgebundene Direktiven ablehnen
	 * @throws \InvalidArgumentException mit Zeilennummer und Grund
	 */
	private static function assertDirectiveAllowed(string $directive, string $argument, int $line, ?SnippetScope $scope): void
	{
		self::assertDirectiveNameAllowed($directive, $line);
		if (in_array($directive, self::PASS_DIRECTIVES, true)) {
			self::assertPassTargetAllowed($directive, $argument, $line, $scope);
			return;
		}
		if (array_key_exists($directive, self::PATH_DIRECTIVES)) {
			self::assertPathAllowed($directive, $argument, $line, $scope);
			return;
		}
		if (self::isPathParam($directive, $argument)) {
			self::assertParamPathAllowed($directive, $argument, $line, $scope);
		}
	}

	/**
	 * Sperren, die sich allein am Namen der Direktive entscheiden.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und Grund
	 */
	private static function assertDirectiveNameAllowed(string $directive, int $line): void
	{
		if (in_array($directive, self::IDENTITY_DIRECTIVES, true)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – Bindung und Name des vHosts werden über die Oberfläche verwaltet."
			);
		}
		if (in_array($directive, self::ACCESS_DIRECTIVES, true)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – IP-Freigaben werden über die Oberfläche verwaltet "
				. '(sonst liesse sich der Verzeichnisschutz damit umgehen).'
			);
		}
		if (in_array($directive, self::PROTECTION_DIRECTIVES, true)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – der Verzeichnisschutz wird über die Oberfläche verwaltet."
			);
		}
		if (self::isCodeDirective($directive)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – damit liesse sich Code im nginx-Prozess ausführen."
			);
		}
		if (in_array($directive, self::WRITE_DIRECTIVES, true)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – das erlaubte Schreibzugriff über HTTP."
			);
		}
		if (in_array($directive, self::FORBIDDEN_DIRECTIVES, true)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist nicht erlaubt – Zertifikate und Schlüssel verwaltet die Oberfläche."
			);
		}
	}

	/**
	 * Deutet der Name auf eingebetteten Code hin (Lua, njs, Perl)?
	 */
	private static function isCodeDirective(string $directive): bool
	{
		if (in_array($directive, self::CODE_DIRECTIVES, true)) {
			return true;
		}
		foreach (self::FORBIDDEN_PREFIXES as $prefix) {
			if (str_starts_with($directive, $prefix)) {
				return true;
			}
		}
		foreach (self::CODE_INFIXES as $infix) {
			if (str_contains($directive, $infix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ist das eine *_param-Direktive, die einen Pfad für PHP festlegt?
	 */
	private static function isPathParam(string $directive, string $argument): bool
	{
		if (!in_array($directive, ['fastcgi_param', 'uwsgi_param', 'scgi_param'], true)) {
			return false;
		}
		$name = strtoupper((string)(preg_split('/\s+/', trim($argument), 2)[0] ?? ''));
		return in_array($name, self::PATH_PARAMS, true);
	}

	/**
	 * Prüft den Pfad einer *_param-Direktive (SCRIPT_FILENAME & Co.).
	 *
	 * Werte mit nginx-Variablen wie "$document_root$fastcgi_script_name" sind in
	 * Ordnung: $document_root ist der über "root" gesetzte Pfad, der selbst schon
	 * gegen den vHost geprüft wurde.
	 */
	private static function assertParamPathAllowed(string $directive, string $argument, int $line, ?SnippetScope $scope): void
	{
		$parts = preg_split('/\s+/', trim($argument), 2);
		$name = strtoupper((string)($parts[0] ?? ''));
		$value = self::unquote(trim((string)($parts[1] ?? '')));
		if ($value === '' || str_contains($value, '$')) {
			return;
		}
		if (!str_starts_with($value, '/')) {
			return;
		}
		if ($scope === null) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive $name\" mit festem Pfad ist hier nicht prüfbar und deshalb nicht erlaubt."
			);
		}
		if (!SnippetScope::isInside($value, $scope->baseDir)) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive $name\" zeigt mit \"$value\" ausserhalb von {$scope->baseDir} – "
				. 'damit liesse sich fremder PHP-Code ausführen.'
			);
		}
	}

	/**
	 * Prüft eine pfadgebundene Direktive gegen die Grenzen des vHosts.
	 */
	private static function assertPathAllowed(string $directive, string $argument, int $line, ?SnippetScope $scope): void
	{
		$boundaryKey = self::PATH_DIRECTIVES[$directive];
		if ($boundaryKey === null) {
			return;
		}
		$value = self::unquote(trim($argument));
		// "access_log off;" und "error_log ... <stufe>;" schreiben nirgendwohin.
		if ($value === 'off' || $value === '') {
			return;
		}
		// Nur der erste Wert ist der Pfad (z.B. "access_log <pfad> <format>;").
		$path = (string)(preg_split('/\s+/', $value, 2)[0] ?? '');
		if ($path === 'off') {
			return;
		}
		if (str_contains($path, '$')) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" mit einer Variable im Pfad ist nicht erlaubt – der Pfad muss beim Speichern feststehen."
			);
		}
		if ($scope === null) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" ist ohne bekannte Pfadgrenzen des vHosts nicht erlaubt."
			);
		}
		$boundary = match ($boundaryKey) {
			'conf' => $scope->confDir,
			'logs' => $scope->logsDir,
			default => $scope->baseDir,
		};
		if (SnippetScope::isInside($path, $boundary)) {
			return;
		}
		$hint = match ($boundaryKey) {
			'conf' => "nur Dateien in conf/ ($boundary) dürfen eingebunden werden",
			'logs' => "nur Dateien in logs/ ($boundary) sind erlaubt",
			default => "der Pfad muss innerhalb von $boundary liegen",
		};
		throw new \InvalidArgumentException(
			"Zeile $line: \"$directive $path\" zeigt ausserhalb des vHosts – $hint."
		);
	}

	/**
	 * Prüft das Ziel einer *_pass-Direktive: nicht dieser Rechner, und als Unix-Socket
	 * nur der eigene FPM-Socket dieses vHosts.
	 */
	private static function assertPassTargetAllowed(string $directive, string $argument, int $line, ?SnippetScope $scope): void
	{
		if ($argument === '') {
			throw new \InvalidArgumentException("Zeile $line: \"$directive\" ohne Ziel.");
		}
		if (str_contains($argument, '$')) {
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" mit einer Variable im Ziel ist nicht erlaubt, das Ziel muss beim Speichern feststehen."
			);
		}
		$target = self::unquote($argument);
		if (stripos($target, 'unix:') === 0) {
			$socket = substr($target, 5);
			// nginx erlaubt "unix:/pfad:/uri" – nur der Teil vor einem ":" ist der Socket.
			$socket = explode(':', $socket, 2)[0];
			if ($scope?->phpSocket !== null && SnippetScope::normalize($socket) === SnippetScope::normalize($scope->phpSocket)) {
				return;
			}
			throw new \InvalidArgumentException(
				"Zeile $line: \"$directive\" darf als Unix-Socket nur den eigenen FPM-Socket dieses vHosts nutzen"
				. ($scope?->phpSocket !== null ? " ({$scope->phpSocket})" : ' – PHP ist für diesen Host aus')
				. '. Ein fremder Socket liesse sich nutzen, um PHP unter einem anderen Benutzer auszuführen.'
			);
		}
		$host = self::proxyPassTargetHost($target);
		if ($host !== null) {
			self::assertHostNotOwnMachine($host, $line);
		}
	}

	/**
	 * Entfernt umschließende Anführungszeichen, falls vorhanden.
	 */
	private static function unquote(string $value): string
	{
		if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
			return substr($value, 1, -1);
		}
		return $value;
	}
}
