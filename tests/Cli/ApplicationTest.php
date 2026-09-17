<?php
declare(strict_types=1);

/**
 * Tests der Kommandozeile: Argument-Parsing und Befehle über Fakes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:20
 */

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCertbot;
use Tests\Support\FakeReloader;
use Tests\Support\TempDir;
use VhostAdmin\Cli\Application;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class ApplicationTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private VhostService $service;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/avail');
		mkdir($this->dir . '/enabled');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite', 'wwwRoot' => $this->dir . '/www',
			'sitesAvailable' => $this->dir . '/avail', 'sitesEnabled' => $this->dir . '/enabled',
			'authDir' => $this->dir . '/auth', 'letsEncryptLive' => $this->dir . '/le', 'ipv6' => false,
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->service = new VhostService($this->config, $this->repo, new ConfigRenderer($this->config), new FakeReloader(), new FakeCertbot($this->dir . '/le'));
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	/**
	 * Führt das CLI mit den Argumenten aus und liefert [Exit-Code, stdout, stderr].
	 *
	 * Name "runCli", weil PHPUnit\Framework\TestCase::run() final ist.
	 *
	 * @param list<string> $args
	 * @return array{int, string, string}
	 */
	private function runCli(array $args, string $stdin = ''): array
	{
		$in = fopen('php://memory', 'w+');
		fwrite($in, $stdin);
		rewind($in);
		$out = fopen('php://memory', 'w+');
		$err = fopen('php://memory', 'w+');
		$app = new Application($this->service, $this->repo, $this->config, $in, $out, $err);
		$code = $app->run(array_merge(['vhost'], $args));
		rewind($out);
		rewind($err);
		return [$code, (string)stream_get_contents($out), (string)stream_get_contents($err)];
	}

	public function testParseSeparatesCommandPositionalsAndOptions(): void
	{
		$parsed = Application::parse(['vhost', 'add', 'a.de', '--subdir', 'pub', '--no-protect', '--x=1']);
		self::assertSame('add', $parsed['command']);
		self::assertSame(['a.de'], $parsed['positional']);
		self::assertSame(['subdir' => 'pub', 'no-protect' => true, 'x' => '1'], $parsed['options']);
		self::assertSame('help', Application::parse(['vhost'])['command']);
		self::assertSame(['subdir' => 'x'], Application::parse(['vhost', 'add', '--subdir=x'])['options']);
		self::assertSame(['subdir' => ''], Application::parse(['vhost', 'add', '--subdir'])['options']);
	}

	public function testHelpAndUnknownCommand(): void
	{
		[$code, $out] = $this->runCli(['help']);
		self::assertSame(0, $code);
		self::assertStringContainsString('vhost add <domain>', $out);
		[$code, , $err] = $this->runCli(['gibtsnicht']);
		self::assertSame(2, $code);
		self::assertStringContainsString('vhost add <domain>', $err);
	}

	public function testMissingArgumentIsExitOne(): void
	{
		[$code, , $err] = $this->runCli(['add']);
		self::assertSame(1, $code);
		self::assertSame("Fehler: Domain fehlt\n", $err);
	}

	public function testAddListAndRemove(): void
	{
		[$code, $out] = $this->runCli(['add', 'Test.example', '--subdir', 'public']);
		self::assertSame(0, $code);
		self::assertSame("Angelegt: test.example -> {$this->dir}/www/test.example/public\n", $out);
		self::assertDirectoryExists($this->dir . '/www/test.example/public');

		[$code, $out] = $this->runCli(['add-local', '3000', '--no-protect']);
		self::assertSame(0, $code);
		self::assertStringContainsString('localhost:3000', $out);

		[, $out] = $this->runCli(['list']);
		self::assertStringContainsString('test.example', $out);
		self::assertStringContainsString('schutz:an', $out);
		self::assertStringContainsString('localhost:3000', $out);
		self::assertStringContainsString('schutz:aus', $out);

		[$code] = $this->runCli(['remove', 'test.example', '--purge']);
		self::assertSame(0, $code);
		self::assertDirectoryDoesNotExist($this->dir . '/www/test.example');
		[$code, , $err] = $this->runCli(['remove', 'test.example']);
		self::assertSame(1, $code);
		self::assertStringContainsString('Unbekannter vHost', $err);
	}

	public function testInvalidDomainIsExitOne(): void
	{
		[$code, , $err] = $this->runCli(['add', 'localhost:3000']);
		self::assertSame(1, $code);
		self::assertStringContainsString('add-local', $err);
	}

	public function testUserPasswordComesFromStdin(): void
	{
		$this->runCli(['add', 'a.example']);
		[$code, $out] = $this->runCli(['user-add', 'a.example', 'alice'], "geheim\n");
		self::assertSame(0, $code);
		self::assertSame("Benutzer alice gespeichert.\n", $out);
		self::assertStringStartsWith('alice:$6$', (string)file_get_contents($this->dir . '/auth/a.example.htpasswd'));
		[$code, , $err] = $this->runCli(['user-add', 'a.example', 'bob'], "\n");
		self::assertSame(1, $code);
		self::assertStringContainsString('Leeres Passwort', $err);
		[$code] = $this->runCli(['user-del', 'a.example', 'alice']);
		self::assertSame(0, $code);
		self::assertSame('', file_get_contents($this->dir . '/auth/a.example.htpasswd'));
	}

	public function testProtectIpsAndSsl(): void
	{
		$this->runCli(['add', 'a.example']);
		[$code, $out] = $this->runCli(['protect', 'a.example', 'off']);
		self::assertSame(0, $code);
		self::assertSame("Verzeichnisschutz deaktiviert.\n", $out);
		[$code, , $err] = $this->runCli(['protect', 'a.example', 'maybe']);
		self::assertSame(1, $code);
		self::assertStringContainsString('on|off', $err);

		[$code, $out] = $this->runCli(['ip-add', 'a.example', '10.0.0.0/08']);
		self::assertSame(0, $code);
		self::assertSame("IP 10.0.0.0/8 freigegeben.\n", $out);
		[$code] = $this->runCli(['ip-del', 'a.example', '10.0.0.0/8']);
		self::assertSame(0, $code);

		[$code, , $err] = $this->runCli(['ssl', 'a.example', 'on']);
		self::assertSame(1, $code);
		self::assertStringContainsString('E-Mail', $err);
		[$code] = $this->runCli(['set', 'le_email', 'admin@example.com']);
		self::assertSame(0, $code);
		[$code, $out] = $this->runCli(['ssl', 'a.example', 'on']);
		self::assertSame(0, $code);
		self::assertStringContainsString('Simuliertes Zertifikat', $out);
		self::assertStringContainsString("Let's Encrypt aktiviert.", $out);
		[$code, , $err] = $this->runCli(['set', 'unbekannt', 'x']);
		self::assertSame(1, $code);
		self::assertStringContainsString('Unbekannte Einstellung', $err);
	}

	public function testRenderAndInit(): void
	{
		$this->runCli(['add', 'a.example']);
		unlink($this->dir . '/avail/a.example.conf');
		[$code, $out] = $this->runCli(['render']);
		self::assertSame(0, $code);
		self::assertStringContainsString('neu geschrieben', $out);
		self::assertFileExists($this->dir . '/avail/a.example.conf');
		[$code, $out] = $this->runCli(['init']);
		self::assertSame(0, $code);
		self::assertSame("Datenbank bereit.\n", $out);
	}
}
