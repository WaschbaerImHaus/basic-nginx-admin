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
}
