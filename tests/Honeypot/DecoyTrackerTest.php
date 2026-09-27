<?php
declare(strict_types=1);

/**
 * Tests der Köderkennungen: ausgegebene Kennungen lesen, spätere Benutzung finden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 11:00
 */

namespace Tests\Honeypot;

use Honeypot\DecoyTracker;
use Honeypot\LogEntry;
use PHPUnit\Framework\TestCase;

final class DecoyTrackerTest extends TestCase
{
	private function issued(string $token, string $time = '2026-09-27T10:00:00+02:00', string $status = '200'): string
	{
		return json_encode([
			'time' => $time, 'token' => $token, 'request' => 'GET /.env HTTP/1.1',
			'status' => $status, 'agent' => 'Scanner/1.0',
		]);
	}

	public function testReadsTheIssuedTokens(): void
	{
		$tokens = DecoyTracker::issued([$this->issued('0123456789'), 'kaputt', '', $this->issued('abcdefabcd', '2026-09-26T23:59:59+02:00')]);
		self::assertCount(2, $tokens);
		self::assertSame([
			'token' => '0123456789', 'date' => '2026-09-27', 'time' => '10:00:00',
			'request' => 'GET /.env HTTP/1.1', 'agent' => 'Scanner/1.0',
		], $tokens[0]);
		self::assertSame('2026-09-26', $tokens[1]['date']);
	}

	/** Ohne Kennung (nginx ohne request_id) oder ohne Auslieferung (304, 404) nichts ausgegeben. */
	public function testSkipsLinesWithoutAnIssuedToken(): void
	{
		self::assertSame([], DecoyTracker::issued([$this->issued(''), $this->issued('0123456789', status: '404'), $this->issued('xyz')]));
	}

	/** Benutzt: die Kennung taucht in einer späteren Anfrage auf, etwa als Schlüssel. */
	public function testFindsATokenInALaterRequest(): void
	{
		$entry = LogEntry::fromLine('10.200.0.1 - - [27/Sep/2026:11:30:00 +0200] "GET /api/internal/status?key=0123456789 HTTP/1.1" 404 5 "-" "curl/8.0"');
		$uses = DecoyTracker::uses([$entry], [], ['0123456789' => true]);
		self::assertSame([[
			'token' => '0123456789', 'date' => '2026-09-27', 'time' => '11:30:00', 'source' => 'Anfrage',
			'detail' => 'GET /api/internal/status?key=0123456789 HTTP/1.1', 'agent' => 'curl/8.0',
		]], $uses);
	}

	/** Oder als Benutzername bei einer Anmeldung (error.log des Verzeichnisschutzes). */
	public function testFindsATokenInALoginAttempt(): void
	{
		$line = '2026/09/28 03:04:05 [error] 12#12: *9 user "deploy-0123456789" was not found in "/etc/nginx/auth/x", client: 10.200.0.1, server: x, request: "GET /admin/ HTTP/1.1", host: "x"';
		$uses = DecoyTracker::uses([], [$line], ['0123456789' => true]);
		self::assertSame('Anmeldung', $uses[0]['source']);
		self::assertSame('deploy-0123456789', $uses[0]['detail']);
		self::assertSame(['2026-09-28', '03:04:05'], [$uses[0]['date'], $uses[0]['time']]);
	}

	/** Unbekannte Hexfolgen sind Zufall, keine Benutzung. */
	public function testIgnoresUnknownTokens(): void
	{
		$entry = LogEntry::fromLine('10.200.0.1 - - [27/Sep/2026:11:30:00 +0200] "GET /?id=aaaaaaaaaa HTTP/1.1" 404 5 "-" "x"');
		self::assertSame([], DecoyTracker::uses([$entry], [], ['0123456789' => true]));
	}
}
