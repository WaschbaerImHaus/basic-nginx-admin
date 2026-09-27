<?php
declare(strict_types=1);

/**
 * Fasst Scanner-Sitzungen zu Familien zusammen: Zwei Sitzungen gehören zusammen, wenn
 * ihre Pfadlisten sich zu mindestens 60 % decken (Jaccard: gemeinsame Pfade durch alle
 * Pfade beider). Zusammenhang ist transitiv – A~B und B~C ergibt eine Familie.
 *
 * Die Kennung zählt bewusst nicht: Werkzeuge wechseln sie gern, ihre Wortliste selten.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Honeypot;

final class ScanFamilies
{
	public const SIMILARITY = 0.6;

	/**
	 * @param list<ScanSession> $sessions
	 * @return list<ScanFamily> wiederkehrende zuerst, dann nach Tagen und Anfragen
	 */
	public static function group(array $sessions): array
	{
		$count = count($sessions);
		$parent = range(0, max(0, $count - 1));
		$find = static function (int $i) use (&$parent): int {
			while ($parent[$i] !== $i) {
				$parent[$i] = $parent[$parent[$i]];
				$i = $parent[$i];
			}
			return $i;
		};
		$sets = array_map(static fn(ScanSession $s): array => array_fill_keys($s->paths, true), $sessions);
		for ($a = 0; $a < $count; $a++) {
			for ($b = $a + 1; $b < $count; $b++) {
				if (self::similarity($sets[$a], $sets[$b]) >= self::SIMILARITY) {
					$parent[$find($a)] = $find($b);
				}
			}
		}
		$groups = [];
		foreach ($sessions as $i => $session) {
			$groups[$find($i)][] = $session;
		}
		$families = array_map(static fn(array $list): ScanFamily => new ScanFamily($list), array_values($groups));
		usort($families, static fn(ScanFamily $x, ScanFamily $y): int =>
			[$y->recurring(), count($y->dates()), $y->requests()] <=> [$x->recurring(), count($x->dates()), $x->requests()]);
		return $families;
	}

	/**
	 * @param array<string, true> $a
	 * @param array<string, true> $b
	 */
	private static function similarity(array $a, array $b): float
	{
		$common = count(array_intersect_key($a, $b));
		$all = count($a) + count($b) - $common;
		return $all === 0 ? 0.0 : $common / $all;
	}
}
