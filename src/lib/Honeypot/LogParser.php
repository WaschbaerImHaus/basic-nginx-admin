<?php
declare(strict_types=1);

/**
 * Zerlegt Logzeilen und gruppiert sie nach Kalendertag.
 *
 * Nach Kalendertag, nicht nach Datei: logrotate schneidet morgens um 06:20, access.log.1
 * enthält also das Ende des Vortags und den Morgen des laufenden Tages. Wer die Datei
 * als „Vortag" zählt, schreibt dem falschen Tag bis zu sechs Stunden Verkehr zu.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:10
 */

namespace Honeypot;

final class LogParser
{
	/**
	 * @param list<string> $lines Zeilen einer oder mehrerer Logdateien
	 */
	public function parse(array $lines): ParsedLog
	{
		$days = [];
		$unreadable = 0;
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$entry = LogEntry::fromLine($line);
			if ($entry === null) {
				$unreadable++;
				continue;
			}
			$days[$entry->date][] = $entry;
		}
		ksort($days);
		// Innerhalb eines Tages nach Zeit sortieren. Gelesen wird access.log vor
		// access.log.1, also neuere Datei vor älterer – ohne diesen Schritt stünden die
		// Einträge eines Tages verkehrt herum, und jede Messung „was kam danach"
		// (robots.txt → /admin) ergäbe einen negativen Abstand.
		foreach ($days as $date => $entries) {
			usort($entries, static fn(LogEntry $a, LogEntry $b): int => $a->timestamp <=> $b->timestamp);
			$days[$date] = $entries;
		}
		return new ParsedLog($days, $unreadable);
	}

	/**
	 * Liest eine Logdatei, auch in der rotierten bzw. komprimierten Fassung.
	 *
	 * @return list<string>
	 */
	public function readFile(string $path): array
	{
		foreach ([$path, $path . '.gz'] as $candidate) {
			if (!is_file($candidate) || !is_readable($candidate)) {
				continue;
			}
			$text = str_ends_with($candidate, '.gz')
				? (string)gzdecode((string)file_get_contents($candidate))
				: (string)file_get_contents($candidate);
			return array_values(array_filter(
				explode("\n", $text),
				static fn(string $line): bool => trim($line) !== ''
			));
		}
		return [];
	}
}
