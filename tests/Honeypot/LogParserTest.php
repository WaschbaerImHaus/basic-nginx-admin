<?php
declare(strict_types=1);

/**
 * Tests der Gruppierung nach Kalendertagen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\LogParser;
use PHPUnit\Framework\TestCase;

final class LogParserTest extends TestCase
{
	private function line(string $date, string $path = '/'): string
	{
		return '10.200.0.1 - - [' . $date . ':12:00:00 +0200] "GET ' . $path . ' HTTP/1.1" 200 5 "-" "x"';
	}

	/**
	 * logrotate schneidet um 06:20, nicht um Mitternacht: access.log.1 enthält das Ende
	 * des Vortags UND den Morgen des laufenden Tages. Wer die Datei als „Vortag" zählt,
	 * schreibt dem falschen Tag bis zu sechs Stunden Verkehr zu.
	 */
	public function testSplitsARotatedFileAtTheCalendarDayNotAtTheFileBoundary(): void
	{
		$parsed = (new LogParser())->parse([
			$this->line('21/Sep/2026'),
			$this->line('21/Sep/2026'),
			$this->line('22/Sep/2026'),
		]);
		self::assertSame(['2026-09-21', '2026-09-22'], array_keys($parsed->days));
		self::assertCount(2, $parsed->days['2026-09-21']);
		self::assertCount(1, $parsed->days['2026-09-22']);
	}

	public function testCountsLinesItCannotRead(): void
	{
		$parsed = (new LogParser())->parse([$this->line('21/Sep/2026'), 'Unfug', '']);
		self::assertSame(1, $parsed->unreadable, 'leere Zeilen zählen nicht als unlesbar');
		self::assertCount(1, $parsed->days['2026-09-21']);
	}

	public function testDaysAreSortedAscending(): void
	{
		$parsed = (new LogParser())->parse([$this->line('22/Sep/2026'), $this->line('20/Sep/2026')]);
		self::assertSame(['2026-09-20', '2026-09-22'], array_keys($parsed->days));
	}

	/**
	 * Die Dateien werden in der Reihenfolge access.log, access.log.1 gelesen – also
	 * neuere vor älteren. Innerhalb eines Tages stünden die Einträge damit verkehrt
	 * herum, und jede Messung „was kam danach" (robots.txt → /admin) liefert einen
	 * negativen Abstand. Belegt an den echten Logs am 2026-09-22.
	 */
	public function testSortsTheEntriesOfADayChronologicallyWhateverTheFileOrder(): void
	{
		$late = '10.200.0.1 - - [21/Sep/2026:18:00:00 +0200] "GET /spaet HTTP/1.1" 200 5 "-" "x"';
		$early = '10.200.0.1 - - [21/Sep/2026:04:00:00 +0200] "GET /frueh HTTP/1.1" 200 5 "-" "x"';
		$parsed = (new LogParser())->parse([$late, $early]);
		self::assertSame(
			['/frueh', '/spaet'],
			array_map(static fn($entry): string => $entry->path, $parsed->days['2026-09-21'])
		);
	}
}
