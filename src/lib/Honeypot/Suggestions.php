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
 * der Vergleich der Sondierungspfade über Tage. Eine Seite, die vorschlägt, was sie
 * schon zeigt, lässt die echten Vorschläge im Rauschen untergehen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 11:20
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
		if (($report->loot['Zugangsdaten'] ?? 0) >= 5) {
			$out[] = '**Köderdateien mit Kennung.** Es wurde ' . $report->loot['Zugangsdaten']
				. '-mal nach Zugangsdaten gesucht. Eine `/.env` mit einer je Abruf eindeutigen, sonst '
				. 'nirgends gültigen Zeichenkette würde zeigen, ob und wo der Fund später benutzt wird. '
				. 'Wichtig: nur erfundene Werte, nichts, was irgendwo gilt.';
		}
		if ($report->robots > 0 && $report->robotsThenAdmin > 0) {
			$out[] = '**Zeitabstand robots.txt → /admin/ auswerten.** ' . $report->robotsThenAdmin
				. '-mal wurde nach dem Lesen der robots.txt der dort ausgeschlossene Pfad besucht, '
				. 'kürzester Abstand ' . (int)$report->shortestGap() . ' s. Der Abstand trennt '
				. '„liest und wertet aus" von „ruft beides blind ab".';
		}
		if ($report->logins !== []) {
			$out[] = '**Versuchte Benutzernamen sammeln.** ' . count($report->logins)
				. ' verschiedene Namen wurden probiert. Eine Liste über Wochen zeigt, ob generisch '
				. 'geraten wird (admin, root) oder gezielt (Domainname, echte Namen).';
		}
		if ($out === []) {
			$out[] = 'Keine. Die Zahlen des Tages tragen keinen der vorgesehenen Vorschläge.';
		}
		return $out;
	}
}
