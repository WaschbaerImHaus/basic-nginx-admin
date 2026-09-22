<?php
declare(strict_types=1);

/**
 * Tests der Anmeldeversuche aus dem error.log.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:40
 */

namespace Tests\Honeypot;

use Honeypot\LoginAttempts;
use PHPUnit\Framework\TestCase;

final class LoginAttemptsTest extends TestCase
{
	private const LINES = [
		'2026/09/21 15:55:33 [error] 123#123: *4 user "admin" was not found in "/etc/nginx/auth/mfsvr.de.htpasswd", client: 10.200.0.1',
		'2026/09/21 15:56:01 [error] 123#123: *5 user "admin" was not found in "/etc/nginx/auth/mfsvr.de.htpasswd", client: 10.200.0.1',
		'2026/09/22 08:00:00 [error] 123#123: *6 user "root" password mismatch, client: 10.200.0.1',
		'2026/09/22 08:00:01 [error] 123#123: *7 open() "/var/www/x" failed (2: No such file or directory)',
	];

	public function testCountsAttemptsPerUser(): void
	{
		self::assertSame(['admin' => 2, 'root' => 1], (new LoginAttempts())->fromLines(self::LINES));
	}

	/**
	 * Die Versuche gehören zu dem Tag, an dem sie stattfanden – das error.log wird wie
	 * das access.log morgens rotiert und enthält zwei Kalendertage.
	 */
	public function testSplitsAttemptsByCalendarDay(): void
	{
		$byDate = (new LoginAttempts())->byDate(self::LINES);
		self::assertSame(['2026-09-21', '2026-09-22'], array_keys($byDate));
		self::assertSame(['admin' => 2], $byDate['2026-09-21']);
		self::assertSame(['root' => 1], $byDate['2026-09-22']);
	}

	public function testIgnoresErrorsThatAreNotLoginAttempts(): void
	{
		self::assertSame([], (new LoginAttempts())->fromLines([self::LINES[3]]));
	}
}
