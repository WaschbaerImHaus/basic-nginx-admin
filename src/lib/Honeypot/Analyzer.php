<?php
declare(strict_types=1);

/**
 * Entscheidet, welche Tage ausgewertet werden, wertet sie aus und legt sie ab.
 *
 * Nutzerwunsch vom 2026-09-25: Was einmal fertig ausgewertet ist, wird nicht erneut
 * ausgewertet. Die Regeln je Tag:
 *
 *   übersprungen  abgeschlossen und mit der aktuellen Fassung ausgewertet
 *   behalten      die Logs enthalten WENIGER Anfragen als gespeichert – ein Teil des
 *                 Tages ist inzwischen gelöscht (Aufbewahrungsfrist). Nie verkleinern.
 *   ausgewertet   alles andere: der laufende Tag, gestern (ab heute abgeschlossen),
 *                 Tage aus einer älteren Fassung, noch unbekannte Tage
 *
 * Nachgeschlagen (DNS, Netztabelle) wird nur für Tage, die wirklich ausgewertet werden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 11:30
 */

namespace Honeypot;

final class Analyzer
{
	/**
	 * Fassung der Auswertung. Erhöhen, wenn sich ändert, was ein Tagesbericht enthält:
	 * Tage einer älteren Fassung werden dann neu ausgewertet, solange ihre Logs da sind.
	 * 1 = übernommene JSON-Berichte, 2 = seit der SQLite-Ablage, 3 = mit Köderabrufen,
	 * 4 = mit Scanner-Sitzungen (Wiedererkennung über Tage).
	 */
	public const VERSION = 4;

	public function __construct(
		private readonly ReportDatabase $db,
		private readonly PeerResolver $resolver,
		private readonly LogParser $parser = new LogParser(),
		private readonly LoginAttempts $attempts = new LoginAttempts(),
	) {
	}

	/**
	 * @param list<string> $accessLines    Zeilen aller vorhandenen access.log-Fassungen
	 * @param list<string> $errorLines     Zeilen aller vorhandenen error.log-Fassungen
	 * @param list<string> $ownNames       eigene Namen und Adressen (keine Gegenstelle)
	 * @param bool         $oldestMayBeCut die älteste Logdatei liegt an der Aufbewahrungs-
	 *                                     grenze; vom ältesten Tag fehlt vermutlich der Anfang
	 * @param list<string> $decoyLines     Zeilen aller vorhandenen decoy.log-Fassungen (ausgegebene Köderkennungen)
	 * @return array{analysed: list<string>, skipped: list<string>, kept: list<string>}
	 */
	public function run(
		string $host,
		array $accessLines,
		array $errorLines,
		array $ownNames,
		string $today,
		bool $oldestMayBeCut,
		array $decoyLines = []
	): array {
		$parsed = $this->parser->parse($accessLines);
		$logins = $this->attempts->byDate($errorLines);
		$finder = new PeerFinder($ownNames);
		$oldest = array_key_first($parsed->days);
		$result = ['analysed' => [], 'skipped' => [], 'kept' => []];

		foreach ($parsed->days as $date => $entries) {
			$date = (string)$date;
			$stored = $this->db->info($host, $date);
			if ($stored !== null && $stored['complete'] && $stored['version'] >= self::VERSION) {
				$result['skipped'][] = $date;
				continue;
			}
			// Mehr gespeichert, als die Logs noch hergeben: Ein Teil ist schon rotiert.
			// Übersprungene Selbsttests zählen dabei mit – sie fehlen nicht, sie zählen
			// nur nicht mehr (ein alter Stand enthielt sie noch).
			if ($stored !== null && $stored['requests'] > count($entries) + ($parsed->local[$date] ?? 0)) {
				$result['kept'][] = $date;
				continue;
			}
			$complete = $date < $today && !($oldestMayBeCut && $date === $oldest);
			$report = DayReport::fromEntries(
				$date,
				$entries,
				// Unlesbare Zeilen tragen kein Datum; sie stehen beim laufenden Tag, wo
				// eine Formatänderung im Log am schnellsten auffällt.
				$date === $today ? $parsed->unreadable : 0,
				$logins[$date] ?? [],
				$complete
			)->withPeers($this->resolver->resolve($finder->find($entries)));
			$this->db->save($host, $report, self::VERSION);
			$this->db->saveSessions($host, $date, ScanSessions::fromEntries($entries));
			$result['analysed'][] = $date;
		}

		// Köderkennungen über alle gelesenen Zeilen, unabhängig davon, welche Tage oben
		// übersprungen wurden: Eine Kennung von vorgestern kann heute benutzt werden.
		// Doppeltes übergeht die Datenbank.
		$this->db->saveTokens($host, DecoyTracker::issued($decoyLines));
		$all = $parsed->days === [] ? [] : array_merge(...array_values($parsed->days));
		$this->db->saveUses($host, DecoyTracker::uses($all, $errorLines, $this->db->knownTokens($host)));
		return $result;
	}
}
