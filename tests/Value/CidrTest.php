<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Cidr.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Cidr;

final class CidrTest extends TestCase
{
	/** @return iterable<string, array{string, string}> */
	public static function validValues(): iterable
	{
		yield 'IPv4' => ['203.0.113.5', '203.0.113.5'];
		yield 'IPv4 mit Maske' => ['10.0.0.0/8', '10.0.0.0/8'];
		yield 'IPv4 /32' => ['10.0.0.1/32', '10.0.0.1/32'];
		yield 'IPv4 /0' => ['0.0.0.0/0', '0.0.0.0/0'];
		yield 'IPv6' => ['2001:db8::1', '2001:db8::1'];
		yield 'IPv6 mit Maske' => ['2001:db8::/32', '2001:db8::/32'];
		yield 'IPv6 /128' => ['::1/128', '::1/128'];
		yield 'führende Null in Maske' => ['10.0.0.0/08', '10.0.0.0/8'];
		yield 'Leerzeichen außen' => [' 127.0.0.1 ', '127.0.0.1'];
	}

	#[DataProvider('validValues')]
	public function testAcceptsAndNormalizes(string $raw, string $expected): void
	{
		self::assertSame($expected, Cidr::fromString($raw)->value);
		self::assertSame($expected, (string)Cidr::fromString($raw));
	}

	/** @return iterable<string, array{string}> */
	public static function invalidValues(): iterable
	{
		yield 'leer' => [''];
		yield 'Hostname' => ['example.com'];
		yield 'IPv4 Oktett zu groß' => ['256.0.0.1'];
		yield 'IPv4 Maske zu groß' => ['10.0.0.0/33'];
		yield 'IPv6 Maske zu groß' => ['::1/129'];
		yield 'Maske negativ' => ['10.0.0.0/-1'];
		yield 'Maske Text' => ['10.0.0.0/abc'];
		yield 'doppelter Schrägstrich' => ['10.0.0.0//8'];
		yield 'Semikolon (nginx-Injektion)' => ['10.0.0.1; deny all'];
	}

	#[DataProvider('invalidValues')]
	public function testRejectsInvalidValues(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Cidr::fromString($raw);
	}
}
