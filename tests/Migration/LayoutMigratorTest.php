<?php
declare(strict_types=1);

/**
 * Tests der Migration alter vHost-Verzeichnisse auf die neue Struktur.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:45
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

	public function testMigrateAbortsOnCollisionAndKeepsAllContentSafe(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		// Zustand nach einem früheren Fehlschlag: der Zwischenordner enthält schon eine
		// Datei, und im Basisordner ist – z. B. durch nginx – wieder eine gleichnamige
		// Datei entstanden. Das ist die Kollision, die migrate() erkennen muss.
		mkdir($base . '/.web-migrating');
		file_put_contents($base . '/.web-migrating/conflict.txt', 'ALT');
		file_put_contents($base . '/conflict.txt', 'NEU');

		try {
			$this->migrator->migrate($v);
			self::fail('Erwartete RuntimeException wegen Zielkollision blieb aus.');
		} catch (\RuntimeException) {
			// erwartet
		}

		$names = array_map(static fn($vh) => $vh->name, $this->migrator->pending());
		self::assertSame(['alt.example'], $names, 'Host gilt weiterhin als nicht migriert');
		self::assertDirectoryDoesNotExist($base . '/web');
		self::assertSame('NEU', file_get_contents($base . '/conflict.txt'), 'Basisordner-Inhalt bleibt erhalten');
		self::assertSame('ALT', file_get_contents($base . '/.web-migrating/conflict.txt'), 'Zwischenordner-Inhalt bleibt erhalten');
	}

	public function testMigrateThrowsOnCollisionWithoutOverwritingTheStagedFile(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		mkdir($base . '/.web-migrating');
		file_put_contents($base . '/.web-migrating/index.html', 'ALT-VERSION');
		file_put_contents($base . '/index.html', 'NEU-VERSION');

		try {
			$this->migrator->migrate($v);
			self::fail('Erwartete RuntimeException wegen Zielkollision blieb aus.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('index.html', $e->getMessage());
		}

		self::assertSame('ALT-VERSION', file_get_contents($base . '/.web-migrating/index.html'));
		self::assertSame('NEU-VERSION', file_get_contents($base . '/index.html'));
	}

	public function testMigrateContinuesAfterCollisionIsResolved(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		mkdir($base . '/.web-migrating');
		file_put_contents($base . '/.web-migrating/conflict.txt', 'ALT');
		file_put_contents($base . '/conflict.txt', 'NEU');
		file_put_contents($base . '/andere.txt', 'ANDERE');

		try {
			$this->migrator->migrate($v);
			self::fail('Erwartete RuntimeException wegen Zielkollision blieb aus.');
		} catch (\RuntimeException) {
			// erwartet – Ursache jetzt beseitigen: die neue Datei gewinnt.
		}
		unlink($base . '/.web-migrating/conflict.txt');

		$this->migrator->migrate($v);

		self::assertSame([], $this->migrator->pending());
		self::assertDirectoryDoesNotExist($base . '/.web-migrating');
		self::assertSame('NEU', file_get_contents($base . '/web/conflict.txt'));
		self::assertSame('ANDERE', file_get_contents($base . '/web/andere.txt'));
	}

	public function testMigrateMovesStructureNamedFolderFromOldFlatLayout(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		// Beim Erstlauf existiert die neue Struktur noch nicht: ein Ordner "conf" ist
		// dann zwingend Altinhalt und muss mit nach web/ wandern statt übersprungen zu werden.
		mkdir($base . '/conf', 0775, true);
		file_put_contents($base . '/conf/alte-datei.txt', 'Alt');

		$this->migrator->migrate($v);

		self::assertSame('Alt', file_get_contents($base . '/web/conf/alte-datei.txt'));
		self::assertDirectoryExists($base . '/conf');
	}

	public function testPendingIncludesHostWithWebSymlink(): void
	{
		$this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		$elsewhere = $this->dir . '/anderswo';
		mkdir($elsewhere);
		symlink($elsewhere, $base . '/web');

		$names = array_map(static fn($vh) => $vh->name, $this->migrator->pending());
		self::assertSame(['alt.example'], $names);
	}

	public function testMigrateThrowsOnWebSymlinkWithoutMovingAnything(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		$elsewhere = $this->dir . '/anderswo';
		mkdir($elsewhere);
		symlink($elsewhere, $base . '/web');
		file_put_contents($base . '/index.html', 'Inhalt');

		try {
			$this->migrator->migrate($v);
			self::fail('Erwartete RuntimeException wegen Symlink blieb aus.');
		} catch (\RuntimeException) {
			// erwartet
		}

		self::assertTrue(is_link($base . '/web'), 'Symlink bleibt unangetastet');
		self::assertSame('Inhalt', file_get_contents($base . '/index.html'), 'nichts wurde verschoben');
		self::assertDirectoryDoesNotExist($base . '/.web-migrating');
	}
}
