<?php
declare(strict_types=1);

/**
 * Tests der Migration alter vHost-Verzeichnisse auf die neue Struktur.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:32
 */

namespace Tests\Migration;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Migration\LayoutMigrator;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostLayout;

final class LayoutMigratorTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private VhostLayout $layout;
	private LayoutMigrator $migrator;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/www');
		mkdir($this->dir . '/nginxlogs');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'wwwOwner' => 'user',
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->layout = new VhostLayout($this->config);
		$this->migrator = new LayoutMigrator($this->config, $this->repo, $this->layout, $this->dir . '/nginxlogs');
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testPendingListsOnlyOldHosts(): void
	{
		$alt = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$neu = $this->repo->insert('neu.example', VhostKind::Domain, null, null, true);
		mkdir($this->dir . '/www/alt.example');
		mkdir($this->dir . '/www/neu.example/web', 0775, true);
		$names = array_map(static fn($v) => $v->name, $this->migrator->pending());
		self::assertSame(['alt.example'], $names);
	}

	public function testMigrateMovesContentIntoWebAndCreatesFolders(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, 'www/src', true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base . '/www/src', 0775, true);
		file_put_contents($base . '/www/src/index.html', 'Inhalt');
		file_put_contents($base . '/.htaccess-Rest', 'egal');
		file_put_contents($this->dir . '/nginxlogs/alt.example.access.log', "zugriff\n");
		file_put_contents($this->dir . '/nginxlogs/alt.example.error.log', "fehler\n");

		$this->migrator->migrate($v);

		self::assertSame('Inhalt', file_get_contents($base . '/web/www/src/index.html'));
		self::assertFileExists($base . '/web/.htaccess-Rest');
		self::assertDirectoryDoesNotExist($base . '/www');
		foreach (['web', 'conf', 'cert', 'private', 'logs'] as $sub) {
			self::assertDirectoryExists($base . '/' . $sub);
		}
		self::assertSame("zugriff\n", file_get_contents($base . '/logs/access.log'));
		self::assertSame("fehler\n", file_get_contents($base . '/logs/error.log'));
		self::assertFileDoesNotExist($this->dir . '/nginxlogs/alt.example.access.log');
		self::assertSame('0750', sprintf('%04o', (fileperms($base . '/conf') ?: 0) & 07777));
		self::assertSame('2775', sprintf('%04o', (fileperms($base . '/web') ?: 0) & 07777));
	}

	public function testMigrateIsIdempotent(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		file_put_contents($base . '/index.html', 'Inhalt');
		$this->migrator->migrate($v);
		self::assertSame([], $this->migrator->pending());
		$this->migrator->migrate($v);
		self::assertSame('Inhalt', file_get_contents($base . '/web/index.html'));
		self::assertFileDoesNotExist($base . '/web/web');
	}

	public function testMigrateSkipsMissingBaseDirectory(): void
	{
		$v = $this->repo->insert('fehlt.example', VhostKind::Domain, null, null, true);
		$this->migrator->migrate($v);
		self::assertDirectoryDoesNotExist($this->dir . '/www/fehlt.example');
	}

	public function testBackupCreatesArchiveContainingTheFiles(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		file_put_contents($base . '/index.html', 'Inhalt');
		$target = $this->dir . '/backups';
		mkdir($target);
		$archive = $this->migrator->backup([$v], $target);
		self::assertFileExists($archive);
		self::assertStringStartsWith($target . '/vhost-admin-migration-', $archive);
		exec('tar tzf ' . escapeshellarg($archive), $out, $code);
		self::assertSame(0, $code);
		self::assertNotEmpty(preg_grep('#alt\.example/index\.html$#', $out));
	}

	public function testBackupOfNothingThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->migrator->backup([], $this->dir . '/backups');
	}
}
