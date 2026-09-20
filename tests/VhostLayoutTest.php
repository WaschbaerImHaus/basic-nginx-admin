<?php
declare(strict_types=1);

/**
 * Tests für die Pfad- und Rechteverwaltung eines vHosts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 10:34
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\Value\SnippetScope;
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
		$expect($byPath['/srv/www/example.com'], 'max', 'max', 0755);
		$expect($byPath['/srv/www/example.com/web'], 'max', 'www-data', 02775);
		$expect($byPath['/srv/www/example.com/conf'], 'root', 'www-data', 0750);
		$expect($byPath['/srv/www/example.com/cert'], 'root', 'max', 0750);
		$expect($byPath['/srv/www/example.com/private'], 'max', 'max', 0750);
		$expect($byPath['/srv/www/example.com/logs'], 'root', 'max', 0750);
	}

	/**
	 * C2 (Abschlussreview): Der Basisordner darf für www-data nicht mehr
	 * gruppenbeschreibbar sein – sonst kann www-data ihn umbenennen/Einträge
	 * ersetzen und z.B. den root-eigenen conf/-Ordner austauschen. web/ bleibt
	 * bewusst gruppenbeschreibbar, damit die Oberfläche dort Inhalte pflegen kann.
	 */
	public function testBaseDirIsNotGroupWritableButWebDirIs(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$byPath = [];
		foreach ($this->layout->directories($v) as $spec) {
			$byPath[$spec->path] = $spec;
		}
		$base = $byPath['/srv/www/example.com'];
		$web = $byPath['/srv/www/example.com/web'];
		self::assertSame(0, $base->mode & 0020, 'Basisordner darf nicht gruppenbeschreibbar sein');
		self::assertNotSame(0, $web->mode & 0020, 'web/ muss gruppenbeschreibbar sein');
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
	public function testPhpPathsAndUser(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		// Benutzername nach dem ISPConfig-Schema: kurz, stabil, aus der Datenbank-ID.
		// Ein Name aus der Domain wäre nicht zuverlässig gültig (Länge, Punkte, Bindestriche).
		self::assertSame('web7', $this->layout->phpUser($v));
		self::assertSame('/run/php/vhost-example.com.sock', $this->layout->phpSocket($v));
		self::assertSame('/etc/php/8.5/fpm/pool.d/vhost-example.com.conf', $this->layout->phpPoolFile($v));
		self::assertSame('/srv/www/example.com/logs/php.log', $this->layout->phpLog($v));
	}

	public function testPhpPathsForLocalhostUseTheSlug(): void
	{
		$v = new Vhost(3, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false, true);
		self::assertSame('web3', $this->layout->phpUser($v));
		self::assertSame('/run/php/vhost-localhost-3000.sock', $this->layout->phpSocket($v));
		self::assertSame('/etc/php/8.5/fpm/pool.d/vhost-localhost-3000.conf', $this->layout->phpPoolFile($v));
	}

	public function testPhpUserNeedsAnIdentifier(): void
	{
		$v = new Vhost(null, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$this->expectException(\RuntimeException::class);
		$this->layout->phpUser($v);
	}

	/**
	 * Mit PHP gehört web/ dem eigenen Benutzer des Hosts, nicht mehr dem allgemeinen
	 * Besitzer: PHP läuft als dieser Benutzer und schreibt dort, nginx liest über die
	 * Gruppe. www-data verliert damit das Schreibrecht, das es ohne PHP hatte.
	 */
	public function testWebDirBelongsToThePhpUserWhenPhpIsOn(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, 'public', true, false, true);
		$byPath = [];
		foreach ($this->layout->directories($v) as $spec) {
			$byPath[$spec->path] = $spec;
		}
		$web = $byPath['/srv/www/example.com/web'];
		self::assertSame('web7', $web->owner);
		self::assertSame('www-data', $web->group);
		self::assertSame(02750, $web->mode);
		$sub = $byPath['/srv/www/example.com/web/public'];
		self::assertSame('web7', $sub->owner);
		self::assertSame(02750, $sub->mode);
		// Der Basisordner bleibt beim allgemeinen Besitzer (C2 vom 2026-09-20).
		self::assertSame('max', $byPath['/srv/www/example.com']->owner);
		self::assertSame(0755, $byPath['/srv/www/example.com']->mode);
	}

	public function testWebDirStaysGroupWritableWithoutPhp(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, false);
		foreach ($this->layout->directories($v) as $spec) {
			if ($spec->path === '/srv/www/example.com/web') {
				self::assertSame('max', $spec->owner);
				self::assertSame(02775, $spec->mode);
				return;
			}
		}
		self::fail('web/ nicht in den Verzeichnissen');
	}
	public function testSnippetScopeCarriesTheVhostBoundaries(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$scope = $this->layout->snippetScope($v);
		self::assertSame('/srv/www/example.com', $scope->baseDir);
		self::assertSame('/srv/www/example.com/conf', $scope->confDir);
		self::assertSame('/srv/www/example.com/logs', $scope->logsDir);
		self::assertSame('/run/php/vhost-example.com.sock', $scope->phpSocket);
	}

	/**
	 * Ohne PHP gibt es keinen erlaubten Socket – sonst liesse sich fastcgi_pass auf
	 * einen Socket richten, den dieser Host gar nicht hat.
	 */
	public function testSnippetScopeHasNoSocketWithoutPhp(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, false);
		self::assertNull($this->layout->snippetScope($v)->phpSocket);
	}
	/**
	 * logs/ gehört root (nginx schreibt dort als root). Mit PHP muss der eigene
	 * Benutzer des Hosts trotzdem an seine php.log herankommen – dazu braucht er das
	 * Durchgangsrecht auf dem Ordner. Lesen kann er die übrigen Logs damit nicht,
	 * die stehen auf 0640 root:<owner>.
	 */
	public function testLogsDirIsTraversableForThePhpUser(): void
	{
		$withPhp = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$withoutPhp = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, false);
		self::assertSame(0751, $this->modeOf($withPhp, '/srv/www/example.com/logs'));
		self::assertSame(0750, $this->modeOf($withoutPhp, '/srv/www/example.com/logs'));
	}

	private function modeOf(Vhost $vhost, string $path): int
	{
		foreach ($this->layout->directories($vhost) as $spec) {
			if ($spec->path === $path) {
				return $spec->mode;
			}
		}
		self::fail("Verzeichnis $path nicht in den Soll-Rechten");
	}
}
