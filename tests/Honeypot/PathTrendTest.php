<?php
declare(strict_types=1);

/**
 * Tests des Vergleichs der Sondierungspfade über mehrere Tage.
 *
 * Die Frage dahinter: Was wird HEUTE gesucht, das in den Tagen davor niemand gesucht
 * hat? Ein neuer Pfad, der auf einmal von vielen Seiten kommt, ist meist eine frisch
 * veröffentlichte Lücke, die gerade reihum ausprobiert wird.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 11:00
 */

namespace Tests\Honeypot;

use Honeypot\DayReport;
use Honeypot\LogEntry;
use Honeypot\PathTrend;
use PHPUnit\Framework\TestCase;

final class PathTrendTest extends TestCase
{
	/** Ein Tagesbericht mit den angegebenen 404-Pfaden (Pfad => Anzahl). */
	private function day(string $date, array $paths): DayReport
	{
		$entries = [];
		[$y, $m, $d] = explode('-', $date);
		$month = date('M', (int)mktime(0, 0, 0, (int)$m, 1));
		foreach ($paths as $path => $count) {
			for ($i = 0; $i < $count; $i++) {
				$entries[] = LogEntry::fromLine(sprintf(
					'10.200.0.1 - - [%s/%s/%s:10:00:%02d +0200] "GET %s HTTP/1.1" 404 5 "-" "x"',
					$d, $month, $y, $i % 60, $path
				));
			}
		}
		return DayReport::fromEntries($date, $entries, 0, [], true);
	}

	public function testFindsPathsThatNobodySearchedForOnTheDaysBefore(): void
	{
		$trend = new PathTrend([
			$this->day('2026-09-25', ['/.env' => 3, '/neue-luecke.php' => 5]),
			$this->day('2026-09-24', ['/.env' => 2]),
			$this->day('2026-09-23', ['/.env' => 1, '/alt' => 1]),
		]);
		self::assertSame(['/neue-luecke.php' => 5], $trend->newToday());
	}

	/**
	 * Ohne Vortage gibt es nichts zu vergleichen. Dann wäre jeder Pfad „neu" – eine
	 * Aussage, die wie eine Warnung aussieht und keine ist.
	 */
	public function testWithoutEarlierDaysNothingCountsAsNew(): void
	{
		$trend = new PathTrend([$this->day('2026-09-25', ['/.env' => 3])]);
		self::assertFalse($trend->hasHistory());
		self::assertSame([], $trend->newToday());
	}

	public function testNewPathsAreSortedByHowOftenTheyCameToday(): void
	{
		$trend = new PathTrend([
			$this->day('2026-09-25', ['/selten' => 1, '/oft' => 7, '/mittel' => 3]),
			$this->day('2026-09-24', ['/.env' => 2]),
		]);
		self::assertSame(['/oft', '/mittel', '/selten'], array_keys($trend->newToday()));
	}

	/**
	 * Die Detailansicht zeigt eine Matrix Pfad × Tag. Häufigste Pfade oben, und jeder
	 * Tag hat eine Spalte, auch wenn der Pfad an dem Tag fehlte.
	 */
	public function testBuildsAPathByDayMatrixWithAColumnForEveryDay(): void
	{
		$trend = new PathTrend([
			$this->day('2026-09-25', ['/a' => 1, '/b' => 4]),
			$this->day('2026-09-24', ['/a' => 5]),
		]);
		$matrix = $trend->matrix();

		self::assertSame(['/a', '/b'], array_keys($matrix), '/a hat insgesamt 6, /b 4');
		self::assertSame(['2026-09-25' => 1, '2026-09-24' => 5], $matrix['/a']);
		self::assertSame(['2026-09-25' => 4, '2026-09-24' => 0], $matrix['/b']);
		self::assertSame(['2026-09-25', '2026-09-24'], $trend->dates());
	}

	public function testTheMatrixCanBeLimited(): void
	{
		$trend = new PathTrend([$this->day('2026-09-25', ['/a' => 3, '/b' => 2, '/c' => 1])]);
		self::assertSame(['/a', '/b'], array_keys($trend->matrix(2)));
	}

	public function testKnowsWhenAPathWasFirstSeen(): void
	{
		$trend = new PathTrend([
			$this->day('2026-09-25', ['/x' => 1]),
			$this->day('2026-09-24', ['/x' => 1]),
			$this->day('2026-09-22', ['/x' => 1]),
			$this->day('2026-09-21', ['/y' => 1]),
		]);
		self::assertSame('2026-09-22', $trend->firstSeen('/x'));
		self::assertNull($trend->firstSeen('/nie'));
	}

	/**
	 * Die Tage kommen aus der Ablage und werden nicht vorsortiert übergeben. Wer den
	 * falschen Tag für „heute" hält, meldet die Pfade von gestern als neu.
	 */
	public function testSortsTheDaysNewestFirstWhateverOrderTheyArriveIn(): void
	{
		$trend = new PathTrend([
			$this->day('2026-09-23', ['/alt' => 1]),
			$this->day('2026-09-25', ['/alt' => 1, '/neu' => 2]),
			$this->day('2026-09-24', ['/alt' => 1]),
		]);
		self::assertSame(['/neu' => 2], $trend->newToday());
		self::assertSame('2026-09-25', $trend->dates()[0]);
	}
}
