<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt SubDirectory.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:33
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\SubDirectory;

final class SubDirectoryTest extends TestCase
{
	public function testEmptyMeansNone(): void
	{
		self::assertNull(SubDirectory::fromString(null));
		self::assertNull(SubDirectory::fromString(''));
		self::assertNull(SubDirectory::fromString('  /  '));
	}

	public function testTrimsSlashesAndSplitsSegments(): void
	{
		$s = SubDirectory::fromString('/public/html/');
		self::assertSame('public/html', $s->value);
		self::assertSame(['public', 'html'], $s->segments());
		self::assertSame('public/html', (string)$s);
	}

	public function testAcceptsDotsInsideSegments(): void
	{
		self::assertSame('v1.2/site_a', SubDirectory::fromString('v1.2/site_a')->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidDirectories(): iterable
	{
		yield 'Elternverzeichnis' => ['../etc'];
		yield 'Elternverzeichnis innen' => ['public/../secret'];
		yield 'Punktverzeichnis' => ['./a'];
		yield 'versteckt' => ['.hidden'];
		yield 'Leerzeichen' => ['my site'];
		yield 'Umlaut' => ['bilder/über'];
		yield 'Backslash' => ['a\\b'];
		yield 'doppelter Schrägstrich' => ['a//b'];
	}

	#[DataProvider('invalidDirectories')]
	public function testRejectsInvalidDirectories(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SubDirectory::fromString($raw);
	}
}
