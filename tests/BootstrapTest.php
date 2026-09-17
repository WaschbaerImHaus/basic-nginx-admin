<?php
declare(strict_types=1);

/**
 * Prüft, dass der Autoloader Klassen des Namespace VhostAdmin findet.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:23
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
	public function testAutoloaderFindsConfigClass(): void
	{
		self::assertTrue(class_exists(\VhostAdmin\Config::class));
	}

	public function testTempDirIsCreatedAndRemoved(): void
	{
		$dir = Support\TempDir::create();
		self::assertDirectoryExists($dir);
		mkdir($dir . '/a');
		file_put_contents($dir . '/a/b.txt', 'x');
		Support\TempDir::remove($dir);
		self::assertDirectoryDoesNotExist($dir);
	}
}
