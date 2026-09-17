<?php
declare(strict_types=1);

/**
 * Tests der Test-Doubles selbst, damit VhostServiceTest ihnen vertrauen kann.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Nginx\ReloaderInterface;
use VhostAdmin\Ssl\CertbotInterface;

final class FakesTest extends TestCase
{
	public function testFakeReloaderCountsAndFails(): void
	{
		$r = new FakeReloader();
		self::assertInstanceOf(ReloaderInterface::class, $r);
		$r->reload();
		$r->reload();
		self::assertSame(2, $r->calls);
		$r->failWith = 'kaputt';
		$this->expectException(\RuntimeException::class);
		$r->reload();
	}

	public function testFakeCertbotWritesCertificateFiles(): void
	{
		$dir = TempDir::create();
		try {
			$c = new FakeCertbot($dir);
			self::assertInstanceOf(CertbotInterface::class, $c);
			$out = $c->obtain('example.com', '/var/www/example.com', 'a@b.de');
			self::assertStringContainsString('example.com', $out);
			self::assertFileExists($dir . '/example.com/fullchain.pem');
			self::assertFileExists($dir . '/example.com/privkey.pem');
			self::assertSame([['example.com', '/var/www/example.com', 'a@b.de']], $c->calls);
			$c->succeed = false;
			$this->expectException(\RuntimeException::class);
			$c->obtain('fail.com', '/x', 'a@b.de');
		} finally {
			TempDir::remove($dir);
		}
	}
}
