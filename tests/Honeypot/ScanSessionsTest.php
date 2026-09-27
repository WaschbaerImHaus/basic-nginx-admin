<?php
declare(strict_types=1);

/**
 * Tests der Scanner-Sitzungen: zusammenhängende Anfragen eines Werkzeugs an einem Tag.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:40
 */

namespace Tests\Honeypot;

use Honeypot\LogEntry;
use Honeypot\ScanSession;
use Honeypot\ScanSessions;
use PHPUnit\Framework\TestCase;

final class ScanSessionsTest extends TestCase
{
	/** @return list<LogEntry> aus [Zeit, Pfad, Kennung] */
	private function entries(array $specs): array
	{
		return array_map(
			static fn(array $s): LogEntry => LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:' . $s[0]
				. ' +0200] "GET ' . $s[1] . ' HTTP/1.1" 404 5 "-" "' . $s[2] . '"'),
			$specs
		);
	}

	public function testGroupsRequestsOfOneAgentWithoutLongPauses(): void
	{
		$sessions = ScanSessions::fromEntries($this->entries([
			['03:10:00', '/.env', 'bot'], ['03:10:01', '/.git/config', 'bot'], ['03:10:02', '/wp-config.php', 'bot'],
			['03:10:02', '/', 'browser'],
			// Über 30 Minuten Pause: eine neue Sitzung desselben Werkzeugs.
			['05:00:00', '/a', 'bot'], ['05:00:01', '/b', 'bot'], ['05:00:02', '/c', 'bot'],
		]));
		self::assertCount(2, $sessions, 'der Browser mit einer Anfrage ist keine Sitzung');
		self::assertSame(['/.env', '/.git/config', '/wp-config.php'], $sessions[0]->paths);
		self::assertSame('bot', $sessions[0]->agents[0]);
		self::assertSame(3, $sessions[0]->requests);
		self::assertSame(['2026-09-21', '03:10:00', '03:10:02'], [$sessions[0]->date, $sessions[0]->start, $sessions[0]->end]);
		self::assertSame(['/a', '/b', '/c'], $sessions[1]->paths);
	}

	/** Weniger als drei Anfragen oder nur ein Pfad: zu wenig für einen Fingerabdruck. */
	public function testSkipsTooSmallSessions(): void
	{
		self::assertSame([], ScanSessions::fromEntries($this->entries([
			['03:10:00', '/', 'x'], ['03:10:01', '/', 'x'], ['03:10:02', '/', 'x'], ['03:11:00', '/a', 'y'], ['03:11:01', '/b', 'y'],
		])));
	}

	/**
	 * Ein Werkzeug, das seine Kennung durchwechselt (gleich viele Anfragen je Kennung),
	 * ist EINE Sitzung – sonst zerfiele es in lauter Einzelanfragen.
	 */
	public function testRotatingAgentsFormOneSession(): void
	{
		$specs = [];
		foreach (['Mozilla/5.0 A', 'Mozilla/5.0 B', 'Mozilla/5.0 C'] as $i => $agent) {
			for ($n = 0; $n < 5; $n++) {
				$specs[] = [sprintf('04:00:%02d', $i * 5 + $n), '/p' . ($i * 5 + $n), $agent];
			}
		}
		$sessions = ScanSessions::fromEntries($this->entries($specs));
		self::assertCount(1, $sessions);
		self::assertSame(15, $sessions[0]->requests);
		self::assertSame(['Mozilla/5.0 A', 'Mozilla/5.0 B', 'Mozilla/5.0 C'], $sessions[0]->agents);
	}

	/** Die Abfrage gehört nicht zum Fingerabdruck – ?x=1 und ?x=2 sind derselbe Pfad. */
	public function testIgnoresTheQuery(): void
	{
		$sessions = ScanSessions::fromEntries($this->entries([
			['03:10:00', '/a?x=1', 'b'], ['03:10:01', '/a?x=2', 'b'], ['03:10:02', '/b', 'b'],
		]));
		self::assertSame(['/a', '/b'], $sessions[0]->paths);
	}

	public function testRoundTripsThroughAnArray(): void
	{
		$session = new ScanSession('2026-09-21', '03:10:00', '03:12:00', 7, ['bot'], ['/a', '/b']);
		self::assertEquals($session, ScanSession::fromArray($session->toArray()));
		self::assertSame(190, $session->startMinute());
	}
}
