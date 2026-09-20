<?php
declare(strict_types=1);

/**
 * Tests für die Pfadgrenzen eines Snippets.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 21:12
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\SnippetScope;

final class SnippetScopeTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function paths(): iterable
	{
		yield 'Ordner selbst' => ['/var/www/a.de', '/var/www/a.de', true];
		yield 'Datei darin' => ['/var/www/a.de/web/index.php', '/var/www/a.de', true];
		yield 'doppelte Schrägstriche' => ['/var/www//a.de///web', '/var/www/a.de', true];
		yield 'Punkt-Segmente' => ['/var/www/a.de/./web/../web', '/var/www/a.de', true];
		yield 'Nachbarordner mit gleichem Anfang' => ['/var/www/a.de.evil/web', '/var/www/a.de', false];
		yield 'Ausbruch mit ..' => ['/var/www/a.de/../../etc/passwd', '/var/www/a.de', false];
		yield 'ganz anderer Pfad' => ['/etc/nginx', '/var/www/a.de', false];
		yield 'relativer Pfad' => ['web/index.php', '/var/www/a.de', false];
	}

	#[DataProvider('paths')]
	public function testIsInside(string $path, string $dir, bool $expected): void
	{
		self::assertSame($expected, SnippetScope::isInside($path, $dir));
	}

	public function testNormalizeKeepsLeadingSlashAndDropsNoise(): void
	{
		self::assertSame('/var/www/a.de/web', SnippetScope::normalize('/var//www/./a.de/x/../web'));
		self::assertSame('/', SnippetScope::normalize('/..'));
	}
}
