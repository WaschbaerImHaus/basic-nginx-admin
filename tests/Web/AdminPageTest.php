<?php
declare(strict_types=1);

/**
 * Tests der Web-Logik: Aktionen → CLI-Argumente, Flash, Redirects, CSRF.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 18:20
 */

namespace Tests\Web;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostRepository;
use VhostAdmin\Web\AdminPage;
use VhostAdmin\Web\CommandRunner;

final class AdminPageTest extends TestCase
{
	private string $dir;
	private array $session = [];
	private VhostRepository $repo;
	private AdminPage $page;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'vhostBinary' => dirname(__DIR__) . '/Support/echo-command.php',
		]);
		$db = new Database($config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->session = [];
		$this->page = new AdminPage($this->repo, new CommandRunner($config, ['php']), $config, $this->session);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testCommandRunnerPassesArgsAndStdin(): void
	{
		$runner = new CommandRunner(Config::fromArray(['vhostBinary' => dirname(__DIR__) . '/Support/echo-command.php']), ['php']);
		[$code, $out] = $runner->run(['add', 'a b'], "geheim\n");
		self::assertSame(0, $code);
		self::assertSame("ARGS=add|a b\nSTDIN=geheim", $out);
		[$code, $out] = $runner->run(['fail']);
		self::assertSame(3, $code);
		self::assertStringContainsString('Fehler: Simulation', $out);
	}

	public function testCommandForMapsEveryAction(): void
	{
		self::assertSame(['args' => ['add', 'a.de'], 'stdin' => null], AdminPage::commandFor('create', ['domain' => ' a.de ', 'subdir' => '']));
		self::assertSame(['args' => ['add', 'a.de', '--subdir', 'pub'], 'stdin' => null], AdminPage::commandFor('create', ['domain' => 'a.de', 'subdir' => 'pub']));
		self::assertSame(['args' => ['protect', 'a.de', 'off'], 'stdin' => null], AdminPage::commandFor('protect', ['name' => 'a.de', 'state' => 'off']));
		self::assertSame(['args' => ['user-add', 'a.de', 'alice'], 'stdin' => "p w\n"], AdminPage::commandFor('user_add', ['name' => 'a.de', 'username' => 'alice', 'password' => 'p w']));
		self::assertSame(['args' => ['user-del', 'a.de', 'alice'], 'stdin' => null], AdminPage::commandFor('user_del', ['name' => 'a.de', 'username' => 'alice']));
		self::assertSame(['args' => ['ip-add', 'a.de', '10.0.0.0/8'], 'stdin' => null], AdminPage::commandFor('ip_add', ['name' => 'a.de', 'cidr' => '10.0.0.0/8']));
		self::assertSame(['args' => ['ip-del', 'a.de', '10.0.0.0/8'], 'stdin' => null], AdminPage::commandFor('ip_del', ['name' => 'a.de', 'cidr' => '10.0.0.0/8']));
		self::assertSame(['args' => ['ssl', 'a.de', 'on'], 'stdin' => null], AdminPage::commandFor('ssl', ['name' => 'a.de', 'state' => 'on']));
		self::assertSame(['args' => ['remove', 'a.de'], 'stdin' => null], AdminPage::commandFor('remove', ['name' => 'a.de']));
		self::assertSame(['args' => ['set', 'le_email', 'x@y.de'], 'stdin' => null], AdminPage::commandFor('email', ['le_email' => 'x@y.de']));
		self::assertNull(AdminPage::commandFor('hack', []));
		self::assertNull(AdminPage::commandFor('', []));
	}

	/**
	 * Befund 8: das "name"-Feld landet ungefiltert als Positionsargument; ein Wert wie
	 * "--purge" erzeugt deshalb ["remove", "--purge"], nicht ["remove", "--purge", ...].
	 * Das CLI selbst fängt das ab (Application::parse() liest "--purge" als Option, nicht
	 * als Name), siehe ApplicationTest::testPurgeAsNameIsNotTreatedAsPositionalArgument().
	 */
	public function testCommandForPassesPurgeLikeNameThrough(): void
	{
		self::assertSame(['args' => ['remove', '--purge'], 'stdin' => null], AdminPage::commandFor('remove', ['name' => '--purge']));
	}

	public function testRedirectTargets(): void
	{
		self::assertSame('/?v=a.de', AdminPage::redirectTarget('create', 0, ['domain' => 'A.DE']));
		self::assertSame('/', AdminPage::redirectTarget('create', 1, ['domain' => 'A.DE']));
		self::assertSame('/', AdminPage::redirectTarget('remove', 0, ['name' => 'a.de']));
		self::assertSame('/', AdminPage::redirectTarget('email', 0, []));
		self::assertSame('/?v=localhost%3A3000', AdminPage::redirectTarget('protect', 0, ['name' => 'localhost:3000']));
	}

	public function testHandlePostRunsCommandAndSetsFlash(): void
	{
		$target = $this->page->handlePost(['action' => 'protect', 'name' => 'a.de', 'state' => 'on']);
		self::assertSame('/?v=a.de', $target);
		self::assertSame(['ok', "ARGS=protect|a.de|on\nSTDIN="], $this->page->takeFlash());
		self::assertNull($this->page->takeFlash());

		$this->page->handlePost(['action' => 'remove', 'name' => 'fail']);
		[$type, $text] = $this->page->takeFlash();
		self::assertSame('err', $type);
		self::assertStringContainsString('Simulation', $text);

		self::assertSame('/', $this->page->handlePost(['action' => 'hack']));
		self::assertNull($this->page->takeFlash());
	}

	public function testCsrfTokenIsStableAndValidated(): void
	{
		$token = $this->page->csrfToken();
		self::assertSame(32, strlen($token));
		self::assertSame($token, $this->page->csrfToken());
		self::assertSame($token, $this->session['csrf']);
		self::assertTrue($this->page->isValidCsrf($token));
		self::assertFalse($this->page->isValidCsrf('x'));
		self::assertFalse($this->page->isValidCsrf(''));
	}

	public function testReadAccessorsUseRepository(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->repo->upsertUser($v->id, 'alice', 'h');
		$this->repo->addIp($v->id, '127.0.0.1');
		$this->repo->setSetting('le_email', 'x@y.de');
		self::assertSame('a.de', $this->page->vhosts()[0]->name);
		self::assertSame($v->id, $this->page->vhost('a.de')?->id);
		self::assertNull($this->page->vhost('nix'));
		self::assertSame([['username' => 'alice', 'hash' => 'h']], $this->page->users($v));
		self::assertSame(['127.0.0.1'], $this->page->ips($v));
		self::assertSame('x@y.de', $this->page->letsEncryptEmail());
	}
}
