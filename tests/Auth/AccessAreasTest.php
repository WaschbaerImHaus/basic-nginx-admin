<?php
declare(strict_types=1);

/**
 * Tests der Schutzbereiche: welcher Pfad geschützt ist und wer dort hinein darf, wenn
 * jeder Benutzer seinen eigenen Pfad hat.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:30
 */

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Auth\AccessAreas;

final class AccessAreasTest extends TestCase
{
	private static function user(string $name, ?string $path): array
	{
		return ['username' => $name, 'hash' => 'h-' . $name, 'path' => $path];
	}

	/** @return array<string, list<string>> Pfad ("" = ganze Seite) => Benutzer */
	private static function map(AccessAreas $areas): array
	{
		$out = [];
		foreach ($areas->areas() as $area) {
			$out[$area->path ?? ''] = array_column($area->users, 'username');
		}
		return $out;
	}

	/** Ohne Benutzer: ganze Seite gesperrt (neuer Host, noch niemand eingetragen). */
	public function testNoUsersLocksTheWholeSite(): void
	{
		$areas = AccessAreas::fromUsers([]);
		self::assertSame(['' => []], self::map($areas));
		self::assertTrue($areas->coversWholeSite());
	}

	/** Alle ohne Pfad: das bisherige Verhalten – ein Bereich, alle Benutzer. */
	public function testUsersWithoutPathProtectTheWholeSite(): void
	{
		self::assertSame(['' => ['a', 'b']], self::map(AccessAreas::fromUsers([self::user('a', null), self::user('b', null)])));
	}

	/** Nur Benutzer mit Pfad: Der Rest der Seite ist frei. */
	public function testOnlyPathUsersLeaveTheRestOpen(): void
	{
		$areas = AccessAreas::fromUsers([self::user('a', '/admin')]);
		self::assertSame(['/admin' => ['a']], self::map($areas));
		self::assertFalse($areas->coversWholeSite());
	}

	/**
	 * Wer die ganze Seite darf, darf auch in jeden Unterbereich; wer /admin darf, auch
	 * in /admin/intern – aber nicht umgekehrt, und nicht in /shop.
	 */
	public function testWiderUsersAreAllowedInNarrowerAreas(): void
	{
		$areas = AccessAreas::fromUsers([
			self::user('chef', null), self::user('admin', '/admin'), self::user('intern', '/admin/intern'),
			self::user('shop', '/shop'),
		]);
		self::assertSame([
			'/admin/intern' => ['chef', 'admin', 'intern'],
			'/admin' => ['chef', 'admin'],
			'/shop' => ['chef', 'shop'],
			'' => ['chef'],
		], self::map($areas));
	}

	/** Genauere Pfade zuerst – nginx nimmt in einer map den ersten passenden Ausdruck. */
	public function testMoreSpecificPathsComeFirst(): void
	{
		$paths = array_map(
			static fn($area): ?string => $area->path,
			AccessAreas::fromUsers([self::user('a', '/a'), self::user('b', '/a/b/c'), self::user('c', '/a/b'), self::user('d', '/z')])->areas()
		);
		self::assertSame(['/a/b/c', '/a/b', '/a', '/z'], $paths);
	}

	/** „/admin" deckt „/administrator" nicht ab. */
	public function testAPrefixIsNoParent(): void
	{
		$map = self::map(AccessAreas::fromUsers([self::user('a', '/admin'), self::user('b', '/administrator')]));
		self::assertSame(['b'], $map['/administrator']);
	}

	/** Mehrere Benutzer mit demselben Pfad teilen sich einen Bereich. */
	public function testSamePathSharesAnArea(): void
	{
		self::assertSame(['/x' => ['a', 'b']], self::map(AccessAreas::fromUsers([self::user('a', '/x'), self::user('b', '/x')])));
	}

	/**
	 * Dateinamen: die ganze Seite behält den bisherigen Namen (Bestand), ein Pfad
	 * bekommt einen festen Zusatz – gleicher Pfad, gleicher Name, bei jedem Rendern.
	 */
	public function testFileSuffixesAreStable(): void
	{
		$areas = AccessAreas::fromUsers([self::user('a', '/admin'), self::user('b', null)])->areas();
		self::assertSame('', $areas[1]->fileSuffix());
		self::assertMatchesRegularExpression('/^\.p-[0-9a-f]{10}$/', $areas[0]->fileSuffix());
		self::assertSame($areas[0]->fileSuffix(), AccessAreas::fromUsers([self::user('x', '/admin')])->areas()[0]->fileSuffix());
	}
}
