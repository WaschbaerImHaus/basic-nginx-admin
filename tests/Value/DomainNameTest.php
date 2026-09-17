<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt DomainName.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\DomainName;

final class DomainNameTest extends TestCase
{
	public function testNormalizesCaseAndWhitespace(): void
	{
		self::assertSame('example.com', DomainName::fromString('  Example.COM ')->value);
		self::assertSame('example.com', (string)DomainName::fromString('example.com'));
	}

	public function testAcceptsSubdomainsAndHyphens(): void
	{
		self::assertSame('a-b.sub.example.co.uk', DomainName::fromString('a-b.sub.example.co.uk')->value);
	}

	public function testAcceptsMaximumLength(): void
	{
		$label = str_repeat('a', 63);
		$name = $label . '.' . $label . '.' . $label . '.' . str_repeat('b', 58) . '.de'; // 253 Zeichen
		self::assertSame(253, strlen($name));
		self::assertSame($name, DomainName::fromString($name)->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidNames(): iterable
	{
		yield 'leer' => [''];
		yield 'ohne TLD' => ['example'];
		yield 'localhost' => ['localhost'];
		yield 'localhost mit Port' => ['localhost:3000'];
		yield 'localhost-Subdomain' => ['app.localhost'];
		yield 'Umlaut' => ['müller.de'];
		yield 'Bindestrich am Anfang' => ['-a.example.com'];
		yield 'Bindestrich am Ende' => ['a-.example.com'];
		yield 'Label zu lang' => [str_repeat('a', 64) . '.example.com'];
		yield 'numerische TLD' => ['example.123'];
		yield 'Leerzeichen innen' => ['exa mple.com'];
		yield 'Schrägstrich' => ['example.com/'];
		yield 'zu lang' => [str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.' . str_repeat('b', 59) . '.de'];
	}

	#[DataProvider('invalidNames')]
	public function testRejectsInvalidNames(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DomainName::fromString($raw);
	}
}
