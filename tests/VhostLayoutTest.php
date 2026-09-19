<?php
declare(strict_types=1);

/**
 * Tests für die Pfad- und Rechteverwaltung eines vHosts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:45
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;

final class VhostLayoutTest extends TestCase
{
	private VhostLayout $layout;

	protected function setUp(): void
	{
		$this->layout = new VhostLayout(Config::fromArray([
			'wwwRoot' => '/srv/www',
			'wwwOwner' => 'max',
			'wwwGroup' => 'www-data',
		]));
	}

	public function testPathsForDomain(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		self::assertSame('/srv/www/example.com', $this->layout->baseDir($v));
		self::assertSame('/srv/www/example.com/web', $this->layout->webDir($v));
		self::assertSame('/srv/www/example.com/web', $this->layout->docroot($v));
		self::assertSame('/srv/www/example.com/conf', $this->layout->confDir($v));
		self::assertSame('/srv/www/example.com/conf/custom.conf', $this->layout->confFile($v));
		self::assertSame('/srv/www/example.com/cert', $this->layout->certDir($v));
		self::assertSame('/srv/www/example.com/private', $this->layout->privateDir($v));
		self::assertSame('/srv/www/example.com/logs', $this->layout->logsDir($v));
		self::assertSame('/srv/www/example.com/logs/access.log', $this->layout->accessLog($v));
		self::assertSame('/srv/www/example.com/logs/error.log', $this->layout->errorLog($v));
	}

	public function testDocrootWithSubdirectoryLiesUnderWeb(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'www/src', true, false);
		self::assertSame('/srv/www/example.com/web', $this->layout->webDir($v));
		self::assertSame('/srv/www/example.com/web/www/src', $this->layout->docroot($v));
	}

	public function testPathsForLocalhost(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		self::assertSame('/srv/www/localhost-3000', $this->layout->baseDir($v));
		self::assertSame('/srv/www/localhost-3000/web', $this->layout->docroot($v));
		self::assertSame('/srv/www/localhost-3000/logs/access.log', $this->layout->accessLog($v));
	}

	public function testDirectoriesCarryOwnerGroupAndMode(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$specs = $this->layout->directories($v);
		$byPath = [];
		foreach ($specs as $spec) {
			self::assertInstanceOf(DirectorySpec::class, $spec);
			$byPath[$spec->path] = $spec;
		}
		self::assertSame(
			['/srv/www/example.com', '/srv/www/example.com/web', '/srv/www/example.com/conf',
				'/srv/www/example.com/cert', '/srv/www/example.com/private', '/srv/www/example.com/logs'],
			array_keys($byPath)
		);
		$expect = static function (DirectorySpec $s, string $owner, string $group, int $mode): void {
			self::assertSame($owner, $s->owner, $s->path);
			self::assertSame($group, $s->group, $s->path);
			self::assertSame($mode, $s->mode, $s->path);
			self::assertNotSame('', $s->description, $s->path);
		};
		$expect($byPath['/srv/www/example.com'], 'max', 'www-data', 02775);
		$expect($byPath['/srv/www/example.com/web'], 'max', 'www-data', 02775);
		$expect($byPath['/srv/www/example.com/conf'], 'root', 'www-data', 0750);
		$expect($byPath['/srv/www/example.com/cert'], 'root', 'max', 0750);
		$expect($byPath['/srv/www/example.com/private'], 'max', 'max', 0750);
		$expect($byPath['/srv/www/example.com/logs'], 'root', 'max', 0750);
	}

	public function testDirectoriesIncludeSubdirectorySegments(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'www/src', true, false);
		$paths = array_map(static fn(DirectorySpec $s): string => $s->path, $this->layout->directories($v));
		self::assertContains('/srv/www/example.com/web/www', $paths);
		self::assertContains('/srv/www/example.com/web/www/src', $paths);
		$webIndex = array_search('/srv/www/example.com/web', $paths, true);
		$leafIndex = array_search('/srv/www/example.com/web/www/src', $paths, true);
		self::assertLessThan($leafIndex, $webIndex, 'web/ muss vor seinen Unterordnern stehen');
	}

	public function testNeedsMigration(): void
	{
		$dir = TempDir::create();
		try {
			$layout = new VhostLayout(Config::fromArray(['wwwRoot' => $dir, 'wwwOwner' => 'max']));
			$v = new Vhost(1, 'alt.example', VhostKind::Domain, null, null, true, false);
			self::assertFalse($layout->needsMigration($v), 'ohne Basisordner nichts zu migrieren');
			mkdir($dir . '/alt.example');
			file_put_contents($dir . '/alt.example/index.html', 'alt');
			self::assertTrue($layout->needsMigration($v));
			mkdir($dir . '/alt.example/web');
			self::assertFalse($layout->needsMigration($v));
		} finally {
			TempDir::remove($dir);
		}
	}

	public function testNeedsMigrationTrueForWebSymlink(): void
	{
		$dir = TempDir::create();
		try {
			$layout = new VhostLayout(Config::fromArray(['wwwRoot' => $dir, 'wwwOwner' => 'max']));
			$v = new Vhost(1, 'alt.example', VhostKind::Domain, null, null, true, false);
			mkdir($dir . '/alt.example');
			mkdir($dir . '/anderswo');
			// Ein untergeschobener Symlink an der Stelle von web/ darf nicht als "schon
			// migriert" gelten – sonst würde die Schutzausnahme in der Migration nie greifen.
			symlink($dir . '/anderswo', $dir . '/alt.example/web');
			self::assertTrue($layout->needsMigration($v));
		} finally {
			TempDir::remove($dir);
		}
	}
}
