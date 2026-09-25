<?php
declare(strict_types=1);

/**
 * Ein Monatsblatt für die Tageswahl: Wochen ab Montag, jeder Tag mit seinen Zahlen und
 * einer Färbung nach Sondierungen.
 *
 * Die Färbung ist relativ zum stärksten Tag des Monats. Absolute Schwellen würden bei
 * einem ruhigen Honigtopf alles blass und bei einem gut besuchten alles rot zeigen – die
 * Frage im Kalender ist aber „welcher Tag fällt auf?".
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 22:55
 */

namespace Honeypot;

final class MonthCalendar
{
	private const MONTHS = [
		1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
		'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
	];

	/**
	 * @param string $month JJJJ-MM
	 * @param array<string, array{requests: int, probing: int, probes: int}> $totals Zahlen je Tag (siehe ReportDatabase::dailyTotals)
	 * @param Period $selected der gezeigte Zeitraum, im Kalender hervorgehoben
	 */
	public function __construct(
		public readonly string $month,
		private readonly array $totals,
		private readonly Period $selected,
	) {
		if (!self::isMonth($month)) {
			throw new \InvalidArgumentException('Ungültiger Monat: ' . $month);
		}
	}

	/**
	 * Der anzuzeigende Monat: der aus der Adresszeile, wenn gültig, sonst der Monat, in
	 * dem der gewählte Zeitraum endet.
	 */
	public static function monthFor(string $param, Period $period): string
	{
		return self::isMonth($param) ? $param : substr($period->to, 0, 7);
	}

	/** Erster bis letzter Tag eines Monats. */
	public static function range(string $month): Period
	{
		$first = new \DateTimeImmutable($month . '-01');
		return Period::between($first->format('Y-m-d'), $first->format('Y-m-t'));
	}

	/** „September 2026". */
	public function label(): string
	{
		return self::MONTHS[(int)substr($this->month, 5, 2)] . ' ' . substr($this->month, 0, 4);
	}

	public function previousMonth(): string
	{
		return (new \DateTimeImmutable($this->month . '-01'))->modify('-1 month')->format('Y-m');
	}

	public function nextMonth(): string
	{
		return (new \DateTimeImmutable($this->month . '-01'))->modify('+1 month')->format('Y-m');
	}

	/**
	 * Die Wochen des Monats. Jede Woche hat ihre Kalenderwoche, den Zeitraum Montag bis
	 * Sonntag und sieben Felder; Felder ausserhalb des Monats sind null.
	 *
	 * @return list<array{week: int, period: Period, days: list<?array{date: string, day: int, requests: int, probing: int, data: bool, level: int, selected: bool}>}>
	 */
	public function weeks(): array
	{
		$first = new \DateTimeImmutable($this->month . '-01');
		$last = $first->modify('last day of this month');
		// Montag der ersten Woche; 'N' zählt Montag als 1.
		$monday = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
		$peak = 0;
		foreach ($this->totals as $totals) {
			$peak = max($peak, (int)$totals['probing']);
		}

		$weeks = [];
		for ($start = $monday; $start <= $last; $start = $start->modify('+7 days')) {
			$days = [];
			for ($offset = 0; $offset < 7; $offset++) {
				$day = $start->modify('+' . $offset . ' days');
				$days[] = $day->format('Y-m') === $this->month ? $this->cell($day->format('Y-m-d'), $peak) : null;
			}
			$weeks[] = [
				'week' => (int)$start->format('W'),
				'period' => Period::between($start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')),
				'days' => $days,
			];
		}
		return $weeks;
	}

	/**
	 * Ein Tag im Blatt.
	 *
	 * @return array{date: string, day: int, requests: int, probing: int, data: bool, level: int, selected: bool}
	 */
	private function cell(string $date, int $peak): array
	{
		$totals = $this->totals[$date] ?? null;
		$probing = (int)($totals['probing'] ?? 0);
		$level = 0;
		if ($totals !== null) {
			// 1 = Daten, aber nichts gesucht; 2 bis 4 = Anteil am stärksten Tag in Dritteln.
			$level = $probing > 0 && $peak > 0 ? 1 + min(3, (int)ceil($probing / $peak * 3)) : 1;
		}
		return [
			'date' => $date,
			'day' => (int)substr($date, 8, 2),
			'requests' => (int)($totals['requests'] ?? 0),
			'probing' => $probing,
			'data' => $totals !== null,
			'level' => $level,
			'selected' => $this->selected->contains($date),
		];
	}

	/** Streng JJJJ-MM mit gültigem Monat. */
	private static function isMonth(string $value): bool
	{
		return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) === 1;
	}
}
