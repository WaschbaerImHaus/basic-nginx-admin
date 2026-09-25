<?php
declare(strict_types=1);

/**
 * Tests der Zeitraumwahl aus der Adresszeile.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 22:55
 */

namespace Tests\Honeypot;

use Honeypot\PeriodSelection;
use PHPUnit\Framework\TestCase;

final class PeriodSelectionTest extends TestCase
{
	private const BOUNDS = ['2026-09-19', '2026-09-24'];
	private const TODAY = '2026-09-25';

	/** Ohne Angabe: der jüngste Tag mit Daten – nicht „heute", der ist oft noch leer. */
	public function testDefaultsToTheLastDayWithData(): void
	{
		$selection = PeriodSelection::fromQuery([], self::BOUNDS, self::TODAY);
		self::assertSame(['2026-09-24', '2026-09-24'], [$selection->period->from, $selection->period->to]);
		self::assertSame(['tag' => '2026-09-24'], $selection->query());
		self::assertSame('', $selection->preset);
	}

	/** Ganz ohne Daten bleibt nur heute. */
	public function testDefaultsToTodayWithoutData(): void
	{
		$selection = PeriodSelection::fromQuery([], null, self::TODAY);
		self::assertSame(self::TODAY, $selection->period->from);
	}

	public function testASingleDay(): void
	{
		$selection = PeriodSelection::fromQuery(['tag' => '2026-09-20'], self::BOUNDS, self::TODAY);
		self::assertTrue($selection->period->isSingleDay());
		self::assertSame('2026-09-20', $selection->period->from);
		self::assertSame(['tag' => '2026-09-20'], $selection->query());
	}

	/** Die Adresszeile ist Fremdeingabe: Unsinn führt zur Voreinstellung, nie zu einem Fehler. */
	public function testIgnoresInvalidValues(): void
	{
		foreach ([['tag' => '2026-02-30'], ['tag' => ['x']], ['tag' => "2026-09-20\n"], ['von' => 'gestern', 'bis' => '2026-09-20'],
			['zeitraum' => 'ewig'], ['von' => '1990-01-01', 'bis' => '2026-09-20']] as $query) {
			$selection = PeriodSelection::fromQuery($query, self::BOUNDS, self::TODAY);
			self::assertSame('2026-09-24', $selection->period->from, json_encode($query));
			self::assertTrue($selection->period->isSingleDay());
		}
	}

	public function testARangeFromTo(): void
	{
		$selection = PeriodSelection::fromQuery(['von' => '2026-09-19', 'bis' => '2026-09-22'], self::BOUNDS, self::TODAY);
		self::assertSame(['2026-09-19', '2026-09-22'], [$selection->period->from, $selection->period->to]);
		self::assertSame(['von' => '2026-09-19', 'bis' => '2026-09-22'], $selection->query());
	}

	/** Vertauscht eingetragen ist kein Fehler, sondern gemeint andersherum. */
	public function testSwapsReversedBounds(): void
	{
		$selection = PeriodSelection::fromQuery(['von' => '2026-09-22', 'bis' => '2026-09-19'], self::BOUNDS, self::TODAY);
		self::assertSame(['2026-09-19', '2026-09-22'], [$selection->period->from, $selection->period->to]);
	}

	/** Ein gleicher Anfang und Ende ist ein einzelner Tag – mit derselben Adresse wie „tag". */
	public function testARangeOfOneDayBecomesADay(): void
	{
		$selection = PeriodSelection::fromQuery(['von' => '2026-09-20', 'bis' => '2026-09-20'], self::BOUNDS, self::TODAY);
		self::assertSame(['tag' => '2026-09-20'], $selection->query());
	}

	/** Nur eine Grenze ausgefüllt: dieser eine Tag. */
	public function testOnlyOneBoundMeansThatDay(): void
	{
		self::assertSame('2026-09-21', PeriodSelection::fromQuery(['von' => '2026-09-21'], self::BOUNDS, self::TODAY)->period->to);
		self::assertSame('2026-09-22', PeriodSelection::fromQuery(['von' => '', 'bis' => '2026-09-22'], self::BOUNDS, self::TODAY)->period->from);
	}

	/** Schnellwahl bleibt relativ: „7" heisst morgen die sieben Tage bis morgen. */
	public function testAPresetStaysRelative(): void
	{
		$selection = PeriodSelection::fromQuery(['zeitraum' => '7'], self::BOUNDS, self::TODAY);
		self::assertSame(['2026-09-19', '2026-09-25'], [$selection->period->from, $selection->period->to]);
		self::assertSame('7', $selection->preset);
		self::assertSame(['zeitraum' => '7'], $selection->query());
	}

	/** Das Genauere gewinnt: von/bis vor tag vor zeitraum. */
	public function testPrecedence(): void
	{
		$all = ['von' => '2026-09-19', 'bis' => '2026-09-20', 'tag' => '2026-09-22', 'zeitraum' => '30'];
		self::assertSame('2026-09-19', PeriodSelection::fromQuery($all, self::BOUNDS, self::TODAY)->period->from);
		unset($all['von'], $all['bis']);
		self::assertSame('2026-09-22', PeriodSelection::fromQuery($all, self::BOUNDS, self::TODAY)->period->from);
	}

	public function testPresetsAreListedWithLabels(): void
	{
		// PHP macht aus den Schlüsseln '7' und '30' Zahlen – verglichen wird als Text.
		self::assertSame(['heute', 'gestern', '7', '30', 'monat'], array_map('strval', array_keys(PeriodSelection::PRESETS)));
	}
}
