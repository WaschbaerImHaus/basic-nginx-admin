<?php
declare(strict_types=1);

/**
 * Tests der Web-Logik: Aktionen → CLI-Argumente, Flash, Redirects, CSRF, Snippet.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 19:13
 */

namespace Tests\Web;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\Web\AdminPage;
use Tests\Support\FakeReachability;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\Web\CommandRunner;

final class AdminPageTest extends TestCase
{
	private string $dir;
	private array $session = [];
	private FakeReachability $reachability;
	private VhostRepository $repo;
	private AdminPage $page;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/www');
		$config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'vhostBinary' => dirname(__DIR__) . '/Support/echo-command.php',
		]);
		$db = new Database($config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->reachability = new FakeReachability();
		$this->session = [];
		$this->page = new AdminPage($this->repo, new CommandRunner($config, ['php']), $config, new VhostLayout($config), new ReachabilityChecker($this->reachability), $this->session);
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

	/**
	 * Jeder Wert aus dem Formular steht hinter "--": Das CLI liest ihn dann nie als
	 * Option, auch wenn er mit "--" beginnt (SECURITY_RISKS.md, Optionen-Einschleusung).
	 * Beabsichtigte Optionen wie --subdir stehen davor.
	 */
	public function testCommandForMapsEveryAction(): void
	{
		self::assertSame(['args' => ['add', '--', 'a.de'], 'stdin' => null], AdminPage::commandFor('create', ['domain' => ' a.de ', 'subdir' => '']));
		self::assertSame(['args' => ['add', '--subdir', 'pub', '--', 'a.de'], 'stdin' => null], AdminPage::commandFor('create', ['domain' => 'a.de', 'subdir' => 'pub']));
		self::assertSame(['args' => ['protect', '--', 'a.de', 'off'], 'stdin' => null], AdminPage::commandFor('protect', ['name' => 'a.de', 'state' => 'off']));
		self::assertSame(['args' => ['user-add', '--', 'a.de', 'alice'], 'stdin' => "p w\n"], AdminPage::commandFor('user_add', ['name' => 'a.de', 'username' => 'alice', 'password' => 'p w']));
		self::assertSame(['args' => ['user-del', '--', 'a.de', 'alice'], 'stdin' => null], AdminPage::commandFor('user_del', ['name' => 'a.de', 'username' => 'alice']));
		self::assertSame(['args' => ['ip-add', '--', 'a.de', '10.0.0.0/8'], 'stdin' => null], AdminPage::commandFor('ip_add', ['name' => 'a.de', 'cidr' => '10.0.0.0/8']));
		self::assertSame(['args' => ['ip-del', '--', 'a.de', '10.0.0.0/8'], 'stdin' => null], AdminPage::commandFor('ip_del', ['name' => 'a.de', 'cidr' => '10.0.0.0/8']));
		self::assertSame(['args' => ['ssl', '--', 'a.de', 'on'], 'stdin' => null], AdminPage::commandFor('ssl', ['name' => 'a.de', 'state' => 'on']));
		self::assertSame(['args' => ['remove', '--', 'a.de'], 'stdin' => null], AdminPage::commandFor('remove', ['name' => 'a.de']));
		self::assertSame(['args' => ['set', '--', 'le_email', 'x@y.de'], 'stdin' => null], AdminPage::commandFor('email', ['le_email' => 'x@y.de']));
		self::assertNull(AdminPage::commandFor('hack', []));
		self::assertNull(AdminPage::commandFor('', []));
	}

	/**
	 * Durchgespielt bis zum Parser: Ein Formularwert "--purge" kommt beim CLI als
	 * Argument an, nicht als Option – remove löscht damit nie Dateien.
	 */
	public function testAFormValueThatLooksLikeAnOptionStaysAnArgument(): void
	{
		$command = AdminPage::commandFor('remove', ['name' => '--purge']);
		$parsed = \VhostAdmin\Cli\Application::parse(array_merge(['vhost'], $command['args']));
		self::assertSame([], $parsed['options']);
		self::assertSame(['--purge'], $parsed['positional']);
	}

	public function testCommandForConfPassesTextOnStdin(): void
	{
		self::assertSame(
			['args' => ['conf', '--', 'a.de'], 'stdin' => "expires 1d;\n"],
			AdminPage::commandFor('conf', ['name' => 'a.de', 'snippet' => "expires 1d;\n"])
		);
		self::assertSame(
			['args' => ['conf', '--', 'a.de'], 'stdin' => ''],
			AdminPage::commandFor('conf', ['name' => 'a.de', 'snippet' => ''])
		);
	}

	public function testConfRedirectsToDetailPage(): void
	{
		self::assertSame('/?v=a.de', AdminPage::redirectTarget('conf', 0, ['name' => 'a.de']));
		self::assertSame('/?v=a.de', AdminPage::redirectTarget('conf', 1, ['name' => 'a.de']));
	}

	/**
	 * Die Oberfläche liest das Snippet nicht mehr aus conf/ – der Ordner gehört root und
	 * ist für www-data unzugänglich (Nutzervorgabe 2026-09-20: bearbeiten nur über den
	 * Admin). Sie ruft stattdessen "vhost conf-show <name>" auf.
	 */
	public function testSnippetIsFetchedThroughTheCliNotFromTheFile(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		// Das Testskript spiegelt die Argumente zurück; daran ist der Aufruf erkennbar.
		self::assertStringContainsString('ARGS=conf-show|a.de', $this->page->snippet($v));

		// Eine Datei in conf/ darf das Ergebnis NICHT beeinflussen: gelesen wird über das CLI.
		mkdir($this->dir . '/www/a.de/conf', 0750, true);
		file_put_contents($this->dir . '/www/a.de/conf/custom.conf', "expires 1d;\n");
		self::assertStringNotContainsString('expires 1d;', $this->page->snippet($v));
	}

	/**
	 * Scheitert der CLI-Aufruf, zeigt das Textfeld nichts an statt eine Fehlermeldung
	 * als angeblichen Snippet-Inhalt.
	 */
	public function testSnippetIsEmptyWhenTheCliFails(): void
	{
		$config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'vhostBinary' => $this->dir . '/gibt-es-nicht.php',
		]);
		$session = [];
		$page = new AdminPage($this->repo, new CommandRunner($config, ['php']), $config, new VhostLayout($config), new ReachabilityChecker($this->reachability), $session);
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		self::assertSame('', $page->snippet($v));
	}

	/**
	 * Befund 8, seit 2026-09-25 geschlossen: Früher erzeugte ein Namensfeld "--purge"
	 * ["remove", "--purge"], und das CLI las den Wert als Option. Jetzt steht er hinter
	 * "--" und bleibt ein Name – den das CLI dann als ungültigen Domainnamen ablehnt.
	 */
	public function testCommandForPassesPurgeLikeNameThrough(): void
	{
		self::assertSame(['args' => ['remove', '--', '--purge'], 'stdin' => null], AdminPage::commandFor('remove', ['name' => '--purge']));
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
		self::assertSame(['ok', "ARGS=protect|--|a.de|on\nSTDIN="], $this->page->takeFlash());
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
	/**
	 * Seite, deren CLI-Aufruf immer scheitert (Programm gibt es nicht) – damit lässt
	 * sich der Weg "Direktiven abgelehnt" prüfen, ohne echte Direktiven zu brauchen.
	 */
	private function pageWithFailingCli(): AdminPage
	{
		$config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'vhostBinary' => $this->dir . '/gibt-es-nicht.php',
		]);
		return new AdminPage(
			$this->repo,
			new CommandRunner($config, ['php']),
			$config,
			new VhostLayout($config),
			new ReachabilityChecker($this->reachability),
			$this->session
		);
	}

	/**
	 * Wird ein Snippet abgelehnt, muss der eingegebene Text erhalten bleiben – sonst
	 * tippt man wegen eines Fehlers in Zeile 3 die ganze Datei neu.
	 */
	public function testRejectedSnippetIsKeptForTheNextPageView(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$page = $this->pageWithFailingCli();
		$page->handlePost(['action' => 'conf', 'name' => 'a.de', 'snippet' => "expires 1d;\nkaputt\n"]);

		self::assertSame("expires 1d;\nkaputt\n", $page->draft($v));
		// Nur einmal: nach dem Anzeigen ist der Entwurf verbraucht.
		self::assertNull($page->draft($v));
	}

	public function testDraftIsNotShownForADifferentVhost(): void
	{
		$a = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$b = $this->repo->insert('b.de', VhostKind::Domain, null, null, true);
		$page = $this->pageWithFailingCli();
		$page->handlePost(['action' => 'conf', 'name' => 'a.de', 'snippet' => "kaputt\n"]);

		self::assertNull($page->draft($b), 'Der Entwurf gehört zu a.de');
		self::assertSame("kaputt\n", $page->draft($a));
	}

	/**
	 * Bei erfolgreicher Übernahme gibt es nichts aufzubewahren – sonst überschriebe ein
	 * alter Entwurf später die gespeicherte Fassung im Textfeld.
	 */
	public function testNoDraftIsKeptWhenTheSnippetWasAccepted(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->page->handlePost(['action' => 'conf', 'name' => 'a.de', 'snippet' => "expires 1d;\n"]);
		self::assertNull($this->page->draft($v));
	}
	// ------------------------------------------------------------------
	// Erzeugte Passwörter
	// ------------------------------------------------------------------

	/**
	 * "Passwort neu" hat kein Eingabefeld – das Passwort muss beim Verarbeiten
	 * entstehen und an das CLI gehen.
	 */
	public function testUserResetGeneratesAPasswordAndPassesItToTheCli(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->page->handlePost(['action' => 'user_reset', 'name' => 'a.de', 'username' => 'alice']);

		$credential = $this->page->takeCredential($v);
		self::assertNotNull($credential);
		self::assertSame('alice', $credential['user']);
		self::assertSame(20, strlen($credential['password']));
		self::assertMatchesRegularExpression('/^[A-Za-z0-9@=#+.,_:;-]{20}$/', $credential['password']);
	}

	public function testResettingTwiceGivesDifferentPasswords(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->page->handlePost(['action' => 'user_reset', 'name' => 'a.de', 'username' => 'alice']);
		$first = $this->page->takeCredential($v)['password'];
		$this->page->handlePost(['action' => 'user_reset', 'name' => 'a.de', 'username' => 'alice']);
		$second = $this->page->takeCredential($v)['password'];
		self::assertNotSame($first, $second);
	}

	/**
	 * Das Passwort darf nur einmal erscheinen: Ein Neuladen der Seite soll es nicht
	 * noch einmal zeigen.
	 */
	public function testCredentialIsShownOnlyOnce(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$this->page->handlePost(['action' => 'user_add', 'name' => 'a.de', 'username' => 'bob', 'password' => 'Geheim123@abcdefghij']);

		self::assertSame('Geheim123@abcdefghij', $this->page->takeCredential($v)['password']);
		self::assertNull($this->page->takeCredential($v));
	}

	public function testCredentialBelongsToItsVhost(): void
	{
		$a = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$b = $this->repo->insert('b.de', VhostKind::Domain, null, null, true);
		$this->page->handlePost(['action' => 'user_add', 'name' => 'a.de', 'username' => 'bob', 'password' => 'x']);
		self::assertNull($this->page->takeCredential($b));
		self::assertNotNull($this->page->takeCredential($a));
	}

	/**
	 * Scheitert das Anlegen, darf kein Passwort angezeigt werden – es wurde ja keines
	 * hinterlegt.
	 */
	public function testNoCredentialWhenTheCommandFails(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		$page = $this->pageWithFailingCli();
		$page->handlePost(['action' => 'user_add', 'name' => 'a.de', 'username' => 'bob', 'password' => 'x']);
		self::assertNull($page->takeCredential($v));
	}

	public function testSuggestedPasswordIsFreshEveryTime(): void
	{
		self::assertNotSame($this->page->suggestedPassword(), $this->page->suggestedPassword());
		self::assertSame(20, strlen($this->page->suggestedPassword()));
	}
}
