<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Username.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Username;

final class UsernameTest extends TestCase
{
	public function testAcceptsTypicalNames(): void
	{
		self::assertSame('alice', Username::fromString('alice')->value);
		self::assertSame('bob.smith@example.com', Username::fromString('bob.smith@example.com')->value);
		self::assertSame(str_repeat('a', 64), Username::fromString(str_repeat('a', 64))->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidNames(): iterable
	{
		yield 'leer' => [''];
		yield 'Doppelpunkt (htpasswd-Trenner)' => ['a:b'];
		yield 'Leerzeichen' => ['a b'];
		yield 'zu lang' => [str_repeat('a', 65)];
		yield 'Umlaut' => ['jürgen'];
		yield 'Zeilenumbruch' => ["a\nb"];
	}

	#[DataProvider('invalidNames')]
	public function testRejectsInvalidNames(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Username::fromString($raw);
	}
}
