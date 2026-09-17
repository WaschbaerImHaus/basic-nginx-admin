<?php
declare(strict_types=1);

/**
 * Tests für die Entität Vhost.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 18:20
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;

final class VhostTest extends TestCase
{
	private Config $config;

	protected function setUp(): void
	{
		$this->config = Config::fromArray(['wwwRoot' => '/srv/www']);
	}

	public function testDomainPaths(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		self::assertFalse($v->isLocal());
		self::assertSame('example.com', $v->slug());
		self::assertSame('/srv/www/example.com', $v->baseDir($this->config));
		self::assertSame('/srv/www/example.com', $v->docroot($this->config));
	}

	public function testDomainWithSubdirectory(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public/html', true, false);
		self::assertSame('/srv/www/example.com', $v->baseDir($this->config));
		self::assertSame('/srv/www/example.com/public/html', $v->docroot($this->config));
	}

	public function testLocalhostPaths(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		self::assertTrue($v->isLocal());
		self::assertSame('localhost-3000', $v->slug());
		self::assertSame('/srv/www/localhost-3000', $v->docroot($this->config));
	}

	public function testFromRowConvertsTypes(): void
	{
		$v = Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'domain', 'port' => null, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => '2026-09-17 08:00:00',
		]);
		self::assertSame(7, $v->id);
		self::assertSame(VhostKind::Domain, $v->kind);
		self::assertNull($v->port);
		self::assertTrue($v->protect);
		self::assertFalse($v->ssl);
		self::assertSame('2026-09-17 08:00:00', $v->createdAt);
	}

	public function testFromRowTreatsEmptySubdirAsNull(): void
	{
		$v = Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'domain', 'port' => null, 'subdir' => '',
			'protect' => '0', 'ssl' => '1', 'created_at' => '2026-09-17 08:00:00',
		]);
		self::assertNull($v->subdir);
		self::assertFalse($v->protect);
		self::assertTrue($v->ssl);
		self::assertSame('/srv/www/a.de', $v->docroot(Config::fromArray(['wwwRoot' => '/srv/www'])));
	}

	public function testFromRowRejectsPathEscapeInName(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('../ausbruch');
		Vhost::fromRow([
			'id' => '7', 'name' => '../ausbruch', 'kind' => 'domain', 'port' => null, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => '2026-09-17 08:00:00',
		]);
	}

	public function testFromRowRejectsDomainWithPort(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Domain mit Port');
		Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'domain', 'port' => 3000, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => null,
		]);
	}

	public function testFromRowRejectsLocalhostWithoutMatchingPort(): void
	{
		$this->expectException(\RuntimeException::class);
		Vhost::fromRow([
			'id' => '7', 'name' => 'localhost-', 'kind' => 'localhost', 'port' => null, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => null,
		]);
	}

	public function testFromRowRejectsLocalhostNameMismatch(): void
	{
		$this->expectException(\RuntimeException::class);
		Vhost::fromRow([
			'id' => '7', 'name' => 'localhost:3000', 'kind' => 'localhost', 'port' => 3001, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => null,
		]);
	}

	public function testFromRowRejectsInvalidSubdir(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('../secret');
		Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'domain', 'port' => null, 'subdir' => '../secret',
			'protect' => '1', 'ssl' => '0', 'created_at' => null,
		]);
	}

	public function testFromRowAcceptsValidLocalhostRow(): void
	{
		$v = Vhost::fromRow([
			'id' => '9', 'name' => 'localhost:3000', 'kind' => 'localhost', 'port' => 3000, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => null,
		]);
		self::assertSame('localhost:3000', $v->name);
	}
}
