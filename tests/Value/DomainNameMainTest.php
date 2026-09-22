<?php
declare(strict_types=1);

/**
 * Tests für die Unterscheidung Hauptdomain / Unterdomain.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 14:10
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\DomainName;

final class DomainNameMainTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function names(): iterable
	{
		yield 'einfache Domain' => ['example.com', true];
		yield 'deutsche Domain' => ['mfsvr.de', true];
		yield 'Unterdomain' => ['bienchen.mfsvr.de', false];
		yield 'tiefe Unterdomain' => ['a.b.example.com', false];
		yield 'www ist selbst eine Unterdomain' => ['www.example.com', false];
		// Zweiteilige Endungen: example.co.uk ist eine Hauptdomain, shop.example.co.uk nicht.
		yield 'britische Hauptdomain' => ['example.co.uk', true];
		yield 'britische Unterdomain' => ['shop.example.co.uk', false];
		yield 'brasilianische Hauptdomain' => ['example.com.br', true];
		yield 'österreichische Hauptdomain' => ['example.ac.at', true];
		yield 'nur eine Marke' => ['example', false];
	}

	#[DataProvider('names')]
	public function testIsMainDomain(string $name, bool $expected): void
	{
		self::assertSame($expected, DomainName::isMainDomain($name), $name);
	}
}
