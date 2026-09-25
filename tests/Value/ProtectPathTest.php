<?php
declare(strict_types=1);

/**
 * Tests des geschützten Pfads.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 21:15
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\ProtectPath;

final class ProtectPathTest extends TestCase
{
	public static function normalised(): array
	{
		return [
			['/admin', '/admin'],
			['/admin/', '/admin'],
			['  /admin/intern/  ', '/admin/intern'],
			['admin', '/admin'],
			['/geheim.html', '/geheim.html'],
			['/a-b_c~d.e', '/a-b_c~d.e'],
		];
	}

	#[DataProvider('normalised')]
	public function testNormalisesTheInput(string $raw, string $expected): void
	{
		self::assertSame($expected, ProtectPath::fromString($raw)?->value);
	}

	/**
	 * Leer oder "/" heisst: die ganze Seite. Das ist kein eigener Pfad, sondern der
	 * Normalfall – er wird ohne Pfad gespeichert.
	 */
	public function testEmptyOrRootMeansTheWholeSite(): void
	{
		self::assertNull(ProtectPath::fromString(''));
		self::assertNull(ProtectPath::fromString('   '));
		self::assertNull(ProtectPath::fromString('/'));
		self::assertNull(ProtectPath::fromString('///'));
	}

	public static function rejected(): array
	{
		return [
			'Rückwärts' => ['/admin/../etc'],
			'Punktsegment' => ['/./admin'],
			'doppelter Schrägstrich' => ['/admin//intern'],
			'Leerzeichen' => ['/mein ordner'],
			'Semikolon' => ['/admin;deny'],
			'geschweifte Klammer' => ['/admin}'],
			'Anführungszeichen' => ['/"admin'],
			'Dollar' => ['/$uri'],
			'Regex-Zeichen' => ['/admin(.*)'],
			'Zeilenumbruch' => ["/admin\nallow all"],
			'Prozent' => ['/%61dmin'],
			'Umlaut' => ['/über'],
			'Rückstrich' => ['/admin\\x'],
		];
	}

	/**
	 * Der Pfad landet als regulärer Ausdruck in der nginx-Konfiguration. Alles, was
	 * dort eine Bedeutung hat (Semikolon, Klammern, Anführungszeichen, $, Zeilenumbruch),
	 * wäre ein Weg, eigene Direktiven einzuschleusen – oder den Schutz auszuhebeln.
	 * Prozentkodierung ist sinnlos: nginx vergleicht mit dem bereits dekodierten $uri.
	 */
	#[DataProvider('rejected')]
	public function testRejectsAnythingThatCouldEscapeTheRule(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		ProtectPath::fromString($raw);
	}

	public function testRejectsAnOverlongPath(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		ProtectPath::fromString('/' . str_repeat('a', 300));
	}

	/**
	 * /admin schützt /admin, /admin/ und alles darunter – aber nicht /administrator.
	 * Der Punkt ist im Muster maskiert: /geheim.html darf nicht auf /geheimXhtml passen.
	 */
	public function testBuildsAPatternThatMatchesTheDirectoryAndEverythingBelow(): void
	{
		$pattern = '~' . substr(ProtectPath::fromString('/admin')->pattern(), 1) . '~';
		foreach (['/admin', '/admin/', '/admin/x.php', '/admin/a/b'] as $uri) {
			self::assertSame(1, preg_match($pattern, $uri), $uri);
		}
		foreach (['/administrator', '/adminx', '/', '/x/admin'] as $uri) {
			self::assertSame(0, preg_match($pattern, $uri), $uri);
		}
		$file = '~' . substr(ProtectPath::fromString('/geheim.html')->pattern(), 1) . '~';
		self::assertSame(1, preg_match($file, '/geheim.html'));
		self::assertSame(0, preg_match($file, '/geheimXhtml'), 'der Punkt muss maskiert sein');
	}

	public function testThePatternIsAnNginxRegexKey(): void
	{
		self::assertSame('~^/admin/intern(?:/|$)', ProtectPath::fromString('/admin/intern')->pattern());
	}
}
