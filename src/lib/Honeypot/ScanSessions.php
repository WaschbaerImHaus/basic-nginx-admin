<?php
declare(strict_types=1);

/**
 * Zerlegt die Anfragen eines Tages in Scanner-Sitzungen (siehe ScanSession).
 *
 * Kennungen, die ein Werkzeug durchwechselt (gleich viele Anfragen je Kennung, siehe
 * DayReport::detectRotation), zählen als eine – sonst zerfiele genau das Werkzeug, das
 * sich am meisten Mühe gibt, in lauter Einzelanfragen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Honeypot;

final class ScanSessions
{
	/** Längste Pause innerhalb einer Sitzung, in Sekunden. */
	public const GAP = 1800;
	/** Mindestens so viele Anfragen … */
	public const MIN_REQUESTS = 3;
	/** … und so viele verschiedene Pfade, sonst gibt es keinen Fingerabdruck. */
	public const MIN_PATHS = 2;

	/**
	 * @param list<LogEntry> $entries Einträge eines Tages, nach Zeit sortiert
	 * @return list<ScanSession> nach Beginn sortiert
	 */
	public static function fromEntries(array $entries): array
	{
		// Durchgewechselte Kennungen auf einen gemeinsamen Schlüssel abbilden.
		$counts = [];
		foreach ($entries as $entry) {
			$counts[$entry->agent] = ($counts[$entry->agent] ?? 0) + 1;
		}
		$keyOf = [];
		foreach (DayReport::detectRotation($counts) as $count => $agents) {
			foreach ($agents as $agent) {
				$keyOf[$agent] = "\0rotation:" . $count;
			}
		}

		/** @var array<string, list<list<LogEntry>>> $runs */
		$runs = [];
		$last = [];
		foreach ($entries as $entry) {
			$key = $keyOf[$entry->agent] ?? $entry->agent;
			if (!isset($last[$key]) || $entry->timestamp - $last[$key] > self::GAP) {
				$runs[$key][] = [];
			}
			$runs[$key][array_key_last($runs[$key])][] = $entry;
			$last[$key] = $entry->timestamp;
		}

		$sessions = [];
		foreach ($runs as $list) {
			foreach ($list as $run) {
				$paths = array_values(array_unique(array_map(
					static fn(LogEntry $e): string => explode('?', $e->path, 2)[0],
					$run
				)));
				if (count($run) < self::MIN_REQUESTS || count($paths) < self::MIN_PATHS) {
					continue;
				}
				sort($paths);
				$agents = array_values(array_unique(array_map(static fn(LogEntry $e): string => $e->agent, $run)));
				sort($agents);
				$sessions[] = new ScanSession($run[0]->date, $run[0]->time, $run[count($run) - 1]->time, count($run), $agents, $paths);
			}
		}
		usort($sessions, static fn(ScanSession $a, ScanSession $b): int => [$a->date, $a->start] <=> [$b->date, $b->start]);
		return $sessions;
	}
}
