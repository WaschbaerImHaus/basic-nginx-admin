<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Port;

final class PortTest extends TestCase
{
	public function testAcceptsRange(): void
	{
		self::assertSame(1, Port::fromString('1')->value);
		self::assertSame(3000, Port::fromString('3000')->value);
		self::assertSame(65535, Port::fromString('65535')->value);
		self::assertSame('3000', (string)Port::fromString('3000'));
	}

	public function testAdminPortIsConfigurable(): void
	{
		self::assertSame(8080, Port::fromString('8080', 9090)->value);
		$this->expectException(\InvalidArgumentException::class);
		Port::fromString('9090', 9090);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidPorts(): iterable
	{
		yield 'null' => ['0'];
		yield 'zu groß' => ['65536'];
		yield 'negativ' => ['-1'];
		yield 'Text' => ['abc'];
		yield 'leer' => [''];
		yield 'Dezimal' => ['80.5'];
		yield 'http' => ['80'];
		yield 'https' => ['443'];
		yield 'admin' => ['8080'];
	}

	#[DataProvider('invalidPorts')]
	public function testRejectsInvalidPorts(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Port::fromString($raw);
	}
}
