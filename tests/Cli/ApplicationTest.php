<?php
declare(strict_types=1);

/**
 * Tests der Kommandozeile: Argument-Parsing und Befehle über Fakes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 15:21
 */

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCertbot;
use Tests\Support\FakeFpmReloader;
use Tests\Support\FakeReachability;
use Tests\Support\FakeSystemUsers;
use Tests\Support\FakeReloader;
use Tests\Support\TempDir;
use VhostAdmin\Cli\Application;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Migration\LayoutMigrator;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Php\PoolRenderer;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class ApplicationTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private VhostLayout $layout;
	private VhostService $service;
	private FakeReachability $reachability;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/avail');
		mkdir($this->dir . '/enabled');
		mkdir($this->dir . '/nginxlogs');
		mkdir($this->dir . '/pool.d');
		mkdir($this->dir . '/run');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite', 'wwwRoot' => $this->dir . '/www',
			'sitesAvailable' => $this->dir . '/avail', 'sitesEnabled' => $this->dir . '/enabled',
			'authDir' => $this->dir . '/auth', 'letsEncryptLive' => $this->dir . '/le', 'ipv6' => false,
			'backupDir' => $this->dir . '/backups',
			// Pool und Socket ins Temporärverzeichnis (nicht ins echte /etc/php).
			'fpmPoolDir' => $this->dir . '/pool.d', 'fpmSocketDir' => $this->dir . '/run',
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->layout = new VhostLayout($this->config);
		$this->reachability = new FakeReachability();
		$this->service = new VhostService(
			$this->config, $this->repo, new ConfigRenderer($this->config, $this->layout),
			new FakeReloader(), new FakeCertbot($this->dir . '/le'), $this->layout,
			new PoolRenderer($this->layout), new FakeFpmReloader(), new FakeSystemUsers(),
			new ReachabilityChecker($this->reachability)
		);
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
	 * @param string|null $renewalDir Überschreibt das Renewal-Verzeichnis des
	 *        Migrators (Befund 2, Re-Review 2026-09-20); null lässt den Default.
	 * @return array{int, string, string}
	 */
	private function runCli(array $args, string $stdin = '', ?string $renewalDir = null): array
	{
		$in = fopen('php://memory', 'w+');
		fwrite($in, $stdin);
		rewind($in);
		$out = fopen('php://memory', 'w+');
		$err = fopen('php://memory', 'w+');
		$migrator = $renewalDir !== null
			? new LayoutMigrator($this->config, $this->repo, $this->layout, $this->dir . '/nginxlogs', $renewalDir)
			: new LayoutMigrator($this->config, $this->repo, $this->layout, $this->dir . '/nginxlogs');
		$app = new Application(
			$this->service, $this->repo, $this->config, $this->layout,
			$migrator,
			$in, $out, $err
		);
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
		self::assertSame("Angelegt: test.example -> {$this->dir}/www/test.example/web/public\n", $out);
		self::assertDirectoryExists($this->dir . '/www/test.example/web/public');

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

	/**
	 * Befund 5: die sudoers-Regel erlaubt www-data beliebige Argumente, "--purge" soll
	 * die Oberfläche trotzdem nie auslösen können. Erkennung über SUDO_USER (sudo setzt
	 * das beim Aufruf durch die Oberfläche).
	 */
	public function testPurgeIsRejectedWhenSudoUserIsWwwData(): void
	{
		$this->runCli(['add', 'a.example']);
		$original = getenv('SUDO_USER');
		putenv('SUDO_USER=www-data');
		try {
			[$code, , $err] = $this->runCli(['remove', 'a.example', '--purge']);
		} finally {
			$original === false ? putenv('SUDO_USER') : putenv("SUDO_USER=$original");
		}
		self::assertSame(1, $code);
		self::assertStringContainsString('--purge ist aus der Oberfläche nicht erlaubt', $err);
		self::assertDirectoryExists($this->dir . '/www/a.example');
		[, $out] = $this->runCli(['list']);
		self::assertStringContainsString('a.example', $out);
	}

	/**
	 * Ohne SUDO_USER=www-data (z.B. ein root-Aufruf per direktem sudo) bleibt "--purge"
	 * erlaubt – die Sperre gilt nur für den Weg über die Oberfläche.
	 */
	public function testPurgeStillWorksWithoutSudoUserWwwData(): void
	{
		$this->runCli(['add', 'a.example']);
		$original = getenv('SUDO_USER');
		putenv('SUDO_USER=der-owner');
		try {
			[$code] = $this->runCli(['remove', 'a.example', '--purge']);
		} finally {
			$original === false ? putenv('SUDO_USER') : putenv("SUDO_USER=$original");
		}
		self::assertSame(0, $code);
		self::assertDirectoryDoesNotExist($this->dir . '/www/a.example');
	}

	/**
	 * Befund 8: AdminPage::commandFor('remove', ['name' => '--purge']) erzeugt
	 * ["remove", "--purge"] (siehe AdminPageTest::testCommandForPassesPurgeLikeNameThrough()).
	 * Application::parse() liest "--purge" dabei als Option, nicht als Positionsargument,
	 * daher fehlt der Name und "remove" schlägt fehl, statt irgendetwas zu löschen.
	 */
	public function testPurgeAsNameIsNotTreatedAsPositionalArgument(): void
	{
		$this->runCli(['add', 'a.example']);
		[$code, , $err] = $this->runCli(['remove', '--purge']);
		self::assertSame(1, $code);
		self::assertStringContainsString('Name fehlt', $err);
		self::assertDirectoryExists($this->dir . '/www/a.example');
	}

	public function testConfReadsSnippetFromStdin(): void
	{
		$this->runCli(['add', 'a.example']);
		[$code, $out] = $this->runCli(['conf', 'a.example'], "expires 1d;\n");
		self::assertSame(0, $code);
		self::assertSame("Konfiguration übernommen.\n", $out);
		self::assertSame("expires 1d;\n", file_get_contents($this->dir . '/www/a.example/conf/custom.conf'));
	}

	public function testConfRejectsForbiddenDirectiveWithLineNumber(): void
	{
		$this->runCli(['add', 'a.example']);
		[$code, , $err] = $this->runCli(['conf', 'a.example'], "expires 1d;\nroot /etc;\n");
		self::assertSame(1, $code);
		self::assertStringContainsString('Zeile 2', $err);
		self::assertStringContainsString('root', $err);
		self::assertFileDoesNotExist($this->dir . '/www/a.example/conf/custom.conf');
	}

	public function testConfWithEmptyInputRemovesSnippet(): void
	{
		$this->runCli(['add', 'a.example']);
		$this->runCli(['conf', 'a.example'], "expires 1d;\n");
		[$code, $out] = $this->runCli(['conf', 'a.example'], '');
		self::assertSame(0, $code);
		self::assertStringContainsString('entfernt', $out);
		self::assertFileDoesNotExist($this->dir . '/www/a.example/conf/custom.conf');
	}

	public function testFixPermissionsForOneAndAllHosts(): void
	{
		$this->runCli(['add', 'a.example']);
		chmod($this->dir . '/www/a.example/conf', 0777);
		[$code, $out] = $this->runCli(['fix-permissions', 'a.example']);
		self::assertSame(0, $code);
		self::assertStringContainsString('a.example', $out);
		self::assertSame('0750', sprintf('%04o', (fileperms($this->dir . '/www/a.example/conf') ?: 0) & 07777));
		[$code, $out] = $this->runCli(['fix-permissions']);
		self::assertSame(0, $code);
		self::assertStringContainsString('1', $out);
	}

	public function testMigrateLayoutMovesOldHostAndIsIdempotent(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base, 0775, true);
		file_put_contents($base . '/index.html', 'Inhalt');
		[$code, $out] = $this->runCli(['migrate-layout']);
		self::assertSame(0, $code);
		self::assertStringContainsString('alt.example', $out);
		self::assertStringContainsString('Sicherung', $out);
		self::assertSame('Inhalt', file_get_contents($base . '/web/index.html'));
		[$code, $out] = $this->runCli(['migrate-layout']);
		self::assertSame(0, $code);
		self::assertStringContainsString('Nichts zu migrieren', $out);
	}

	/**
	 * Befund 2 (Re-Review 2026-09-20): migrate-layout rief migrate() bisher nur für
	 * pending() auf, und pending() enthält nur Hosts, deren web/ noch fehlt. Ein
	 * bereits migrierter Host (web/ existiert schon) bekam den certbot-Renewal-
	 * Nachzug dadurch NIE – auch wenn migrate-layout beliebig oft erneut läuft.
	 */
	public function testMigrateLayoutFixesRenewalConfigForAlreadyMigratedHost(): void
	{
		$this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base . '/web', 0775, true);
		$renewalDir = $this->dir . '/renewal';
		mkdir($renewalDir);
		file_put_contents($renewalDir . '/alt.example.conf', "webroot_path = $base,\n");

		[$code, $out] = $this->runCli(['migrate-layout'], '', $renewalDir);

		self::assertSame(0, $code);
		self::assertStringNotContainsString('Nichts zu migrieren', $out);
		self::assertStringContainsString('1', $out);
		self::assertStringContainsString(
			"webroot_path = $base/web,",
			(string)file_get_contents($renewalDir . '/alt.example.conf')
		);
	}

	public function testMigrateLayoutReportsNothingToDoWhenBothStepsAreEmpty(): void
	{
		$renewalDir = $this->dir . '/renewal';
		mkdir($renewalDir);

		[$code, $out] = $this->runCli(['migrate-layout'], '', $renewalDir);

		self::assertSame(0, $code);
		self::assertStringContainsString('Nichts zu migrieren', $out);
	}

	public function testUsageListsNewCommands(): void
	{
		[, $out] = $this->runCli(['help']);
		foreach (['vhost conf <name>', 'vhost fix-permissions', 'vhost migrate-layout'] as $line) {
			self::assertStringContainsString($line, $out);
		}
	}
	public function testPhpCommandTogglesTheFlagAndReportsSocket(): void
	{
		$this->runCli(['add', 'php.example']);
		[$code, $out] = $this->runCli(['php', 'php.example', 'on']);
		self::assertSame(0, $code);
		self::assertStringContainsString('PHP aktiviert', $out);
		self::assertStringContainsString('vhost-php.example.sock', $out);
		self::assertTrue($this->repo->byName('php.example')->php);

		[$code, $out] = $this->runCli(['php', 'php.example', 'off']);
		self::assertSame(0, $code);
		self::assertStringContainsString('PHP deaktiviert', $out);
		self::assertFalse($this->repo->byName('php.example')->php);
	}

	public function testPhpCommandNeedsOnOrOff(): void
	{
		$this->runCli(['add', 'php.example']);
		[$code] = $this->runCli(['php', 'php.example', 'vielleicht']);
		self::assertSame(1, $code);
		self::assertFalse($this->repo->byName('php.example')->php);
	}
}
