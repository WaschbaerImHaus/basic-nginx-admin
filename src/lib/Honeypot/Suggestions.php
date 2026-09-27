<?php
declare(strict_types=1);

/**
 * Vorschläge, welche Werkzeuge in der Ansicht als Nächstes lohnen.
 *
 * Jede Regel hängt an einer Schwelle: Ein Vorschlag erscheint, wenn die Zahlen des Tages
 * ihn tragen – nicht, weil er grundsätzlich denkbar wäre.
 *
 * Umgesetzte Vorschläge verschwinden von hier. Am 2026-09-25 waren das: eigene Kachel
 * für Anfragen ohne Webzugriff, Gruppierung der Kennungen nach gleicher Häufigkeit und
 * der Vergleich der Sondierungspfade über Tage. Am 2026-09-27: Köder mit Kennung
 * (phpinfo, .env), die Verteilung der Abstände robots.txt → /admin und die
 * Benutzernamen über Wochen. Eine Seite, die vorschlägt, was sie schon zeigt, lässt die
 * echten Vorschläge im Rauschen untergehen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 13:30
 */

namespace Honeypot;

final class Suggestions
{
	/**
	 * @return list<string> Vorschläge in Markdown
	 */
	public function forReport(DayReport $report): array
	{
		$out = [];
		// Gesuchte Zugangsdaten, für die es noch keinen Köder gibt (/.git/config,
		// /.aws/credentials …). Dieselbe Technik – erfundener Inhalt mit Kennung je
		// Abruf – liesse sich auf sie ausdehnen.
		$uncovered = [];
		$classifier = new LootClassifier();
		foreach ($report->notFound as $path => $count) {
			if ($classifier->classify((string)$path) === 'Zugangsdaten' && Decoys::kindOf((string)$path) === null) {
				$uncovered[(string)$path] = (int)$count;
			}
		}
		if (array_sum($uncovered) >= 5) {
			arsort($uncovered);
			$top = array_map(
				static fn(string $path, int $count): string => '`' . $path . '` (' . $count . ')',
				array_keys(array_slice($uncovered, 0, 3, true)),
				array_slice($uncovered, 0, 3, true)
			);
			$out[] = '**Weitere Köder.** ' . array_sum($uncovered) . '-mal wurde nach Zugangsdaten gesucht, die noch '
				. 'kein Köder abdeckt, vor allem ' . implode(', ', $top) . '. Ein erfundener Inhalt mit Kennung je '
				. 'Abruf (wie bei phpinfo und .env) zeigte, ob der Fund später benutzt wird.';
		}
		if ($out === []) {
			$out[] = 'Keine. Die Zahlen des Tages tragen keinen der vorgesehenen Vorschläge.';
		}
		return $out;
	}
}
