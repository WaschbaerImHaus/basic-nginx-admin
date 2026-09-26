<?php
declare(strict_types=1);

/**
 * Tests der Rotationserkennung beim Lesen der Logs.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-26 21:45
 */

namespace Tests\Honeypot;

use Honeypot\LogFiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class LogFilesTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		file_put_contents($this->dir . '/access.log', "a\n");
		file_put_contents($this->dir . '/access.log.1', "b\n");
		file_put_contents($this->dir . '/error.log', "c\n");
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	/** Neue Zeilen sind der Normalfall – nginx schreibt ständig weiter. */
	public function testAppendingIsNoRotation(): void
	{
		$before = LogFiles::fingerprint($this->dir);
		file_put_contents($this->dir . '/access.log', "neu\n", FILE_APPEND);
		file_put_contents($this->dir . '/error.log', "neu\n", FILE_APPEND);
		self::assertSame($before, LogFiles::fingerprint($this->dir));
	}

	/** logrotate benennt um: Jede Fassung ist danach eine andere Datei. */
	public function testRenamingIsARotation(): void
	{
		$before = LogFiles::fingerprint($this->dir);
		rename($this->dir . '/access.log.1', $this->dir . '/access.log.2');
		rename($this->dir . '/access.log', $this->dir . '/access.log.1');
		file_put_contents($this->dir . '/access.log', '');
		self::assertNotSame($before, LogFiles::fingerprint($this->dir));
	}

	/** Auch halb durchgeführt: erst eine Datei weitergeschoben, der Rest noch nicht. */
	public function testAHalfDoneRotationIsARotation(): void
	{
		$before = LogFiles::fingerprint($this->dir);
		rename($this->dir . '/access.log.1', $this->dir . '/access.log.2');
		self::assertNotSame($before, LogFiles::fingerprint($this->dir));
	}

	/** Das Fehlerlog rotiert getrennt und zählt genauso. */
	public function testTheErrorLogCounts(): void
	{
		$before = LogFiles::fingerprint($this->dir);
		rename($this->dir . '/error.log', $this->dir . '/error.log.1');
		self::assertNotSame($before, LogFiles::fingerprint($this->dir));
	}

	/** Komprimieren einer älteren Fassung (access.log.2 -> .2.gz) ist auch eine Änderung. */
	public function testCompressionIsARotation(): void
	{
		file_put_contents($this->dir . '/access.log.2', "x\n");
		$before = LogFiles::fingerprint($this->dir);
		file_put_contents($this->dir . '/access.log.2.gz', gzencode("x\n"));
		unlink($this->dir . '/access.log.2');
		self::assertNotSame($before, LogFiles::fingerprint($this->dir));
	}

	/** Andere Dateien im Ordner stören nicht. */
	public function testIgnoresOtherFiles(): void
	{
		$before = LogFiles::fingerprint($this->dir);
		file_put_contents($this->dir . '/notiz.txt', 'x');
		self::assertSame($before, LogFiles::fingerprint($this->dir));
	}

	/** Ein fehlender Ordner hat einen festen, leeren Fingerabdruck. */
	public function testAMissingDirectory(): void
	{
		self::assertSame(LogFiles::fingerprint($this->dir . '/fehlt'), LogFiles::fingerprint($this->dir . '/fehlt'));
	}
}
