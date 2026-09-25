<?php
declare(strict_types=1);

/**
 * Tests des Monatskalenders der Honigtopf-Ansicht.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 22:55
 */

namespace Tests\Honeypot;

use Honeypot\MonthCalendar;
use Honeypot\Period;
use PHPUnit\Framework\TestCase;

final class MonthCalendarTest extends TestCase
{
	public function testTheMonthFollowsTheSelectionUnlessGiven(): void
	{
		$period = Period::between('2026-08-28', '2026-09-03');
		self::assertSame('2026-09', MonthCalendar::monthFor('', $period));
		self::assertSame('2026-07', MonthCalendar::monthFor('2026-07', $period));
		// Unsinn aus der Adresszeile fällt auf den Zeitraum zurück.
		foreach (['2026-13', '26-07', '2026-7', "2026-07\n", 'x'] as $bad) {
			self::assertSame('2026-09', MonthCalendar::monthFor($bad, $period), $bad);
		}
	}

	public function testTheRangeOfAMonth(): void
	{
		$range = MonthCalendar::range('2028-02');
		self::assertSame(['2028-02-01', '2028-02-29'], [$range->from, $range->to]);
	}

	/**
	 * Wochen beginnen am Montag, Lücken vor dem Ersten und nach dem Letzten sind leer.
	 * Der 1. September 2026 ist ein Dienstag.
	 */
	public function testWeeksStartOnMonday(): void
	{
		$calendar = new MonthCalendar('2026-09', [], Period::day('2026-09-25'));
		$weeks = $calendar->weeks();
		self::assertCount(5, $weeks);
		self::assertSame(36, $weeks[0]['week']);
		self::assertNull($weeks[0]['days'][0]);
		self::assertSame('2026-09-01', $weeks[0]['days'][1]['date']);
		self::assertSame(1, $weeks[0]['days'][1]['day']);
		self::assertSame('2026-09-30', $weeks[4]['days'][2]['date']);
		self::assertNull($weeks[4]['days'][6]);
		foreach ($weeks as $week) {
			self::assertCount(7, $week['days']);
		}
	}

	/** Eine Kalenderwoche als Zeitraum – von Montag bis Sonntag, auch über den Monatsrand. */
	public function testAWeekIsAPeriodFromMondayToSunday(): void
	{
		$weeks = (new MonthCalendar('2026-09', [], Period::day('2026-09-25')))->weeks();
		self::assertSame(['2026-08-31', '2026-09-06'], [$weeks[0]['period']->from, $weeks[0]['period']->to]);
	}

	/**
	 * Die Färbung misst Sondierungen (404) relativ zum stärksten Tag des Monats:
	 * 0 = keine Daten, 1 = Daten ohne Sondierung, 2 bis 4 = zunehmend.
	 */
	public function testHeatLevels(): void
	{
		$totals = [
			'2026-09-02' => ['requests' => 5, 'probing' => 0, 'probes' => 0],
			'2026-09-03' => ['requests' => 50, 'probing' => 10, 'probes' => 1],
			'2026-09-04' => ['requests' => 80, 'probing' => 60, 'probes' => 0],
			'2026-09-05' => ['requests' => 90, 'probing' => 30, 'probes' => 0],
		];
		$cells = $this->cells(new MonthCalendar('2026-09', $totals, Period::day('2026-09-25')));
		self::assertSame(0, $cells['2026-09-01']['level']);
		self::assertFalse($cells['2026-09-01']['data']);
		self::assertSame(1, $cells['2026-09-02']['level']);
		self::assertTrue($cells['2026-09-02']['data']);
		self::assertSame(2, $cells['2026-09-03']['level']);
		self::assertSame(4, $cells['2026-09-04']['level']);
		self::assertSame(3, $cells['2026-09-05']['level']);
		self::assertSame(60, $cells['2026-09-04']['probing']);
		self::assertSame(80, $cells['2026-09-04']['requests']);
	}

	public function testMarksTheSelectedPeriod(): void
	{
		$cells = $this->cells(new MonthCalendar('2026-09', [], Period::between('2026-08-30', '2026-09-02')));
		self::assertTrue($cells['2026-09-01']['selected']);
		self::assertTrue($cells['2026-09-02']['selected']);
		self::assertFalse($cells['2026-09-03']['selected']);
	}

	public function testLabelAndNeighbours(): void
	{
		$calendar = new MonthCalendar('2026-01', [], Period::day('2026-01-10'));
		self::assertSame('Januar 2026', $calendar->label());
		self::assertSame('2025-12', $calendar->previousMonth());
		self::assertSame('2026-02', $calendar->nextMonth());
		self::assertSame('2026-01', $calendar->month);
	}

	/** @return array<string, array<string, mixed>> */
	private function cells(MonthCalendar $calendar): array
	{
		$cells = [];
		foreach ($calendar->weeks() as $week) {
			foreach ($week['days'] as $cell) {
				if ($cell !== null) {
					$cells[$cell['date']] = $cell;
				}
			}
		}
		return $cells;
	}
}
