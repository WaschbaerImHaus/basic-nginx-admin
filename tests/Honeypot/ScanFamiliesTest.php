<?php
declare(strict_types=1);

/**
 * Tests der Scanner-Familien: Sitzungen mit fast gleicher Pfadliste über Tage hinweg –
 * dasselbe Werkzeug, auch wenn es seine Kennung wechselt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Tests\Honeypot;

use Honeypot\ScanFamilies;
use Honeypot\ScanSession;
use PHPUnit\Framework\TestCase;

final class ScanFamiliesTest extends TestCase
{
	private static function session(string $date, string $start, array $paths, string $agent = 'bot', int $requests = 0): ScanSession
	{
		return new ScanSession($date, $start, $start, $requests ?: count($paths), [$agent], $paths);
	}

	/** Gleiche Liste an drei Tagen, andere Kennung: eine Familie, drei Tage. */
	public function testRecognisesTheSameToolOnDifferentDays(): void
	{
		$paths = ['/.env', '/.git/config', '/wp-config.php', '/config.php', '/phpinfo.php'];
		$families = ScanFamilies::group([
			self::session('2026-09-20', '03:10:00', $paths, 'curl/7'),
			self::session('2026-09-21', '03:12:00', $paths, 'Mozilla/5.0'),
			self::session('2026-09-22', '03:09:00', array_slice($paths, 0, 4), 'Go-http-client'),
			self::session('2026-09-22', '14:00:00', ['/a', '/b', '/c', '/d']),
		]);
		self::assertCount(2, $families);
		$tool = $families[0];
		self::assertSame(['2026-09-20', '2026-09-21', '2026-09-22'], $tool->dates());
		self::assertSame(['Go-http-client', 'Mozilla/5.0', 'curl/7'], $tool->agents());
		self::assertTrue($tool->recurring());
		self::assertFalse($families[1]->recurring());
		// Kern der Familie: Pfade, die in mindestens der Hälfte ihrer Sitzungen vorkamen.
		self::assertSame(['/.env', '/.git/config', '/config.php', '/phpinfo.php', '/wp-config.php'], $tool->commonPaths());
	}

	/** Ähnlich reicht nicht: Unter 60 % gemeinsamer Pfade sind es verschiedene Werkzeuge. */
	public function testDifferentListsAreDifferentFamilies(): void
	{
		$families = ScanFamilies::group([
			self::session('2026-09-20', '01:00:00', ['/a', '/b', '/c', '/d', '/e']),
			self::session('2026-09-21', '01:00:00', ['/a', '/b', '/x', '/y', '/z']),
		]);
		self::assertCount(2, $families);
	}

	/** Zeitmuster: kommt es immer zur selben Zeit, steht die Uhrzeit dran. */
	public function testDescribesTheTimePattern(): void
	{
		$paths = ['/a', '/b', '/c'];
		$regular = ScanFamilies::group([
			self::session('2026-09-20', '03:05:00', $paths), self::session('2026-09-21', '03:15:00', $paths),
			self::session('2026-09-22', '03:10:00', $paths),
		])[0];
		self::assertSame('meist gegen 03:10', $regular->timePattern());

		$irregular = ScanFamilies::group([
			self::session('2026-09-20', '03:00:00', $paths), self::session('2026-09-21', '15:00:00', $paths),
		])[0];
		self::assertSame('zu wechselnden Zeiten', $irregular->timePattern());
		self::assertSame('einmal um 03:00', ScanFamilies::group([self::session('2026-09-20', '03:00:00', $paths)])[0]->timePattern());
	}

	/** Über Mitternacht: 23:55 und 00:05 liegen zehn Minuten auseinander, nicht fast einen Tag. */
	public function testTheTimePatternWrapsAroundMidnight(): void
	{
		$paths = ['/a', '/b', '/c'];
		$family = ScanFamilies::group([self::session('2026-09-20', '23:55:00', $paths), self::session('2026-09-21', '00:05:00', $paths)])[0];
		self::assertSame('meist gegen 00:00', $family->timePattern());
	}

	/** Reihenfolge: wiederkehrende zuerst, dann nach Zahl der Tage und Anfragen. */
	public function testOrdersRecurringFamiliesFirst(): void
	{
		$families = ScanFamilies::group([
			self::session('2026-09-20', '01:00:00', ['/x1', '/x2', '/x3'], 'a', 500),
			self::session('2026-09-20', '02:00:00', ['/a', '/b', '/c']),
			self::session('2026-09-21', '02:00:00', ['/a', '/b', '/c']),
		]);
		self::assertTrue($families[0]->recurring());
		self::assertSame(500, $families[1]->requests());
	}
}
