<?php
declare(strict_types=1);

/**
 * Tests für die Konfigurationsklasse.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;

final class ConfigTest extends TestCase
{
	public function testDefaultsPointToProductionPaths(): void
	{
		$c = Config::defaults();
		self::assertSame('/var/lib/vhost-admin/vhosts.sqlite', $c->dbPath);
		self::assertSame('/var/www', $c->wwwRoot);
		self::assertSame('www-data', $c->wwwGroup);
		self::assertSame('/etc/nginx/sites-available', $c->sitesAvailable);
		self::assertSame('/etc/nginx/sites-enabled', $c->sitesEnabled);
		self::assertSame('/etc/nginx/auth', $c->authDir);
		self::assertSame('/etc/letsencrypt/live', $c->letsEncryptLive);
		self::assertSame('/usr/local/sbin/vhost', $c->vhostBinary);
		self::assertSame(8080, $c->adminPort);
		self::assertStringEndsWith('/templates/index.html', $c->templatePath);
	}

	public function testFromArrayOverridesOnlyGivenKeys(): void
	{
		$c = Config::fromArray(['wwwRoot' => '/tmp/www', 'adminPort' => 9090, 'ipv6' => false]);
		self::assertSame('/tmp/www', $c->wwwRoot);
		self::assertSame(9090, $c->adminPort);
		self::assertFalse($c->ipv6);
		self::assertSame('/etc/nginx/auth', $c->authDir);
	}

	public function testFromArrayRejectsUnknownKey(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Config::fromArray(['doesNotExist' => 1]);
	}
}
