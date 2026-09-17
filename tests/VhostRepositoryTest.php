<?php
declare(strict_types=1);

/**
 * Tests für das Repository (temporäre SQLite-Datei).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:40
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostRepository;

final class VhostRepositoryTest extends TestCase
{
	private string $dir;
	private VhostRepository $repo;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$db = new Database(Config::fromArray(['dbPath' => $this->dir . '/test.sqlite']));
		$db->initSchema();
		$this->repo = new VhostRepository($db);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testInsertAndLoad(): void
	{
		$v = $this->repo->insert('example.com', VhostKind::Domain, null, 'public', true);
		self::assertNotNull($v->id);
		self::assertSame('example.com', $v->name);
		self::assertSame('public', $v->subdir);
		self::assertTrue($v->protect);
		self::assertFalse($v->ssl);
		self::assertNotNull($v->createdAt);
		self::assertSame($v->id, $this->repo->byName('example.com')?->id);
		self::assertSame($v->id, $this->repo->byId($v->id)?->id);
		self::assertNull($this->repo->byName('unknown.com'));
	}

	public function testInsertDuplicateThrows(): void
	{
		$this->repo->insert('example.com', VhostKind::Domain, null, null, true);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Existiert bereits');
		$this->repo->insert('example.com', VhostKind::Domain, null, null, true);
	}

	public function testAllIsSortedByKindThenName(): void
	{
		$this->repo->insert('localhost:3000', VhostKind::Localhost, 3000, null, true);
		$this->repo->insert('b.de', VhostKind::Domain, null, null, true);
		$this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		self::assertSame(['a.de', 'b.de', 'localhost:3000'], array_map(fn($v) => $v->name, $this->repo->all()));
	}

	public function testFlagsCanBeToggled(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->repo->setProtect($v->id, false);
		$this->repo->setSsl($v->id, true);
		$reloaded = $this->repo->byId($v->id);
		self::assertFalse($reloaded->protect);
		self::assertTrue($reloaded->ssl);
	}

	public function testUsersUpsertAndDelete(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		self::assertSame([], $this->repo->users($v->id));
		$this->repo->upsertUser($v->id, 'alice', 'hash1');
		$this->repo->upsertUser($v->id, 'alice', 'hash2');
		$this->repo->upsertUser($v->id, 'bob', 'hash3');
		self::assertSame(
			[['username' => 'alice', 'hash' => 'hash2'], ['username' => 'bob', 'hash' => 'hash3']],
			$this->repo->users($v->id)
		);
		self::assertTrue($this->repo->deleteUser($v->id, 'alice'));
		self::assertFalse($this->repo->deleteUser($v->id, 'alice'));
		self::assertSame([['username' => 'bob', 'hash' => 'hash3']], $this->repo->users($v->id));
	}

	public function testIpsIgnoreDuplicates(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->repo->addIp($v->id, '10.0.0.0/8');
		$this->repo->addIp($v->id, '10.0.0.0/8');
		$this->repo->addIp($v->id, '127.0.0.1');
		self::assertSame(['10.0.0.0/8', '127.0.0.1'], $this->repo->ips($v->id));
		self::assertTrue($this->repo->deleteIp($v->id, '127.0.0.1'));
		self::assertFalse($this->repo->deleteIp($v->id, '127.0.0.1'));
	}

	public function testDeleteCascadesToUsersAndIps(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->repo->upsertUser($v->id, 'alice', 'h');
		$this->repo->addIp($v->id, '127.0.0.1');
		$this->repo->delete($v->id);
		self::assertNull($this->repo->byName('a.de'));
		self::assertSame([], $this->repo->users($v->id));
		self::assertSame([], $this->repo->ips($v->id));
	}

	public function testSettings(): void
	{
		self::assertNull($this->repo->setting('le_email'));
		$this->repo->setSetting('le_email', 'a@b.de');
		self::assertSame('a@b.de', $this->repo->setting('le_email'));
		$this->repo->setSetting('le_email', 'c@d.de');
		self::assertSame('c@d.de', $this->repo->setting('le_email'));
		$this->repo->setSetting('le_email', '');
		self::assertNull($this->repo->setting('le_email'));
	}
}
