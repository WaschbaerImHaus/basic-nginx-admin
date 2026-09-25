<?php
declare(strict_types=1);

/**
 * Vergleicht die Sondierungspfade (404) über mehrere Tage.
 *
 * Die Frage dahinter ist die nützlichste Frühwarnung, die ein Honigtopf liefern kann:
 * Was wird heute gesucht, das in den Tagen davor niemand gesucht hat? Ein neuer Pfad,
 * der auf einmal auftaucht, ist meist eine frisch veröffentlichte Lücke, die gerade
 * reihum ausprobiert wird – und damit ein Hinweis, die eigenen Anwendungen darauf
 * durchzusehen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 11:00
 */

namespace Honeypot;

final class PathTrend
{
	/** @var list<DayReport> neuester Tag zuerst */
	private readonly array $days;

	/**
	 * @param list<DayReport> $days in beliebiger Reihenfolge; der neueste gilt als „heute"
	 */
	public function __construct(array $days)
	{
		// Selbst sortieren, nicht auf die Übergabe vertrauen: Wer den falschen Tag für
		// heute hält, meldet die Pfade von gestern als neu.
		usort($days, static fn(DayReport $a, DayReport $b): int => strcmp($b->date, $a->date));
		$this->days = $days;
	}

	/**
	 * Gibt es überhaupt Vortage? Ohne sie wäre jeder Pfad „neu".
	 */
	public function hasHistory(): bool
	{
		return count($this->days) >= 2;
	}

	/**
	 * Pfade, die am neuesten Tag gesucht wurden und an keinem der Vortage.
	 *
	 * @return array<string, int> Pfad => Anfragen heute, häufigste zuerst
	 */
	public function newToday(): array
	{
		if (!$this->hasHistory()) {
			return [];
		}
		$earlier = [];
		foreach (array_slice($this->days, 1) as $day) {
			foreach (array_keys($day->notFound) as $path) {
				$earlier[(string)$path] = true;
			}
		}
		$new = [];
		foreach ($this->days[0]->notFound as $path => $count) {
			if (!isset($earlier[(string)$path])) {
				$new[(string)$path] = (int)$count;
			}
		}
		arsort($new);
		return $new;
	}

	/**
	 * Matrix Pfad × Tag, häufigste Pfade (über alle Tage) zuerst. Jeder Tag hat eine
	 * Spalte, auch wenn der Pfad an dem Tag fehlte – sonst liesse sich nicht ablesen,
	 * wann ein Pfad auftauchte oder verschwand.
	 *
	 * @return array<string, array<string, int>> Pfad => Datum => Anfragen
	 */
	public function matrix(int $limit = 80): array
	{
		$totals = [];
		foreach ($this->days as $day) {
			foreach ($day->notFound as $path => $count) {
				$totals[(string)$path] = ($totals[(string)$path] ?? 0) + (int)$count;
			}
		}
		// Bei gleicher Summe alphabetisch, damit die Reihenfolge nicht von der
		// Reihenfolge im Log abhängt.
		uksort($totals, static fn(string $a, string $b): int => $totals[$b] <=> $totals[$a] ?: strcmp($a, $b));

		$matrix = [];
		foreach (array_slice(array_keys($totals), 0, $limit) as $path) {
			foreach ($this->days as $day) {
				$matrix[$path][$day->date] = (int)($day->notFound[$path] ?? 0);
			}
		}
		return $matrix;
	}

	/**
	 * Der früheste Tag im betrachteten Zeitraum, an dem der Pfad gesucht wurde.
	 */
	public function firstSeen(string $path): ?string
	{
		$first = null;
		foreach ($this->days as $day) {
			if (isset($day->notFound[$path])) {
				$first = $day->date;
			}
		}
		return $first;
	}

	/**
	 * Die betrachteten Tage, neuester zuerst.
	 *
	 * @return list<string>
	 */
	public function dates(): array
	{
		return array_map(static fn(DayReport $day): string => $day->date, $this->days);
	}
}
