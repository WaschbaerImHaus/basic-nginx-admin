<?php
declare(strict_types=1);

/**
 * Tests für die Datenbankverbindung und das Schema.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;

final class DatabaseTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testInitSchemaCreatesAllTablesAndParentDirectory(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/sub/test.sqlite']));
		$db->initSchema();
		$tables = $db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame(['auth_ips', 'auth_users', 'settings', 'vhosts'], $tables);
		self::assertFileExists($this->dir . '/sub/test.sqlite');
	}

	public function testForeignKeysAreEnforced(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/test.sqlite']));
		$db->initSchema();
		$this->expectException(\PDOException::class);
		$db->pdo()->exec("INSERT INTO auth_users (vhost_id, username, hash) VALUES (999, 'a', 'b')");
	}

	public function testInitSchemaIsIdempotent(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/test.sqlite']));
		$db->initSchema();
		$db->initSchema();
		self::assertSame(0, (int)$db->pdo()->query('SELECT COUNT(*) FROM vhosts')->fetchColumn());
	}
	/**
	 * Eine Datenbank aus einer älteren Fassung hat die Spalte "php" noch nicht.
	 * initSchema() legt sie nach, statt an "CREATE TABLE IF NOT EXISTS" zu scheitern.
	 */
	public function testInitSchemaAddsPhpColumnToOlderDatabase(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/alt.sqlite']));
		// Schema in der alten Fassung anlegen: ohne Spalte "php".
		$db->pdo()->exec(
			'CREATE TABLE vhosts (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, '
			. "kind TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')), port INTEGER, "
			. 'subdir TEXT, protect INTEGER NOT NULL DEFAULT 1, ssl INTEGER NOT NULL DEFAULT 0, '
			. "created_at TEXT NOT NULL DEFAULT (datetime('now')))"
		);
		$db->pdo()->exec("INSERT INTO vhosts (name, kind) VALUES ('alt.de', 'domain')");

		$db->initSchema();

		$columns = array_column($db->pdo()->query('PRAGMA table_info(vhosts)')->fetchAll(), 'name');
		self::assertContains('php', $columns);
		$row = $db->pdo()->query("SELECT php FROM vhosts WHERE name = 'alt.de'")->fetch();
		self::assertSame(0, (int)$row['php'], 'Bestehende Hosts behalten PHP aus');
	}

	/**
	 * Bestandsdatenbanken bekommen die Spalte für den geschützten Pfad nachgereicht;
	 * bestehende Hosts bleiben dabei ohne Pfad, also für die ganze Seite geschützt.
	 */
	public function testInitSchemaAddsProtectPathColumnToOlderDatabase(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/alt.sqlite']));
		$db->pdo()->exec(
			'CREATE TABLE vhosts (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, '
			. "kind TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')), port INTEGER, "
			. 'subdir TEXT, protect INTEGER NOT NULL DEFAULT 1, ssl INTEGER NOT NULL DEFAULT 0, '
			. "created_at TEXT NOT NULL DEFAULT (datetime('now')))"
		);
		$db->pdo()->exec("INSERT INTO vhosts (name, kind) VALUES ('alt.de', 'domain')");

		$db->initSchema();

		$columns = array_column($db->pdo()->query('PRAGMA table_info(vhosts)')->fetchAll(), 'name');
		self::assertContains('protect_path', $columns);
		$row = $db->pdo()->query("SELECT protect_path FROM vhosts WHERE name = 'alt.de'")->fetch();
		self::assertNull($row['protect_path'], 'bestehende Hosts bleiben ganz geschützt');
	}
}
