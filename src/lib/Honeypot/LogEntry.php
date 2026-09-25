<?php
declare(strict_types=1);

/**
 * Eine Zeile aus dem nginx-Combined-Log als Wertobjekt.
 *
 * Bewusst ohne Client-Adresse als Kennzahl: Vor diesem Rechner sitzt eine
 * Adressumsetzung (NAT), jede Anfrage von aussen erscheint als 10.200.0.1. Die Adresse
 * wird zwar gelesen, taugt hier aber nur zur Unterscheidung „von aussen" / „vom Rechner
 * selbst" – alles andere wäre erfunden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:10
 */

namespace Honeypot;

final class LogEntry
{
	/** Verben, die nginx als gewöhnliche Webanfrage behandelt. */
	private const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'TRACE'];

	public function __construct(
		public readonly string $ip,
		public readonly string $date,
		public readonly string $time,
		public readonly int $timestamp,
		public readonly string $method,
		public readonly string $path,
		public readonly string $status,
		public readonly string $agent,
		public readonly string $request,
	) {
	}

	/**
	 * Zerlegt eine Logzeile; null, wenn sie nicht im Combined-Format steht.
	 */
	public static function fromLine(string $line): ?self
	{
		$pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\S+) \S+ "(?:[^"\\\\]|\\\\.)*" "((?:[^"\\\\]|\\\\.)*)"/';
		if (preg_match($pattern, $line, $m) !== 1) {
			return null;
		}
		// Das Datum muss aus der Zeile kommen, nicht aus dem Dateinamen: logrotate
		// schneidet morgens um 06:20, access.log.1 enthält also zwei Kalendertage.
		$stamp = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[2]);
		if ($stamp === false) {
			return null;
		}
		$request = $m[3];
		$parts = explode(' ', $request);
		return new self(
			$m[1],
			$stamp->format('Y-m-d'),
			$stamp->format('H:i:s'),
			$stamp->getTimestamp(),
			$parts[0] ?? '',
			$parts[1] ?? '',
			$m[4],
			$m[5],
			$request,
		);
	}

	/**
	 * Stunde des Tages, zweistellig – Schlüssel im Tagesverlauf.
	 */
	public function hour(): string
	{
		return substr($this->time, 0, 2);
	}

	/**
	 * War das überhaupt kein Webzugriff, sondern die Suche nach einem anderen Dienst?
	 *
	 * Zwei Fälle: kein gültiges HTTP-Verb (SSH-Banner, Binärprotokoll, leere Zeile) oder
	 * CONNECT – syntaktisch HTTP, sucht aber einen offenen Proxy. Eine verstümmelte
	 * GET-Anfrage (Status 400 mit gültigem Verb) zählt nicht dazu; sie war HTTP, nur
	 * fehlerhaft.
	 */
	public function isProbe(): bool
	{
		return self::probeKindOf($this->request) !== null;
	}

	/**
	 * Benennt, wonach gesucht wurde – die Aussage der Kachel „kein Webzugriff".
	 */
	public function probeKind(): ?string
	{
		return self::probeKindOf($this->request);
	}

	/**
	 * Dasselbe allein aus der Anfragezeile – die Detailansicht eines Zeitraums kennt
	 * nur noch sie, nicht mehr jedes Ereignis. null bei gewöhnlichem HTTP.
	 */
	public static function probeKindOf(string $request): ?string
	{
		$method = explode(' ', $request)[0];
		if ($method !== 'CONNECT' && in_array($method, self::HTTP_METHODS, true)) {
			return null;
		}
		return match (true) {
			str_starts_with($request, 'SSH-') => 'SSH-Banner',
			str_starts_with($request, 'MGLNDD_') => 'Portscanner-Kennung',
			$method === 'CONNECT' => 'Proxy gesucht',
			str_starts_with($request, '\\x') => 'Binärprotokoll',
			$request === '-' || trim($request) === '' => 'leere Anfrage',
			default => 'unbekanntes Protokoll',
		};
	}

	/**
	 * Gehört die Zeile in die Ereignisliste der Detailansicht?
	 *
	 * Alles Auffällige: erfolglose Antworten, Dienstsondierungen, das Fallensignal
	 * robots.txt und der dort ausgeschlossene Pfad. Ein gewöhnlicher Treffer nicht –
	 * sonst ersäuft das Auffällige im Alltäglichen.
	 */
	public function isNoteworthy(): bool
	{
		return $this->isProbe()
			|| !in_array($this->status, ['200', '204', '301', '302', '304'], true)
			|| $this->path === '/robots.txt'
			|| str_starts_with($this->path, '/admin');
	}
}
