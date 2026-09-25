<?php
declare(strict_types=1);

/**
 * Tests des Auswertungszeitraums.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

namespace Tests\Honeypot;

use Honeypot\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
	public function testASingleDay(): void
	{
		$period = Period::day('2026-09-25');
		self::assertSame('2026-09-25', $period->from);
		self::assertSame('2026-09-25', $period->to);
		self::assertTrue($period->isSingleDay());
		self::assertSame(1, $period->length());
		self::assertSame('25.09.2026', $period->label());
	}

	public function testARange(): void
	{
		$period = Period::between('2026-09-01', '2026-09-25');
		self::assertFalse($period->isSingleDay());
		self::assertSame(25, $period->length());
		self::assertSame('01.09. – 25.09.2026', $period->label());
		self::assertSame('30.12.2025 – 02.01.2026', Period::between('2025-12-30', '2026-01-02')->label());
	}

	/**
	 * Wer „von" und „bis" vertauscht, meint trotzdem denselben Zeitraum.
	 */
	public function testSwapsReversedBounds(): void
	{
		$period = Period::between('2026-09-25', '2026-09-01');
		self::assertSame('2026-09-01', $period->from);
		self::assertSame('2026-09-25', $period->to);
	}

	/**
	 * Verglichen wird mit einem gleich langen Zeitraum direkt davor – nicht mit
	 * „gestern", sonst stünde eine Woche gegen einen Tag.
	 */
	public function testThePreviousPeriodHasTheSameLength(): void
	{
		self::assertEquals(Period::day('2026-09-24'), Period::day('2026-09-25')->previous());
		self::assertEquals(Period::between('2026-08-25', '2026-08-31'), Period::between('2026-09-01', '2026-09-07')->previous());
	}

	public function testListsItsDays(): void
	{
		self::assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], Period::between('2026-09-29', '2026-10-01')->days());
	}

	public function testKnowsWhetherADayBelongsToIt(): void
	{
		$period = Period::between('2026-09-01', '2026-09-07');
		self::assertTrue($period->contains('2026-09-01'));
		self::assertTrue($period->contains('2026-09-07'));
		self::assertFalse($period->contains('2026-09-08'));
	}

	public function testTheDaysBeforeIt(): void
	{
		self::assertEquals(Period::between('2026-09-11', '2026-09-24'), Period::day('2026-09-25')->before(14));
	}

	/**
	 * Die Werte kommen aus der Adresszeile. Nur echte Kalenderdaten im Format
	 * JJJJ-MM-TT – ein „2026-02-30" darf nicht still zum 2. März werden.
	 */
	public function testRejectsAnythingThatIsNotARealDate(): void
	{
		foreach (['', 'gestern', '2026-9-1', '2026-02-30', '2026-13-01', "2026-09-01\0", '2026-09-01 OR 1=1', '../2026-09-01'] as $bad) {
			try {
				Period::day($bad);
				self::fail('angenommen: ' . $bad);
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	/**
	 * Ein Zeitraum über Jahrzehnte wäre eine teure Anfrage an die Datenbank, ohne dass
	 * dort Daten liegen könnten.
	 */
	public function testRejectsAnAbsurdlyLongRange(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Period::between('2000-01-01', '2026-09-25');
	}

	public function testPresetsRelativeToAGivenDay(): void
	{
		self::assertEquals(Period::day('2026-09-25'), Period::preset('heute', '2026-09-25'));
		self::assertEquals(Period::day('2026-09-24'), Period::preset('gestern', '2026-09-25'));
		self::assertEquals(Period::between('2026-09-19', '2026-09-25'), Period::preset('7', '2026-09-25'));
		self::assertEquals(Period::between('2026-08-27', '2026-09-25'), Period::preset('30', '2026-09-25'));
		self::assertEquals(Period::between('2026-09-01', '2026-09-25'), Period::preset('monat', '2026-09-25'));
		self::assertNull(Period::preset('unsinn', '2026-09-25'));
	}
}
