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

	/**
	 * Seit 2026-09-27 gilt der Schutzpfad je Benutzer (Nutzerwunsch). Ein bisher für die
	 * Domain gesetzter Pfad geht auf alle ihre Benutzer über; die Domain-Spalte bleibt
	 * danach leer, ein zweiter Lauf ändert nichts mehr.
	 */
	public function testMovesTheDomainPathToItsUsers(): void
	{
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/alt.sqlite']));
		$db->pdo()->exec(
			'CREATE TABLE vhosts (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, '
			. "kind TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')), port INTEGER, "
			. 'subdir TEXT, protect INTEGER NOT NULL DEFAULT 1, protect_path TEXT, ssl INTEGER NOT NULL DEFAULT 0, '
			. "created_at TEXT NOT NULL DEFAULT (datetime('now')))"
		);
		$db->pdo()->exec('CREATE TABLE auth_users (id INTEGER PRIMARY KEY, vhost_id INTEGER NOT NULL, username TEXT NOT NULL, '
			. 'hash TEXT NOT NULL, UNIQUE (vhost_id, username))');
		$db->pdo()->exec("INSERT INTO vhosts (id, name, kind, protect_path) VALUES (1, 'a.de', 'domain', '/admin'), (2, 'b.de', 'domain', NULL)");
		$db->pdo()->exec("INSERT INTO auth_users (vhost_id, username, hash) VALUES (1, 'x', 'h'), (1, 'y', 'h'), (2, 'z', 'h')");

		$db->initSchema();
		$db->initSchema();

		$paths = $db->pdo()->query('SELECT username, path FROM auth_users ORDER BY username')->fetchAll(\PDO::FETCH_KEY_PAIR);
		self::assertSame(['x' => '/admin', 'y' => '/admin', 'z' => null], $paths);
		self::assertSame([null, null], array_column($db->pdo()->query('SELECT protect_path FROM vhosts ORDER BY id')->fetchAll(), 'protect_path'));
	}
}
