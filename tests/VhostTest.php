<?php
declare(strict_types=1);

/**
 * Tests für die Entität Vhost.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:14
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;

final class VhostTest extends TestCase
{
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
		self::assertSame('a.de', $v->slug());
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

	/**
	 * Befund 4: ein unbekannter Wert in der Spalte "kind" soll eine \RuntimeException
	 * auslösen (wie die übrigen Revalidierungsprüfungen), nicht den \ValueError von
	 * VhostKind::from() – den fängt das CLI zwar über \Throwable ab, die Oberfläche
	 * aber nicht, sodass ein defekter Datensatz dort zu HTTP 500 führen würde.
	 */
	public function testFromRowRejectsUnknownKind(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unbekannt');
		Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'ftp', 'port' => null, 'subdir' => null,
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
