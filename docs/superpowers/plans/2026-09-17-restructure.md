# Neustrukturierung vhost-admin – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das funktionierende Projekt `vhost-admin` ohne Verhaltensänderung in die Struktur und den OOP-Stil der globalen `~/.claude/CLAUDE.md` überführen – mit PHPUnit-Tests, Build-Skript und Pflichtdokumentation.

**Architecture:** Schlankes OOP unter dem Namespace `VhostAdmin` (Wertobjekte, Entität `Vhost`, `VhostRepository`, reiner `Nginx\ConfigRenderer`, `VhostService` für Anwendungsfälle, Interfaces nur für nginx-Reload und certbot, damit Tests Fakes nutzen). CLI und Web-Oberfläche sind dünne Adapter über dem Service. Eigener Autoloader, kein Composer.

**Tech Stack:** PHP 8.5 (pdo_sqlite, posix), PHPUnit 13 (Ubuntu-Paket `phpunit`), nginx 1.28, certbot, Bash, git.

**Spec:** `docs/superpowers/specs/2026-09-17-restructure-design.md`

## Global Constraints

- Einrückung am Zeilenanfang ausschließlich mit **Tabulatoren** (1 Tab = 4 Leerzeichen) – in PHP, Bash, XML und Markdown-Codeblöcken.
- Bezeichner (Klassen, Methoden, Variablen) **englisch**; Kommentare, DocBlocks, Fehlermeldungen, Usage-Texte **deutsch** mit echten Umlauten.
- Jede PHP-Datei beginnt mit `<?php` + `declare(strict_types=1);` und trägt einen deutschen DocBlock mit `@author Kurt Ingwer` und `@version Letzte Änderung: YYYY-MM-DD HH:MM` (Uhrzeit des tatsächlichen Schreibens).
- Jede Klasse und jede öffentliche Methode bekommt einen deutschen DocBlock.
- Verhalten, nginx-Ausgabe, Installationspfade und CLI-Befehle bleiben identisch zum heutigen Stand (siehe Spec Abschnitt 1 und 3).
- Tests laufen **ohne root** und berühren keine Systempfade: alle Pfade kommen aus `Config::fromArray([...])` mit Temp-Verzeichnissen; nginx-Reload und certbot laufen über Fakes.
- Commits nach jedem Task lokal; **kein `git push` aus einem Subagenten**. Gepusht wird nur durch die Hauptsitzung im Rahmen von `build.sh` (Task 13).
- Neue Funktionen sind ausgeschlossen (Spec Abschnitt 9).

## Dateistruktur (Ergebnis)

```
vhost-admin/
├── install.sh                       Wrapper → src/install.sh
├── build.sh                         Tests → Buildnummer → Tarball → Commit → Push
├── claude.sh                        Kopie von ~/claude.sh
├── phpunit.xml
├── .gitignore
├── src/
│   ├── build.txt
│   ├── install.sh
│   ├── bootstrap.php
│   ├── lib/VhostAdmin/
│   │   ├── Config.php
│   │   ├── Database.php
│   │   ├── VhostKind.php
│   │   ├── Vhost.php
│   │   ├── VhostRepository.php
│   │   ├── VhostService.php
│   │   ├── Value/DomainName.php, Port.php, SubDirectory.php, Username.php, Cidr.php
│   │   ├── Nginx/ConfigRenderer.php, ReloaderInterface.php, SystemdReloader.php
│   │   ├── Ssl/CertbotInterface.php, CertbotClient.php
│   │   ├── Cli/Application.php
│   │   └── Web/CommandRunner.php, AdminPage.php
│   ├── bin/vhost, bin/vhost.php
│   ├── public/index.php
│   ├── templates/index.html
│   └── etc/nginx-admin.conf, nginx-default.conf, sudoers-vhost-admin, certbot-deploy-nginx.sh
├── tests/
│   ├── bootstrap.php
│   ├── Support/TempDir.php, FakeReloader.php, FakeCertbot.php
│   ├── ConfigTest.php, DatabaseTest.php, VhostTest.php, VhostRepositoryTest.php, VhostServiceTest.php
│   ├── Value/DomainNameTest.php, PortTest.php, SubDirectoryTest.php, UsernameTest.php, CidrTest.php
│   ├── Nginx/ConfigRendererTest.php
│   ├── Cli/ApplicationTest.php
│   └── Web/AdminPageTest.php
├── build/                           (gitignored)
├── debugging/smoke-test.sh
├── claude-generated/.gitkeep
├── dev-log/2026-09-17-dev.log
├── research/OPTIMIZED_WORKER.md, nginx-basic-auth.md, certbot-webroot.md, php-fpm-haertung.md
├── docs/superpowers/{specs,plans}/
├── .claude/CLAUDE.md
└── README.md, BUGS.md, FEATURES.md, OPTIMIZE.md, MEMORY.md, SECURITY_RISKS.md, SECURITY_FIXED.md
```

Verantwortlichkeiten pro Datei siehe Spec Abschnitt 4. Die alten prozeduralen Dateien `src/lib/config.php`, `src/lib/db.php`, `src/lib/vhost.php` werden in Task 11 gelöscht, nachdem alle Aufrufer umgestellt sind.

---

### Task 1: Projektstruktur, Werkzeuge, Autoloader

**Files:**
- Create: `src/bootstrap.php`, `src/build.txt`, `phpunit.xml`, `.gitignore`, `tests/bootstrap.php`, `tests/Support/TempDir.php`, `tests/BootstrapTest.php`, `install.sh` (Wrapper), `claude-generated/.gitkeep`, `debugging/.gitkeep`
- Move (git mv): `lib/` → `src/lib/`, `bin/` → `src/bin/`, `public/` → `src/public/`, `templates/` → `src/templates/`, `etc/` → `src/etc/`, `install.sh` → `src/install.sh`
- Copy: `~/claude.sh` → `claude.sh`

**Interfaces:**
- Produces: Autoloader für `VhostAdmin\*` (Klasse `VhostAdmin\Foo\Bar` → `src/lib/VhostAdmin/Foo/Bar.php`); `Tests\Support\TempDir::create(): string`, `TempDir::remove(string $dir): void`.

- [ ] **Step 1: PHPUnit installieren und Verzeichnisse anlegen**

```bash
sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -q phpunit
phpunit --version
cd /home/user/vhost-admin
mkdir -p src tests/Support build debugging claude-generated dev-log research docs
git mv lib src/lib && git mv bin src/bin && git mv public src/public && git mv templates src/templates && git mv etc src/etc && git mv install.sh src/install.sh
cp ~/claude.sh claude.sh
touch claude-generated/.gitkeep debugging/.gitkeep
echo 0 > src/build.txt
```
Erwartet: `PHPUnit 13.x`; `git status` zeigt Umbenennungen (R), keine gelöschten Dateien.

- [ ] **Step 2: Wrapper `install.sh` im Wurzelverzeichnis schreiben**

```bash
#!/usr/bin/env bash
# Wrapper: ruft den eigentlichen Installer unter src/ auf.
# Aufruf: sudo ./install.sh [--owner BENUTZER]
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/src/install.sh" "$@"
```
`chmod +x install.sh`. In `src/install.sh` die Zeile `SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"` unverändert lassen – sie zeigt jetzt auf `src/`, alle relativen Pfade darin stimmen weiterhin.

- [ ] **Step 3: `.gitignore` und `phpunit.xml` schreiben**

`.gitignore`:
```
build/
*.sqlite
tests/.phpunit.cache/
```

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	bootstrap="tests/bootstrap.php"
	colors="true"
	failOnWarning="true"
	cacheDirectory="tests/.phpunit.cache">
	<testsuites>
		<testsuite name="vhost-admin">
			<directory>tests</directory>
		</testsuite>
	</testsuites>
	<source>
		<include>
			<directory>src/lib</directory>
		</include>
	</source>
</phpunit>
```

- [ ] **Step 4: Fehlschlagenden Test für den Autoloader schreiben**

`tests/BootstrapTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Prüft, dass der Autoloader Klassen des Namespace VhostAdmin findet.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:00
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
	public function testAutoloaderFindsConfigClass(): void
	{
		self::assertTrue(class_exists(\VhostAdmin\Config::class));
	}

	public function testTempDirIsCreatedAndRemoved(): void
	{
		$dir = Support\TempDir::create();
		self::assertDirectoryExists($dir);
		file_put_contents($dir . '/a/b.txt', 'x') === false || true;
		Support\TempDir::remove($dir);
		self::assertDirectoryDoesNotExist($dir);
	}
}
```
Hinweis: Die Zeile mit `file_put_contents` in ein Unterverzeichnis ist absichtlich ohne `mkdir`; ersetze sie durch:
```php
		mkdir($dir . '/a');
		file_put_contents($dir . '/a/b.txt', 'x');
```

- [ ] **Step 5: `tests/bootstrap.php` und `tests/Support/TempDir.php` schreiben**

`tests/bootstrap.php`:
```php
<?php
declare(strict_types=1);

/**
 * Test-Bootstrap: lädt den Autoloader des Projekts und die Test-Hilfsklassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:00
 */

require dirname(__DIR__) . '/src/bootstrap.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'Tests\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
```

`tests/Support/TempDir.php`:
```php
<?php
declare(strict_types=1);

/**
 * Hilfsklasse: temporäre Verzeichnisse für Tests anlegen und rekursiv löschen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:00
 */

namespace Tests\Support;

final class TempDir
{
	/**
	 * Legt ein eindeutiges Temp-Verzeichnis an und gibt den Pfad zurück.
	 */
	public static function create(): string
	{
		$dir = sys_get_temp_dir() . '/vhost-admin-test-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		return $dir;
	}

	/**
	 * Löscht ein Verzeichnis samt Inhalt. Symlinks werden entfernt, nicht verfolgt.
	 */
	public static function remove(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
```

- [ ] **Step 6: Test ausführen – muss fehlschlagen**

Run: `phpunit`
Erwartet: FAIL, `testAutoloaderFindsConfigClass` schlägt fehl (Klasse `VhostAdmin\Config` existiert noch nicht), `testTempDirIsCreatedAndRemoved` besteht.

- [ ] **Step 7: `src/bootstrap.php` und eine minimale `Config`-Klasse anlegen**

`src/bootstrap.php`:
```php
<?php
declare(strict_types=1);

/**
 * Autoloader für den Namespace VhostAdmin.
 *
 * Eine Klasse VhostAdmin\Foo\Bar liegt in lib/VhostAdmin/Foo/Bar.php.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:00
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'VhostAdmin\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/lib/VhostAdmin/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
```

`src/lib/VhostAdmin/Config.php` (vorläufig, wird in Task 2 vervollständigt):
```php
<?php
declare(strict_types=1);

/**
 * Konfiguration: Pfade und Ports der Installation.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:00
 */

namespace VhostAdmin;

final class Config
{
}
```

- [ ] **Step 8: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: `OK (2 tests, 3 assertions)`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Projektstruktur nach CLAUDE.md: src/, tests/, PHPUnit, Autoloader"
```

---

### Task 2: Config und Database

**Files:**
- Modify: `src/lib/VhostAdmin/Config.php`
- Create: `src/lib/VhostAdmin/Database.php`, `tests/ConfigTest.php`, `tests/DatabaseTest.php`

**Interfaces:**
- Produces:
  - `VhostAdmin\Config` mit `public readonly` Eigenschaften `string $dbPath, string $wwwRoot, string $wwwOwner, string $wwwGroup, string $sitesAvailable, string $sitesEnabled, string $authDir, string $letsEncryptLive, string $vhostBinary, string $templatePath, int $adminPort, bool $ipv6`; `Config::defaults(): Config`; `Config::fromArray(array $values): Config` (Schlüssel = Eigenschaftsnamen, fehlende Schlüssel bekommen Standardwerte); `Config::systemHasIpv6(): bool`.
  - `VhostAdmin\Database::__construct(Config $config)`, `pdo(): \PDO` (lazy, foreign_keys=ON, Exceptions), `initSchema(): void` (CREATE TABLE IF NOT EXISTS für vhosts, auth_users, auth_ips, settings).

- [ ] **Step 1: Fehlschlagende Tests schreiben**

`tests/ConfigTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Konfigurationsklasse.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:10
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;

final class ConfigTest extends TestCase
{
	public function testDefaultsPointToProductionPaths(): void
	{
		$c = Config::defaults();
		self::assertSame('/var/lib/vhost-admin/vhosts.sqlite', $c->dbPath);
		self::assertSame('/var/www', $c->wwwRoot);
		self::assertSame('www-data', $c->wwwGroup);
		self::assertSame('/etc/nginx/sites-available', $c->sitesAvailable);
		self::assertSame('/etc/nginx/sites-enabled', $c->sitesEnabled);
		self::assertSame('/etc/nginx/auth', $c->authDir);
		self::assertSame('/etc/letsencrypt/live', $c->letsEncryptLive);
		self::assertSame('/usr/local/sbin/vhost', $c->vhostBinary);
		self::assertSame(8080, $c->adminPort);
		self::assertStringEndsWith('/templates/index.html', $c->templatePath);
	}

	public function testFromArrayOverridesOnlyGivenKeys(): void
	{
		$c = Config::fromArray(['wwwRoot' => '/tmp/www', 'adminPort' => 9090, 'ipv6' => false]);
		self::assertSame('/tmp/www', $c->wwwRoot);
		self::assertSame(9090, $c->adminPort);
		self::assertFalse($c->ipv6);
		self::assertSame('/etc/nginx/auth', $c->authDir);
	}

	public function testFromArrayRejectsUnknownKey(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Config::fromArray(['doesNotExist' => 1]);
	}
}
```

`tests/DatabaseTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Datenbankverbindung und das Schema.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:10
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
```

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit`
Erwartet: Fehler in `ConfigTest` (Methode `defaults` fehlt) und `DatabaseTest` (Klasse `Database` fehlt).

- [ ] **Step 3: `Config` implementieren**

`src/lib/VhostAdmin/Config.php`:
```php
<?php
declare(strict_types=1);

/**
 * Konfiguration: alle Pfade und Ports der Installation.
 *
 * Die Standardwerte entsprechen der Installation durch install.sh. Tests
 * erzeugen per fromArray() eine Konfiguration mit temporären Verzeichnissen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:10
 */

namespace VhostAdmin;

final class Config
{
	/**
	 * @param string $dbPath          SQLite-Datei mit vHosts, Benutzern, IPs und Einstellungen
	 * @param string $wwwRoot         Wurzel der Docroots (/var/www)
	 * @param string $wwwOwner        Besitzer neuer Docroots (wird von install.sh gesetzt)
	 * @param string $wwwGroup        Gruppe neuer Docroots (nginx-Worker müssen lesen können)
	 * @param string $sitesAvailable  nginx sites-available
	 * @param string $sitesEnabled    nginx sites-enabled
	 * @param string $authDir         htpasswd-Dateien und Auth-Snippets
	 * @param string $letsEncryptLive Zertifikatsverzeichnis von certbot
	 * @param string $vhostBinary     Pfad des CLI, das die Oberfläche per sudo aufruft
	 * @param string $templatePath    Vorlage der bunten Startseite
	 * @param int    $adminPort       Port der Verwaltungsoberfläche (reserviert)
	 * @param bool   $ipv6            ob nginx zusätzlich auf IPv6 lauschen soll
	 */
	public function __construct(
		public readonly string $dbPath,
		public readonly string $wwwRoot,
		public readonly string $wwwOwner,
		public readonly string $wwwGroup,
		public readonly string $sitesAvailable,
		public readonly string $sitesEnabled,
		public readonly string $authDir,
		public readonly string $letsEncryptLive,
		public readonly string $vhostBinary,
		public readonly string $templatePath,
		public readonly int $adminPort,
		public readonly bool $ipv6,
	) {
	}

	/**
	 * Konfiguration der Standardinstallation.
	 */
	public static function defaults(): self
	{
		return self::fromArray([]);
	}

	/**
	 * Konfiguration aus einem Array; fehlende Schlüssel erhalten Standardwerte.
	 *
	 * @param array<string, mixed> $values Schlüssel = Eigenschaftsnamen
	 * @throws \InvalidArgumentException bei unbekannten Schlüsseln
	 */
	public static function fromArray(array $values): self
	{
		$defaults = [
			'dbPath' => '/var/lib/vhost-admin/vhosts.sqlite',
			'wwwRoot' => '/var/www',
			'wwwOwner' => 'user',
			'wwwGroup' => 'www-data',
			'sitesAvailable' => '/etc/nginx/sites-available',
			'sitesEnabled' => '/etc/nginx/sites-enabled',
			'authDir' => '/etc/nginx/auth',
			'letsEncryptLive' => '/etc/letsencrypt/live',
			'vhostBinary' => '/usr/local/sbin/vhost',
			'templatePath' => dirname(__DIR__, 2) . '/templates/index.html',
			'adminPort' => 8080,
			'ipv6' => self::systemHasIpv6(),
		];
		$unknown = array_diff_key($values, $defaults);
		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unbekannte Konfigurationsschlüssel: ' . implode(', ', array_keys($unknown)));
		}
		return new self(...array_merge($defaults, $values));
	}

	/**
	 * Prüft, ob das System IPv6-Adressen hat (dann lauscht nginx auch auf [::]).
	 */
	public static function systemHasIpv6(): bool
	{
		return is_file('/proc/net/if_inet6') && trim((string)file_get_contents('/proc/net/if_inet6')) !== '';
	}
}
```
Hinweis für `install.sh` (Task 11): Der Besitzer wird dort per `sed` in der Zeile `'wwwOwner' => 'user',` ersetzt.

- [ ] **Step 4: `Database` implementieren**

`src/lib/VhostAdmin/Database.php`:
```php
<?php
declare(strict_types=1);

/**
 * SQLite-Verbindung und Schema.
 *
 * Die Verbindung wird erst beim ersten Zugriff geöffnet. Fremdschlüssel sind
 * aktiv, damit Benutzer und IPs beim Löschen eines vHosts mitgelöscht werden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:10
 */

namespace VhostAdmin;

final class Database
{
	private ?\PDO $pdo = null;

	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Liefert die PDO-Verbindung; legt das Datenbankverzeichnis bei Bedarf an.
	 */
	public function pdo(): \PDO
	{
		if ($this->pdo === null) {
			$dir = dirname($this->config->dbPath);
			if (!is_dir($dir)) {
				mkdir($dir, 0770, true);
			}
			$this->pdo = new \PDO('sqlite:' . $this->config->dbPath);
			$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
			$this->pdo->exec('PRAGMA foreign_keys = ON');
		}
		return $this->pdo;
	}

	/**
	 * Legt alle Tabellen an, falls sie fehlen (mehrfach aufrufbar).
	 */
	public function initSchema(): void
	{
		$this->pdo()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS vhosts (
	id         INTEGER PRIMARY KEY,
	name       TEXT NOT NULL UNIQUE,
	kind       TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')),
	port       INTEGER,
	subdir     TEXT,
	protect    INTEGER NOT NULL DEFAULT 1,
	ssl        INTEGER NOT NULL DEFAULT 0,
	created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS auth_users (
	id       INTEGER PRIMARY KEY,
	vhost_id INTEGER NOT NULL REFERENCES vhosts(id) ON DELETE CASCADE,
	username TEXT NOT NULL,
	hash     TEXT NOT NULL,
	UNIQUE (vhost_id, username)
);
CREATE TABLE IF NOT EXISTS auth_ips (
	id       INTEGER PRIMARY KEY,
	vhost_id INTEGER NOT NULL REFERENCES vhosts(id) ON DELETE CASCADE,
	cidr     TEXT NOT NULL,
	UNIQUE (vhost_id, cidr)
);
CREATE TABLE IF NOT EXISTS settings (
	key   TEXT PRIMARY KEY,
	value TEXT NOT NULL
);
SQL);
	}
}
```

- [ ] **Step 5: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: `OK (8 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/lib/VhostAdmin/Config.php src/lib/VhostAdmin/Database.php tests/ConfigTest.php tests/DatabaseTest.php
git commit -m "Config und Database als Klassen mit Tests"
```

---

### Task 3: Wertobjekte (DomainName, Port, SubDirectory, Username, Cidr)

**Files:**
- Create: `src/lib/VhostAdmin/Value/DomainName.php`, `Port.php`, `SubDirectory.php`, `Username.php`, `Cidr.php`
- Test: `tests/Value/DomainNameTest.php`, `PortTest.php`, `SubDirectoryTest.php`, `UsernameTest.php`, `CidrTest.php`

**Interfaces:**
- Produces (alle `final class`, unveränderlich, `__toString()` liefert `$value`):
  - `Value\DomainName::fromString(string $raw): DomainName` – `public readonly string $value` (kleingeschrieben, getrimmt)
  - `Value\Port::fromString(string $raw, int $adminPort = 8080): Port` – `public readonly int $value`
  - `Value\SubDirectory::fromString(?string $raw): ?SubDirectory` – `null` bei leer; `public readonly string $value` ohne führende/abschließende `/`; `segments(): list<string>`
  - `Value\Username::fromString(string $raw): Username` – `public readonly string $value`
  - `Value\Cidr::fromString(string $raw): Cidr` – `public readonly string $value` (`ip` oder `ip/bits`)
  - Alle werfen `\InvalidArgumentException` mit deutscher Meldung.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

`tests/Value/DomainNameTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt DomainName.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\DomainName;

final class DomainNameTest extends TestCase
{
	public function testNormalizesCaseAndWhitespace(): void
	{
		self::assertSame('example.com', DomainName::fromString('  Example.COM ')->value);
		self::assertSame('example.com', (string)DomainName::fromString('example.com'));
	}

	public function testAcceptsSubdomainsAndHyphens(): void
	{
		self::assertSame('a-b.sub.example.co.uk', DomainName::fromString('a-b.sub.example.co.uk')->value);
	}

	public function testAcceptsMaximumLength(): void
	{
		$label = str_repeat('a', 63);
		$name = $label . '.' . $label . '.' . $label . '.' . str_repeat('b', 57) . '.de'; // 253 Zeichen
		self::assertSame(253, strlen($name));
		self::assertSame($name, DomainName::fromString($name)->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidNames(): iterable
	{
		yield 'leer' => [''];
		yield 'ohne TLD' => ['example'];
		yield 'localhost' => ['localhost'];
		yield 'localhost mit Port' => ['localhost:3000'];
		yield 'localhost-Subdomain' => ['app.localhost'];
		yield 'Umlaut' => ['müller.de'];
		yield 'Bindestrich am Anfang' => ['-a.example.com'];
		yield 'Bindestrich am Ende' => ['a-.example.com'];
		yield 'Label zu lang' => [str_repeat('a', 64) . '.example.com'];
		yield 'numerische TLD' => ['example.123'];
		yield 'Leerzeichen innen' => ['exa mple.com'];
		yield 'Schrägstrich' => ['example.com/'];
		yield 'zu lang' => [str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.' . str_repeat('a', 63) . '.' . str_repeat('b', 58) . '.de'];
	}

	#[DataProvider('invalidNames')]
	public function testRejectsInvalidNames(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DomainName::fromString($raw);
	}
}
```

`tests/Value/PortTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Port;

final class PortTest extends TestCase
{
	public function testAcceptsRange(): void
	{
		self::assertSame(1, Port::fromString('1')->value);
		self::assertSame(3000, Port::fromString('3000')->value);
		self::assertSame(65535, Port::fromString('65535')->value);
		self::assertSame('3000', (string)Port::fromString('3000'));
	}

	public function testAdminPortIsConfigurable(): void
	{
		self::assertSame(8080, Port::fromString('8080', 9090)->value);
		$this->expectException(\InvalidArgumentException::class);
		Port::fromString('9090', 9090);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidPorts(): iterable
	{
		yield 'null' => ['0'];
		yield 'zu groß' => ['65536'];
		yield 'negativ' => ['-1'];
		yield 'Text' => ['abc'];
		yield 'leer' => [''];
		yield 'Dezimal' => ['80.5'];
		yield 'http' => ['80'];
		yield 'https' => ['443'];
		yield 'admin' => ['8080'];
	}

	#[DataProvider('invalidPorts')]
	public function testRejectsInvalidPorts(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Port::fromString($raw);
	}
}
```

`tests/Value/SubDirectoryTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt SubDirectory.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\SubDirectory;

final class SubDirectoryTest extends TestCase
{
	public function testEmptyMeansNone(): void
	{
		self::assertNull(SubDirectory::fromString(null));
		self::assertNull(SubDirectory::fromString(''));
		self::assertNull(SubDirectory::fromString('  /  '));
	}

	public function testTrimsSlashesAndSplitsSegments(): void
	{
		$s = SubDirectory::fromString('/public/html/');
		self::assertSame('public/html', $s->value);
		self::assertSame(['public', 'html'], $s->segments());
		self::assertSame('public/html', (string)$s);
	}

	public function testAcceptsDotsInsideSegments(): void
	{
		self::assertSame('v1.2/site_a', SubDirectory::fromString('v1.2/site_a')->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidDirectories(): iterable
	{
		yield 'Elternverzeichnis' => ['../etc'];
		yield 'Elternverzeichnis innen' => ['public/../secret'];
		yield 'Punktverzeichnis' => ['./a'];
		yield 'versteckt' => ['.hidden'];
		yield 'Leerzeichen' => ['my site'];
		yield 'Umlaut' => ['bilder/über'];
		yield 'Backslash' => ['a\\b'];
		yield 'doppelter Schrägstrich' => ['a//b'];
	}

	#[DataProvider('invalidDirectories')]
	public function testRejectsInvalidDirectories(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SubDirectory::fromString($raw);
	}
}
```

`tests/Value/UsernameTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Username.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Username;

final class UsernameTest extends TestCase
{
	public function testAcceptsTypicalNames(): void
	{
		self::assertSame('alice', Username::fromString('alice')->value);
		self::assertSame('bob.smith@example.com', Username::fromString('bob.smith@example.com')->value);
		self::assertSame(str_repeat('a', 64), Username::fromString(str_repeat('a', 64))->value);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidNames(): iterable
	{
		yield 'leer' => [''];
		yield 'Doppelpunkt (htpasswd-Trenner)' => ['a:b'];
		yield 'Leerzeichen' => ['a b'];
		yield 'zu lang' => [str_repeat('a', 65)];
		yield 'Umlaut' => ['jürgen'];
		yield 'Zeilenumbruch' => ["a\nb"];
	}

	#[DataProvider('invalidNames')]
	public function testRejectsInvalidNames(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Username::fromString($raw);
	}
}
```

`tests/Value/CidrTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für das Wertobjekt Cidr.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\Cidr;

final class CidrTest extends TestCase
{
	/** @return iterable<string, array{string, string}> */
	public static function validValues(): iterable
	{
		yield 'IPv4' => ['203.0.113.5', '203.0.113.5'];
		yield 'IPv4 mit Maske' => ['10.0.0.0/8', '10.0.0.0/8'];
		yield 'IPv4 /32' => ['10.0.0.1/32', '10.0.0.1/32'];
		yield 'IPv4 /0' => ['0.0.0.0/0', '0.0.0.0/0'];
		yield 'IPv6' => ['2001:db8::1', '2001:db8::1'];
		yield 'IPv6 mit Maske' => ['2001:db8::/32', '2001:db8::/32'];
		yield 'IPv6 /128' => ['::1/128', '::1/128'];
		yield 'führende Null in Maske' => ['10.0.0.0/08', '10.0.0.0/8'];
		yield 'Leerzeichen außen' => [' 127.0.0.1 ', '127.0.0.1'];
	}

	#[DataProvider('validValues')]
	public function testAcceptsAndNormalizes(string $raw, string $expected): void
	{
		self::assertSame($expected, Cidr::fromString($raw)->value);
		self::assertSame($expected, (string)Cidr::fromString($raw));
	}

	/** @return iterable<string, array{string}> */
	public static function invalidValues(): iterable
	{
		yield 'leer' => [''];
		yield 'Hostname' => ['example.com'];
		yield 'IPv4 Oktett zu groß' => ['256.0.0.1'];
		yield 'IPv4 Maske zu groß' => ['10.0.0.0/33'];
		yield 'IPv6 Maske zu groß' => ['::1/129'];
		yield 'Maske negativ' => ['10.0.0.0/-1'];
		yield 'Maske Text' => ['10.0.0.0/abc'];
		yield 'doppelter Schrägstrich' => ['10.0.0.0//8'];
		yield 'Semikolon (nginx-Injektion)' => ['10.0.0.1; deny all'];
	}

	#[DataProvider('invalidValues')]
	public function testRejectsInvalidValues(string $raw): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Cidr::fromString($raw);
	}
}
```

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/Value`
Erwartet: Fehler „Class ... not found“ für alle fünf Klassen.

- [ ] **Step 3: Wertobjekte implementieren**

`src/lib/VhostAdmin/Value/DomainName.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein gültiger, öffentlicher Domainname.
 *
 * Kleingeschrieben, höchstens 253 Zeichen, Labels nach RFC 1123, TLD nur
 * Buchstaben. localhost-Varianten sind ausgeschlossen, dafür gibt es Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace VhostAdmin\Value;

final class DomainName
{
	private const PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt den Domainnamen aus einer Eingabe.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Namen
	 */
	public static function fromString(string $raw): self
	{
		$name = strtolower(trim($raw));
		if ($name === 'localhost' || str_starts_with($name, 'localhost:') || str_ends_with($name, '.localhost')) {
			throw new \InvalidArgumentException('localhost-Hosts nur per "vhost add-local <port>"');
		}
		if (strlen($name) > 253 || preg_match(self::PATTERN, $name) !== 1) {
			throw new \InvalidArgumentException("Ungültiger Domainname: $raw");
		}
		return new self($name);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
```

`src/lib/VhostAdmin/Value/Port.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: TCP-Port für einen localhost-Host.
 *
 * 1–65535; 80, 443 und der Port der Verwaltungsoberfläche sind reserviert.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace VhostAdmin\Value;

final class Port
{
	private function __construct(public readonly int $value)
	{
	}

	/**
	 * Erzeugt den Port aus einer Eingabe.
	 *
	 * @param int $adminPort Port der Oberfläche, der nicht vergeben werden darf
	 * @throws \InvalidArgumentException bei ungültigem oder reserviertem Port
	 */
	public static function fromString(string $raw, int $adminPort = 8080): self
	{
		$raw = trim($raw);
		if ($raw === '' || !ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 65535) {
			throw new \InvalidArgumentException("Ungültiger Port: $raw");
		}
		$port = (int)$raw;
		if (in_array($port, [80, 443, $adminPort], true)) {
			throw new \InvalidArgumentException("Port $port ist reserviert");
		}
		return new self($port);
	}

	public function __toString(): string
	{
		return (string)$this->value;
	}
}
```

`src/lib/VhostAdmin/Value/SubDirectory.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: optionales Unterverzeichnis unterhalb des Basisordners eines vHosts.
 *
 * Jedes Segment beginnt mit Buchstabe, Ziffer oder Unterstrich; damit sind
 * ".", ".." und versteckte Verzeichnisse ausgeschlossen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace VhostAdmin\Value;

final class SubDirectory
{
	private const PATTERN = '~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Erzeugt das Unterverzeichnis; leer oder nur Schrägstriche ergibt null.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Pfad
	 */
	public static function fromString(?string $raw): ?self
	{
		$path = trim((string)$raw, "/ \t");
		if ($path === '') {
			return null;
		}
		if (preg_match(self::PATTERN, $path) !== 1) {
			throw new \InvalidArgumentException("Ungültiges Unterverzeichnis: $raw");
		}
		return new self($path);
	}

	/**
	 * Die einzelnen Pfadsegmente in Reihenfolge.
	 *
	 * @return list<string>
	 */
	public function segments(): array
	{
		return explode('/', $this->value);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
```

`src/lib/VhostAdmin/Value/Username.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: Benutzername für den Verzeichnisschutz (htpasswd).
 *
 * Kein Doppelpunkt (Trenner in htpasswd), keine Leerzeichen, 1–64 Zeichen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace VhostAdmin\Value;

final class Username
{
	private const PATTERN = '/^[A-Za-z0-9_.@-]{1,64}$/';

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Namen
	 */
	public static function fromString(string $raw): self
	{
		if (preg_match(self::PATTERN, $raw) !== 1) {
			throw new \InvalidArgumentException("Ungültiger Benutzername: $raw");
		}
		return new self($raw);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
```

`src/lib/VhostAdmin/Value/Cidr.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: IPv4-/IPv6-Adresse mit optionaler Netzmaske für nginx "allow".
 *
 * Die Maske wird als Ganzzahl normalisiert (10.0.0.0/08 → 10.0.0.0/8).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:20
 */

namespace VhostAdmin\Value;

final class Cidr
{
	private function __construct(public readonly string $value)
	{
	}

	/**
	 * @throws \InvalidArgumentException bei ungültiger Adresse oder Maske
	 */
	public static function fromString(string $raw): self
	{
		$raw = trim($raw);
		$parts = explode('/', $raw);
		if (count($parts) > 2) {
			throw new \InvalidArgumentException("Ungültige IP/CIDR: $raw");
		}
		[$ip, $bits] = array_pad($parts, 2, null);
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$max = 32;
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$max = 128;
		} else {
			throw new \InvalidArgumentException("Ungültige IP/CIDR: $raw");
		}
		if ($bits === null) {
			return new self($ip);
		}
		if ($bits === '' || !ctype_digit($bits) || (int)$bits > $max) {
			throw new \InvalidArgumentException("Ungültige Netzmaske: $raw");
		}
		return new self($ip . '/' . (int)$bits);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit tests/Value`
Erwartet: alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/Value tests/Value
git commit -m "Wertobjekte für Domain, Port, Unterordner, Benutzer und CIDR mit Tests"
```

---

### Task 4: VhostKind und Entität Vhost

**Files:**
- Create: `src/lib/VhostAdmin/VhostKind.php`, `src/lib/VhostAdmin/Vhost.php`
- Test: `tests/VhostTest.php`

**Interfaces:**
- Consumes: `Config` (Task 2)
- Produces:
  - `enum VhostKind: string { case Domain = 'domain'; case Localhost = 'localhost'; }`
  - `Vhost::__construct(?int $id, string $name, VhostKind $kind, ?int $port, ?string $subdir, bool $protect, bool $ssl, ?string $createdAt = null)` – alle Eigenschaften `public readonly`
  - `Vhost::fromRow(array $row): Vhost` (Datenbankzeile mit Schlüsseln id, name, kind, port, subdir, protect, ssl, created_at)
  - `isLocal(): bool`, `slug(): string` (Domain → Name; localhost → `localhost-<port>`), `baseDir(Config): string`, `docroot(Config): string`

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`tests/VhostTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Entität Vhost.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;

final class VhostTest extends TestCase
{
	private Config $config;

	protected function setUp(): void
	{
		$this->config = Config::fromArray(['wwwRoot' => '/srv/www']);
	}

	public function testDomainPaths(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		self::assertFalse($v->isLocal());
		self::assertSame('example.com', $v->slug());
		self::assertSame('/srv/www/example.com', $v->baseDir($this->config));
		self::assertSame('/srv/www/example.com', $v->docroot($this->config));
	}

	public function testDomainWithSubdirectory(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public/html', true, false);
		self::assertSame('/srv/www/example.com', $v->baseDir($this->config));
		self::assertSame('/srv/www/example.com/public/html', $v->docroot($this->config));
	}

	public function testLocalhostPaths(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		self::assertTrue($v->isLocal());
		self::assertSame('localhost-3000', $v->slug());
		self::assertSame('/srv/www/localhost-3000', $v->docroot($this->config));
	}

	public function testFromRowConvertsTypes(): void
	{
		$v = Vhost::fromRow([
			'id' => '7', 'name' => 'a.de', 'kind' => 'domain', 'port' => null, 'subdir' => null,
			'protect' => '1', 'ssl' => '0', 'created_at' => '2026-09-17 08:00:00',
		]);
		self::assertSame(7, $v->id);
		self::assertSame(VhostKind::Domain, $v->kind);
		self::assertNull($v->port);
		self::assertTrue($v->protect);
		self::assertFalse($v->ssl);
		self::assertSame('2026-09-17 08:00:00', $v->createdAt);
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/VhostTest.php`
Erwartet: „Class VhostAdmin\Vhost not found“.

- [ ] **Step 3: Enum und Entität implementieren**

`src/lib/VhostAdmin/VhostKind.php`:
```php
<?php
declare(strict_types=1);

/**
 * Art eines vHosts: öffentliche Domain oder nur lokal erreichbarer Port.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace VhostAdmin;

enum VhostKind: string
{
	case Domain = 'domain';
	case Localhost = 'localhost';
}
```

`src/lib/VhostAdmin/Vhost.php`:
```php
<?php
declare(strict_types=1);

/**
 * Entität: ein virtueller Host mit seinen Pfaden.
 *
 * Unveränderlich; Zustandsänderungen laufen über das Repository und werden
 * danach neu geladen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace VhostAdmin;

final class Vhost
{
	/**
	 * @param ?int      $id        Datenbank-ID (null vor dem Speichern)
	 * @param string    $name      "example.com" oder "localhost:3000"
	 * @param VhostKind $kind      Domain oder Localhost
	 * @param ?int      $port      nur bei Localhost gesetzt
	 * @param ?string   $subdir    optionales Unterverzeichnis als Docroot
	 * @param bool      $protect   Verzeichnisschutz aktiv
	 * @param bool      $ssl       Let's-Encrypt-Zertifikat aktiv (nur Domain)
	 * @param ?string   $createdAt Zeitstempel aus der Datenbank (UTC)
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly VhostKind $kind,
		public readonly ?int $port,
		public readonly ?string $subdir,
		public readonly bool $protect,
		public readonly bool $ssl,
		public readonly ?string $createdAt = null,
	) {
	}

	/**
	 * Baut die Entität aus einer Datenbankzeile.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int)$row['id'],
			(string)$row['name'],
			VhostKind::from((string)$row['kind']),
			$row['port'] === null ? null : (int)$row['port'],
			$row['subdir'] === null || $row['subdir'] === '' ? null : (string)$row['subdir'],
			(bool)$row['protect'],
			(bool)$row['ssl'],
			$row['created_at'] === null ? null : (string)$row['created_at'],
		);
	}

	/**
	 * Nur lokal erreichbar (bindet an 127.0.0.1)?
	 */
	public function isLocal(): bool
	{
		return $this->kind === VhostKind::Localhost;
	}

	/**
	 * Dateisystem- und Konfigurationsname: Domain unverändert, sonst localhost-<port>.
	 */
	public function slug(): string
	{
		return $this->isLocal() ? 'localhost-' . $this->port : $this->name;
	}

	/**
	 * Basisordner unterhalb der Web-Wurzel (auch Webroot für ACME-Challenges).
	 */
	public function baseDir(Config $config): string
	{
		return $config->wwwRoot . '/' . $this->slug();
	}

	/**
	 * Tatsächlicher Docroot (Basisordner plus optionales Unterverzeichnis).
	 */
	public function docroot(Config $config): string
	{
		return $this->baseDir($config) . ($this->subdir !== null ? '/' . $this->subdir : '');
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/VhostKind.php src/lib/VhostAdmin/Vhost.php tests/VhostTest.php
git commit -m "Entität Vhost und Enum VhostKind mit Tests"
```

---

### Task 5: VhostRepository

**Files:**
- Create: `src/lib/VhostAdmin/VhostRepository.php`
- Test: `tests/VhostRepositoryTest.php`

**Interfaces:**
- Consumes: `Database` (Task 2), `Vhost`, `VhostKind` (Task 4)
- Produces `VhostAdmin\VhostRepository::__construct(Database $db)` mit:
  - `all(): list<Vhost>` (sortiert nach kind, name)
  - `byName(string $name): ?Vhost`
  - `byId(int $id): ?Vhost`
  - `insert(string $name, VhostKind $kind, ?int $port, ?string $subdir, bool $protect): Vhost` – wirft `\RuntimeException("Existiert bereits: …")`
  - `delete(int $id): void`
  - `setProtect(int $id, bool $on): void`, `setSsl(int $id, bool $on): void`
  - `users(int $vhostId): list<array{username: string, hash: string}>`
  - `upsertUser(int $vhostId, string $username, string $hash): void`
  - `deleteUser(int $vhostId, string $username): bool` (false, wenn nicht vorhanden)
  - `ips(int $vhostId): list<string>`
  - `addIp(int $vhostId, string $cidr): void` (Duplikate ignoriert)
  - `deleteIp(int $vhostId, string $cidr): bool`
  - `setting(string $key): ?string`
  - `setSetting(string $key, ?string $value): void` (null oder '' löscht)

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`tests/VhostRepositoryTest.php`:
```php
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
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/VhostRepositoryTest.php`
Erwartet: „Class VhostAdmin\VhostRepository not found“.

- [ ] **Step 3: Repository implementieren**

`src/lib/VhostAdmin/VhostRepository.php`:
```php
<?php
declare(strict_types=1);

/**
 * Persistenz für vHosts, Schutz-Benutzer, freigegebene IPs und Einstellungen.
 *
 * Einziger Ort mit SQL. Liefert immer Vhost-Objekte, nie rohe Zeilen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:40
 */

namespace VhostAdmin;

final class VhostRepository
{
	public function __construct(private readonly Database $db)
	{
	}

	/**
	 * Alle vHosts, Domains zuerst, dann localhost, jeweils alphabetisch.
	 *
	 * @return list<Vhost>
	 */
	public function all(): array
	{
		$rows = $this->db->pdo()->query('SELECT * FROM vhosts ORDER BY kind, name')->fetchAll();
		return array_map(Vhost::fromRow(...), $rows);
	}

	/**
	 * vHost nach Name ("example.com" oder "localhost:3000").
	 */
	public function byName(string $name): ?Vhost
	{
		$st = $this->db->pdo()->prepare('SELECT * FROM vhosts WHERE name = ?');
		$st->execute([$name]);
		$row = $st->fetch();
		return $row === false ? null : Vhost::fromRow($row);
	}

	/**
	 * vHost nach Datenbank-ID.
	 */
	public function byId(int $id): ?Vhost
	{
		$st = $this->db->pdo()->prepare('SELECT * FROM vhosts WHERE id = ?');
		$st->execute([$id]);
		$row = $st->fetch();
		return $row === false ? null : Vhost::fromRow($row);
	}

	/**
	 * Legt einen vHost an und liefert ihn mit ID und Zeitstempel zurück.
	 *
	 * @throws \RuntimeException wenn der Name schon vergeben ist
	 */
	public function insert(string $name, VhostKind $kind, ?int $port, ?string $subdir, bool $protect): Vhost
	{
		if ($this->byName($name) !== null) {
			throw new \RuntimeException("Existiert bereits: $name");
		}
		$this->db->pdo()->prepare('INSERT INTO vhosts (name, kind, port, subdir, protect) VALUES (?, ?, ?, ?, ?)')
			->execute([$name, $kind->value, $port, $subdir, (int)$protect]);
		return $this->byId((int)$this->db->pdo()->lastInsertId());
	}

	/**
	 * Löscht den vHost; Benutzer und IPs werden per Fremdschlüssel mitgelöscht.
	 */
	public function delete(int $id): void
	{
		$this->db->pdo()->prepare('DELETE FROM vhosts WHERE id = ?')->execute([$id]);
	}

	/**
	 * Verzeichnisschutz ein- oder ausschalten.
	 */
	public function setProtect(int $id, bool $on): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET protect = ? WHERE id = ?')->execute([(int)$on, $id]);
	}

	/**
	 * HTTPS-Kennzeichen setzen.
	 */
	public function setSsl(int $id, bool $on): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET ssl = ? WHERE id = ?')->execute([(int)$on, $id]);
	}

	/**
	 * Schutz-Benutzer eines vHosts, alphabetisch.
	 *
	 * @return list<array{username: string, hash: string}>
	 */
	public function users(int $vhostId): array
	{
		$st = $this->db->pdo()->prepare('SELECT username, hash FROM auth_users WHERE vhost_id = ? ORDER BY username');
		$st->execute([$vhostId]);
		return $st->fetchAll();
	}

	/**
	 * Benutzer anlegen oder dessen Passwort-Hash ersetzen.
	 */
	public function upsertUser(int $vhostId, string $username, string $hash): void
	{
		$this->db->pdo()->prepare(
			'INSERT INTO auth_users (vhost_id, username, hash) VALUES (?, ?, ?)
			ON CONFLICT (vhost_id, username) DO UPDATE SET hash = excluded.hash'
		)->execute([$vhostId, $username, $hash]);
	}

	/**
	 * Benutzer entfernen; false, wenn es ihn nicht gab.
	 */
	public function deleteUser(int $vhostId, string $username): bool
	{
		$st = $this->db->pdo()->prepare('DELETE FROM auth_users WHERE vhost_id = ? AND username = ?');
		$st->execute([$vhostId, $username]);
		return $st->rowCount() > 0;
	}

	/**
	 * Freigegebene IPs/Netze eines vHosts, sortiert.
	 *
	 * @return list<string>
	 */
	public function ips(int $vhostId): array
	{
		$st = $this->db->pdo()->prepare('SELECT cidr FROM auth_ips WHERE vhost_id = ? ORDER BY cidr');
		$st->execute([$vhostId]);
		return $st->fetchAll(\PDO::FETCH_COLUMN);
	}

	/**
	 * IP/Netz freigeben; Duplikate werden stillschweigend ignoriert.
	 */
	public function addIp(int $vhostId, string $cidr): void
	{
		$this->db->pdo()->prepare('INSERT OR IGNORE INTO auth_ips (vhost_id, cidr) VALUES (?, ?)')->execute([$vhostId, $cidr]);
	}

	/**
	 * Freigabe entfernen; false, wenn es sie nicht gab.
	 */
	public function deleteIp(int $vhostId, string $cidr): bool
	{
		$st = $this->db->pdo()->prepare('DELETE FROM auth_ips WHERE vhost_id = ? AND cidr = ?');
		$st->execute([$vhostId, $cidr]);
		return $st->rowCount() > 0;
	}

	/**
	 * Einstellung lesen (z. B. le_email).
	 */
	public function setting(string $key): ?string
	{
		$st = $this->db->pdo()->prepare('SELECT value FROM settings WHERE key = ?');
		$st->execute([$key]);
		$value = $st->fetchColumn();
		return $value === false ? null : (string)$value;
	}

	/**
	 * Einstellung schreiben; null oder leer löscht sie.
	 */
	public function setSetting(string $key, ?string $value): void
	{
		if ($value === null || $value === '') {
			$this->db->pdo()->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
			return;
		}
		$this->db->pdo()->prepare(
			'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value'
		)->execute([$key, $value]);
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/VhostRepository.php tests/VhostRepositoryTest.php
git commit -m "VhostRepository mit Tests"
```

---

### Task 6: Nginx\ConfigRenderer

**Files:**
- Create: `src/lib/VhostAdmin/Nginx/ConfigRenderer.php`
- Test: `tests/Nginx/ConfigRendererTest.php`

**Interfaces:**
- Consumes: `Config`, `Vhost`
- Produces `VhostAdmin\Nginx\ConfigRenderer::__construct(Config $config)` mit:
  - `htpasswdPath(Vhost $v): string` → `<authDir>/<slug>.htpasswd`
  - `authSnippetPath(Vhost $v): string` → `<authDir>/<slug>.conf`
  - `serverConfigPath(Vhost $v): string` → `<sitesAvailable>/<slug>.conf`
  - `htpasswd(array $users): string` – eine Zeile `user:hash` je Benutzer
  - `authSnippet(Vhost $v, array $ips): string`
  - `serverConfig(Vhost $v): string`

Die erzeugten Texte müssen **zeichengenau** den heutigen Dateien entsprechen (Referenz: alte Funktion `vh_nginx_conf` in `src/lib/vhost.php`).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`tests/Nginx/ConfigRendererTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Erzeugung der nginx-Konfiguration.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:50
 */

namespace Tests\Nginx;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;

final class ConfigRendererTest extends TestCase
{
	private function renderer(bool $ipv6 = true): ConfigRenderer
	{
		return new ConfigRenderer(Config::fromArray([
			'wwwRoot' => '/var/www', 'authDir' => '/etc/nginx/auth', 'sitesAvailable' => '/etc/nginx/sites-available',
			'letsEncryptLive' => '/etc/letsencrypt/live', 'ipv6' => $ipv6,
		]));
	}

	public function testPaths(): void
	{
		$v = new Vhost(1, 'localhost:3000', VhostKind::Localhost, 3000, null, true, false);
		self::assertSame('/etc/nginx/auth/localhost-3000.htpasswd', $this->renderer()->htpasswdPath($v));
		self::assertSame('/etc/nginx/auth/localhost-3000.conf', $this->renderer()->authSnippetPath($v));
		self::assertSame('/etc/nginx/sites-available/localhost-3000.conf', $this->renderer()->serverConfigPath($v));
	}

	public function testHtpasswdLines(): void
	{
		self::assertSame('', $this->renderer()->htpasswd([]));
		self::assertSame(
			"alice:\$6\$abc\nbob:\$6\$def\n",
			$this->renderer()->htpasswd([['username' => 'alice', 'hash' => '$6$abc'], ['username' => 'bob', 'hash' => '$6$def']])
		);
	}

	public function testAuthSnippetEnabledWithIps(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$expected = "# generiert von vhost – nicht manuell bearbeiten\n"
			. "satisfy any;\n"
			. "allow 10.0.0.0/8;\n"
			. "allow 203.0.113.5;\n"
			. "deny all;\n"
			. "auth_basic \"Geschützter Bereich\";\n"
			. "auth_basic_user_file /etc/nginx/auth/example.com.htpasswd;\n";
		self::assertSame($expected, $this->renderer()->authSnippet($v, ['10.0.0.0/8', '203.0.113.5']));
	}

	public function testAuthSnippetDisabled(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, false, false);
		self::assertSame(
			"# generiert von vhost – nicht manuell bearbeiten\n# Verzeichnisschutz deaktiviert\n",
			$this->renderer()->authSnippet($v, ['10.0.0.0/8'])
		);
	}

	public function testDomainWithoutSsl(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public', true, false);
		$expected = <<<NG
# generiert von vhost – nicht manuell bearbeiten
server {
    listen 80;
    listen [::]:80;
    server_name example.com;
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root /var/www/example.com;
    }
    root /var/www/example.com/public;
    index index.html index.htm;
    access_log /var/log/nginx/example.com.access.log;
    error_log  /var/log/nginx/example.com.error.log;
    include /etc/nginx/auth/example.com.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}

	public function testDomainWithSslRedirectsAndServes443(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n", $out);
		self::assertStringContainsString("    listen 443 ssl;\n    listen [::]:443 ssl;\n    http2 on;\n", $out);
		self::assertStringContainsString('ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;', $out);
		self::assertStringContainsString('ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;', $out);
		self::assertStringContainsString("ssl_protocols TLSv1.2 TLSv1.3;", $out);
		self::assertSame(2, substr_count($out, 'server {'));
		self::assertSame(2, substr_count($out, 'acme-challenge'));
		self::assertSame(1, substr_count($out, 'include /etc/nginx/auth/example.com.conf;'));
	}

	public function testLocalhostBindsLoopbackOnly(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		$expected = <<<NG
# generiert von vhost – nicht manuell bearbeiten
server {
    listen 127.0.0.1:3000;
    listen [::1]:3000;
    server_name localhost;
    root /var/www/localhost-3000;
    index index.html index.htm;
    access_log /var/log/nginx/localhost-3000.access.log;
    error_log  /var/log/nginx/localhost-3000.error.log;
    include /etc/nginx/auth/localhost-3000.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}

	public function testWithoutIpv6NoBracketListens(): void
	{
		$domain = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$local = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, true, false);
		self::assertStringNotContainsString('[::', $this->renderer(false)->serverConfig($domain));
		self::assertStringNotContainsString('[::', $this->renderer(false)->serverConfig($local));
	}
}
```
Hinweis: In den nginx-Blöcken sind die Einrückungen **vier Leerzeichen** (so schreibt es auch die heutige Version; nginx-Dateien sind kein Projektcode). Die PHP-Zeilen davor/danach sind mit Tabs eingerückt.

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Nginx`
Erwartet: „Class VhostAdmin\Nginx\ConfigRenderer not found“.

- [ ] **Step 3: Renderer implementieren**

`src/lib/VhostAdmin/Nginx/ConfigRenderer.php`:
```php
<?php
declare(strict_types=1);

/**
 * Erzeugt nginx-Konfigurationstexte für einen vHost – ohne Dateizugriff.
 *
 * Domains lauschen öffentlich auf 80 (und 443 mit Zertifikat), localhost-Hosts
 * nur auf 127.0.0.1/[::1]. Der ACME-Pfad ist immer frei erreichbar, damit
 * certbot auch bei aktivem Verzeichnisschutz durchkommt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:50
 */

namespace VhostAdmin\Nginx;

use VhostAdmin\Config;
use VhostAdmin\Vhost;

final class ConfigRenderer
{
	private const HEADER = "# generiert von vhost – nicht manuell bearbeiten\n";

	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Pfad der htpasswd-Datei des vHosts.
	 */
	public function htpasswdPath(Vhost $v): string
	{
		return $this->config->authDir . '/' . $v->slug() . '.htpasswd';
	}

	/**
	 * Pfad des Auth-Snippets, das der Server-Block einbindet.
	 */
	public function authSnippetPath(Vhost $v): string
	{
		return $this->config->authDir . '/' . $v->slug() . '.conf';
	}

	/**
	 * Pfad der Server-Konfiguration in sites-available.
	 */
	public function serverConfigPath(Vhost $v): string
	{
		return $this->config->sitesAvailable . '/' . $v->slug() . '.conf';
	}

	/**
	 * Inhalt der htpasswd-Datei: eine Zeile "benutzer:hash" je Benutzer.
	 *
	 * @param list<array{username: string, hash: string}> $users
	 */
	public function htpasswd(array $users): string
	{
		$lines = '';
		foreach ($users as $user) {
			$lines .= $user['username'] . ':' . $user['hash'] . "\n";
		}
		return $lines;
	}

	/**
	 * Auth-Snippet: freigegebene IPs ODER gültiger Login (satisfy any).
	 * Ohne IPs und ohne Benutzer ist der Docroot komplett gesperrt.
	 *
	 * @param list<string> $ips
	 */
	public function authSnippet(Vhost $v, array $ips): string
	{
		if (!$v->protect) {
			return self::HEADER . "# Verzeichnisschutz deaktiviert\n";
		}
		$out = self::HEADER . "satisfy any;\n";
		foreach ($ips as $ip) {
			$out .= "allow $ip;\n";
		}
		return $out . "deny all;\nauth_basic \"Geschützter Bereich\";\nauth_basic_user_file " . $this->htpasswdPath($v) . ";\n";
	}

	/**
	 * Vollständige Server-Konfiguration des vHosts.
	 */
	public function serverConfig(Vhost $v): string
	{
		$slug = $v->slug();
		$root = $v->docroot($this->config);
		$authInclude = $this->authSnippetPath($v);
		$common = <<<NG
    root $root;
    index index.html index.htm;
    access_log /var/log/nginx/$slug.access.log;
    error_log  /var/log/nginx/$slug.error.log;
    include $authInclude;
    location / {
        try_files \$uri \$uri/ =404;
    }
NG;

		if ($v->isLocal()) {
			$listen = "    listen 127.0.0.1:{$v->port};\n" . ($this->config->ipv6 ? "    listen [::1]:{$v->port};\n" : '');
			return self::HEADER . "server {\n$listen    server_name localhost;\n$common\n}\n";
		}

		$base = $v->baseDir($this->config);
		$acme = <<<NG
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root $base;
    }
NG;
		$listen80 = "    listen 80;\n" . ($this->config->ipv6 ? "    listen [::]:80;\n" : '');
		if (!$v->ssl) {
			return self::HEADER . "server {\n$listen80    server_name {$v->name};\n$acme\n$common\n}\n";
		}

		$listen443 = "    listen 443 ssl;\n" . ($this->config->ipv6 ? "    listen [::]:443 ssl;\n" : '') . "    http2 on;\n";
		$live = $this->config->letsEncryptLive . '/' . $v->name;
		$ssl = <<<NG
    ssl_certificate     $live/fullchain.pem;
    ssl_certificate_key $live/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
NG;
		return self::HEADER
			. "server {\n$listen80    server_name {$v->name};\n$acme\n    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n"
			. "server {\n$listen443    server_name {$v->name};\n$ssl\n$common\n}\n";
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/Nginx/ConfigRenderer.php tests/Nginx/ConfigRendererTest.php
git commit -m "Nginx\\ConfigRenderer als reine Klasse mit zeichengenauen Tests"
```

---

### Task 7: Reloader- und Certbot-Schnittstellen mit Implementierungen und Fakes

**Files:**
- Create: `src/lib/VhostAdmin/Nginx/ReloaderInterface.php`, `src/lib/VhostAdmin/Nginx/SystemdReloader.php`, `src/lib/VhostAdmin/Ssl/CertbotInterface.php`, `src/lib/VhostAdmin/Ssl/CertbotClient.php`, `tests/Support/FakeReloader.php`, `tests/Support/FakeCertbot.php`
- Test: `tests/Support/FakesTest.php`

**Interfaces:**
- Produces:
  - `Nginx\ReloaderInterface::reload(): void` – wirft `\RuntimeException`, wenn `nginx -t` oder der Reload scheitert
  - `Nginx\SystemdReloader implements ReloaderInterface` (nginx -t, `systemctl reload-or-restart nginx`, wartet bis zu 5 s, bis die alten Worker beendet sind)
  - `Ssl\CertbotInterface::obtain(string $domain, string $webroot, string $email): string` – liefert die certbot-Ausgabe, wirft `\RuntimeException` bei Fehlschlag
  - `Ssl\CertbotClient implements CertbotInterface`
  - `Tests\Support\FakeReloader` (`public int $calls`, `public ?string $failWith`)
  - `Tests\Support\FakeCertbot::__construct(string $liveDir)` (`public array $calls`, `public bool $succeed`; legt bei Erfolg `<liveDir>/<domain>/fullchain.pem` und `privkey.pem` an)

- [ ] **Step 1: Fehlschlagenden Test für die Fakes schreiben**

`tests/Support/FakesTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests der Test-Doubles selbst, damit VhostServiceTest ihnen vertrauen kann.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Nginx\ReloaderInterface;
use VhostAdmin\Ssl\CertbotInterface;

final class FakesTest extends TestCase
{
	public function testFakeReloaderCountsAndFails(): void
	{
		$r = new FakeReloader();
		self::assertInstanceOf(ReloaderInterface::class, $r);
		$r->reload();
		$r->reload();
		self::assertSame(2, $r->calls);
		$r->failWith = 'kaputt';
		$this->expectException(\RuntimeException::class);
		$r->reload();
	}

	public function testFakeCertbotWritesCertificateFiles(): void
	{
		$dir = TempDir::create();
		try {
			$c = new FakeCertbot($dir);
			self::assertInstanceOf(CertbotInterface::class, $c);
			$out = $c->obtain('example.com', '/var/www/example.com', 'a@b.de');
			self::assertStringContainsString('example.com', $out);
			self::assertFileExists($dir . '/example.com/fullchain.pem');
			self::assertFileExists($dir . '/example.com/privkey.pem');
			self::assertSame([['example.com', '/var/www/example.com', 'a@b.de']], $c->calls);
			$c->succeed = false;
			$this->expectException(\RuntimeException::class);
			$c->obtain('fail.com', '/x', 'a@b.de');
		} finally {
			TempDir::remove($dir);
		}
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Support`
Erwartet: „Class Tests\Support\FakeReloader not found“.

- [ ] **Step 3: Schnittstellen, Implementierungen und Fakes schreiben**

`src/lib/VhostAdmin/Nginx/ReloaderInterface.php`:
```php
<?php
declare(strict_types=1);

/**
 * Prüft die nginx-Konfiguration und lädt sie neu.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Nginx;

interface ReloaderInterface
{
	/**
	 * @throws \RuntimeException wenn die Konfiguration fehlerhaft ist oder der Reload scheitert
	 */
	public function reload(): void;
}
```

`src/lib/VhostAdmin/Nginx/SystemdReloader.php`:
```php
<?php
declare(strict_types=1);

/**
 * nginx über systemd neu laden.
 *
 * "nginx -s reload" kehrt sofort zurück, während der Master die alten Worker
 * erst nach und nach ersetzt. Damit Aufrufer direkt danach die neue
 * Konfiguration sehen, wartet diese Klasse, bis die alten Worker-PIDs
 * verschwunden sind (höchstens fünf Sekunden).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Nginx;

final class SystemdReloader implements ReloaderInterface
{
	public function reload(): void
	{
		exec('nginx -t 2>&1', $testOutput, $testCode);
		if ($testCode !== 0) {
			throw new \RuntimeException("nginx -t fehlgeschlagen:\n" . implode("\n", $testOutput));
		}
		$oldWorkers = $this->workerPids();
		exec('systemctl reload-or-restart nginx 2>&1', $reloadOutput, $reloadCode);
		if ($reloadCode !== 0) {
			throw new \RuntimeException("nginx-Reload fehlgeschlagen:\n" . implode("\n", $reloadOutput));
		}
		for ($i = 0; $i < 100 && $oldWorkers !== []; $i++) {
			usleep(50000);
			$oldWorkers = array_filter($oldWorkers, static fn(int $pid): bool => file_exists("/proc/$pid"));
		}
	}

	/**
	 * PIDs der aktuellen nginx-Worker (Kinder des Master-Prozesses).
	 *
	 * @return list<int>
	 */
	private function workerPids(): array
	{
		$master = (int)trim((string)shell_exec('systemctl show -p MainPID --value nginx'));
		if ($master <= 0) {
			return [];
		}
		$pids = array_map('intval', explode("\n", trim((string)shell_exec('pgrep -P ' . $master))));
		return array_values(array_filter($pids));
	}
}
```

`src/lib/VhostAdmin/Ssl/CertbotInterface.php`:
```php
<?php
declare(strict_types=1);

/**
 * Beschafft ein Let's-Encrypt-Zertifikat für eine Domain.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Ssl;

interface CertbotInterface
{
	/**
	 * @param string $domain  Domain, für die das Zertifikat gilt
	 * @param string $webroot Verzeichnis, unter dem certbot .well-known/acme-challenge/ ablegt
	 * @param string $email   Registrierungsadresse bei Let's Encrypt
	 * @return string Ausgabe des Werkzeugs (für die Anzeige in der Oberfläche)
	 * @throws \RuntimeException wenn kein Zertifikat ausgestellt wurde
	 */
	public function obtain(string $domain, string $webroot, string $email): string;
}
```

`src/lib/VhostAdmin/Ssl/CertbotClient.php`:
```php
<?php
declare(strict_types=1);

/**
 * certbot im Webroot-Modus aufrufen.
 *
 * Nicht-interaktiv; bestehende, noch gültige Zertifikate bleiben erhalten
 * (--keep-until-expiring). Die Verlängerung übernimmt der certbot-Timer.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Ssl;

final class CertbotClient implements CertbotInterface
{
	public function obtain(string $domain, string $webroot, string $email): string
	{
		$command = sprintf(
			'certbot certonly --webroot -w %s -d %s -n --agree-tos --no-eff-email -m %s --keep-until-expiring 2>&1',
			escapeshellarg($webroot),
			escapeshellarg($domain),
			escapeshellarg($email)
		);
		exec($command, $output, $code);
		$text = implode("\n", $output);
		if ($code !== 0) {
			throw new \RuntimeException("certbot fehlgeschlagen:\n$text");
		}
		return $text;
	}
}
```

`tests/Support/FakeReloader.php`:
```php
<?php
declare(strict_types=1);

/**
 * Test-Double: zählt Reloads, kann auf Wunsch fehlschlagen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace Tests\Support;

use VhostAdmin\Nginx\ReloaderInterface;

final class FakeReloader implements ReloaderInterface
{
	public int $calls = 0;
	public ?string $failWith = null;

	public function reload(): void
	{
		$this->calls++;
		if ($this->failWith !== null) {
			throw new \RuntimeException($this->failWith);
		}
	}
}
```

`tests/Support/FakeCertbot.php`:
```php
<?php
declare(strict_types=1);

/**
 * Test-Double: simuliert certbot und legt Zertifikatsdateien im Live-Verzeichnis an.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace Tests\Support;

use VhostAdmin\Ssl\CertbotInterface;

final class FakeCertbot implements CertbotInterface
{
	/** @var list<array{string, string, string}> */
	public array $calls = [];
	public bool $succeed = true;

	public function __construct(private readonly string $liveDir)
	{
	}

	public function obtain(string $domain, string $webroot, string $email): string
	{
		$this->calls[] = [$domain, $webroot, $email];
		if (!$this->succeed) {
			throw new \RuntimeException('certbot fehlgeschlagen: Simulation');
		}
		$dir = $this->liveDir . '/' . $domain;
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}
		file_put_contents($dir . '/fullchain.pem', 'cert');
		file_put_contents($dir . '/privkey.pem', 'key');
		return "Simuliertes Zertifikat für $domain";
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/Nginx src/lib/VhostAdmin/Ssl tests/Support
git commit -m "Reloader- und Certbot-Schnittstellen mit systemd-/certbot-Implementierung und Test-Fakes"
```

---

### Task 8: VhostService (Anwendungsfälle)

**Files:**
- Create: `src/lib/VhostAdmin/VhostService.php`
- Test: `tests/VhostServiceTest.php`

**Interfaces:**
- Consumes: `Config`, `VhostRepository`, `Nginx\ConfigRenderer`, `Nginx\ReloaderInterface`, `Ssl\CertbotInterface`, Wertobjekte, `Vhost`, `VhostKind`
- Produces `VhostAdmin\VhostService::__construct(Config, VhostRepository, ConfigRenderer, ReloaderInterface, CertbotInterface)` mit:
  - `load(string $name): Vhost` – wirft `\RuntimeException("Unbekannter vHost: …")`
  - `createDomain(DomainName $domain, ?SubDirectory $subdir, bool $protect = true): Vhost`
  - `createLocal(Port $port, ?SubDirectory $subdir, bool $protect = true): Vhost`
  - `remove(Vhost $v, bool $purge = false): void`
  - `setProtection(Vhost $v, bool $on): void`
  - `addUser(Vhost $v, Username $user, string $password): void` – wirft bei leerem Passwort
  - `removeUser(Vhost $v, Username $user): void` – wirft, wenn unbekannt
  - `addIp(Vhost $v, Cidr $cidr): void`, `removeIp(Vhost $v, Cidr $cidr): void` – wirft, wenn unbekannt
  - `enableSsl(Vhost $v): string` (certbot-Ausgabe, leer wenn Zertifikat schon vorhanden) – wirft bei localhost, fehlender E-Mail oder certbot-Fehler
  - `disableSsl(Vhost $v): void`
  - `setLetsEncryptEmail(string $email): void` – leer löscht, ungültig wirft `\InvalidArgumentException`
  - `letsEncryptEmail(): ?string`
  - `render(Vhost $v, bool $reload = true): void`, `renderAll(): void`
  - `fixDatabasePermissions(): void` (nur als root wirksam)

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`tests/VhostServiceTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests der Anwendungsfälle mit Temp-Verzeichnissen und Fakes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:10
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCertbot;
use Tests\Support\FakeReloader;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class VhostServiceTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private FakeReloader $reloader;
	private FakeCertbot $certbot;
	private VhostService $service;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/avail');
		mkdir($this->dir . '/enabled');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'sitesAvailable' => $this->dir . '/avail',
			'sitesEnabled' => $this->dir . '/enabled',
			'authDir' => $this->dir . '/auth',
			'letsEncryptLive' => $this->dir . '/le',
			'ipv6' => false,
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->reloader = new FakeReloader();
		$this->certbot = new FakeCertbot($this->dir . '/le');
		$this->service = new VhostService($this->config, $this->repo, new ConfigRenderer($this->config), $this->reloader, $this->certbot);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testCreateDomainWithSubdirectoryCreatesFilesAndReloadsOnce(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('Example.com'), SubDirectory::fromString('public/html'));
		self::assertSame('example.com', $v->name);
		self::assertDirectoryExists($this->dir . '/www/example.com/public/html');
		$index = $this->dir . '/www/example.com/public/html/index.html';
		self::assertFileExists($index);
		self::assertStringContainsString('example.com', (string)file_get_contents($index));
		self::assertStringContainsString('<h1>200</h1>', (string)file_get_contents($index));
		self::assertFileExists($this->dir . '/avail/example.com.conf');
		self::assertTrue(is_link($this->dir . '/enabled/example.com.conf'));
		self::assertFileExists($this->dir . '/auth/example.com.conf');
		self::assertFileExists($this->dir . '/auth/example.com.htpasswd');
		self::assertStringContainsString('deny all;', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertSame('', file_get_contents($this->dir . '/auth/example.com.htpasswd'));
		self::assertSame(1, $this->reloader->calls);
	}

	public function testCreateLocalBindsLoopback(): void
	{
		$v = $this->service->createLocal(Port::fromString('3000'), null, false);
		self::assertSame('localhost:3000', $v->name);
		self::assertFalse($v->protect);
		self::assertDirectoryExists($this->dir . '/www/localhost-3000');
		$conf = (string)file_get_contents($this->dir . '/avail/localhost-3000.conf');
		self::assertStringContainsString('listen 127.0.0.1:3000;', $conf);
		self::assertStringContainsString('Verzeichnisschutz deaktiviert', (string)file_get_contents($this->dir . '/auth/localhost-3000.conf'));
	}

	public function testCreateKeepsExistingIndex(): void
	{
		mkdir($this->dir . '/www/example.com', 0777, true);
		file_put_contents($this->dir . '/www/example.com/index.html', 'eigene Seite');
		$this->service->createDomain(DomainName::fromString('example.com'), null);
		self::assertSame('eigene Seite', file_get_contents($this->dir . '/www/example.com/index.html'));
	}

	public function testCreateDuplicateThrowsWithoutReload(): void
	{
		$this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->expectException(\RuntimeException::class);
		try {
			$this->service->createDomain(DomainName::fromString('example.com'), null);
		} finally {
			self::assertSame(1, $this->reloader->calls);
		}
	}

	public function testLoadUnknownThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unbekannter vHost');
		$this->service->load('nix.example');
	}

	public function testProtectionToggleRewritesSnippet(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->setProtection($v, false);
		self::assertStringNotContainsString('deny all', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertFalse($this->service->load('example.com')->protect);
		$this->service->setProtection($this->service->load('example.com'), true);
		self::assertStringContainsString('deny all', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertSame(3, $this->reloader->calls);
	}

	public function testUsersAndIpsEndUpInFiles(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->addUser($v, Username::fromString('alice'), 'geheim');
		$this->service->addIp($v, Cidr::fromString('10.0.0.0/8'));
		$htpasswd = (string)file_get_contents($this->dir . '/auth/example.com.htpasswd');
		self::assertMatchesRegularExpression('/^alice:\$6\$[^\n]+\n$/', $htpasswd);
		$hash = trim(substr($htpasswd, strlen('alice:')));
		self::assertSame($hash, crypt('geheim', $hash));
		self::assertNotSame($hash, crypt('falsch', $hash));
		self::assertStringContainsString("allow 10.0.0.0/8;\n", (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		$this->service->removeUser($v, Username::fromString('alice'));
		$this->service->removeIp($v, Cidr::fromString('10.0.0.0/8'));
		self::assertSame('', file_get_contents($this->dir . '/auth/example.com.htpasswd'));
		self::assertStringNotContainsString('allow ', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
	}

	public function testEmptyPasswordIsRejected(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->expectException(\RuntimeException::class);
		$this->service->addUser($v, Username::fromString('alice'), '');
	}

	public function testRemovingUnknownUserOrIpThrows(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		try {
			$this->service->removeUser($v, Username::fromString('nobody'));
			self::fail('Exception erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('nobody', $e->getMessage());
		}
		$this->expectException(\RuntimeException::class);
		$this->service->removeIp($v, Cidr::fromString('192.0.2.1'));
	}

	public function testSslNeedsEmail(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		try {
			$this->service->enableSsl($v);
			self::fail('Exception erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('E-Mail', $e->getMessage());
		}
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertSame([], $this->certbot->calls);
	}

	public function testSslRejectsLocalhost(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createLocal(Port::fromString('3000'), null);
		$this->expectException(\RuntimeException::class);
		$this->service->enableSsl($v);
	}

	public function testSslSuccessWritesHttpsConfig(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$output = $this->service->enableSsl($v);
		self::assertStringContainsString('Simuliertes Zertifikat', $output);
		self::assertSame([['example.com', $this->dir . '/www/example.com', 'admin@example.com']], $this->certbot->calls);
		self::assertTrue($this->service->load('example.com')->ssl);
		$conf = (string)file_get_contents($this->dir . '/avail/example.com.conf');
		self::assertStringContainsString('listen 443 ssl;', $conf);
		self::assertStringContainsString('return 301 https://', $conf);
		$this->service->disableSsl($this->service->load('example.com'));
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertStringNotContainsString('443', (string)file_get_contents($this->dir . '/avail/example.com.conf'));
	}

	public function testSslSkipsCertbotWhenCertificateExists(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		mkdir($this->dir . '/le/example.com', 0700, true);
		file_put_contents($this->dir . '/le/example.com/fullchain.pem', 'x');
		self::assertSame('', $this->service->enableSsl($v));
		self::assertSame([], $this->certbot->calls);
		self::assertTrue($this->service->load('example.com')->ssl);
	}

	public function testSslFailureLeavesSslOff(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->certbot->succeed = false;
		try {
			$this->service->enableSsl($v);
			self::fail('Exception erwartet');
		} catch (\RuntimeException) {
		}
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertStringNotContainsString('443', (string)file_get_contents($this->dir . '/avail/example.com.conf'));
	}

	public function testLetsEncryptEmailValidation(): void
	{
		self::assertNull($this->service->letsEncryptEmail());
		$this->service->setLetsEncryptEmail('admin@example.com');
		self::assertSame('admin@example.com', $this->service->letsEncryptEmail());
		$this->service->setLetsEncryptEmail('');
		self::assertNull($this->service->letsEncryptEmail());
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setLetsEncryptEmail('keine-adresse');
	}

	public function testRemoveKeepsFilesUnlessPurged(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->remove($v);
		self::assertNull($this->repo->byName('example.com'));
		self::assertFileDoesNotExist($this->dir . '/avail/example.com.conf');
		self::assertFalse(is_link($this->dir . '/enabled/example.com.conf'));
		self::assertFileDoesNotExist($this->dir . '/auth/example.com.conf');
		self::assertFileDoesNotExist($this->dir . '/auth/example.com.htpasswd');
		self::assertDirectoryExists($this->dir . '/www/example.com');

		$w = $this->service->createDomain(DomainName::fromString('purge.example'), SubDirectory::fromString('public'));
		$this->service->remove($w, true);
		self::assertDirectoryDoesNotExist($this->dir . '/www/purge.example');
		self::assertDirectoryExists($this->dir . '/www');
	}

	public function testRenderAllReloadsOnce(): void
	{
		$this->service->createDomain(DomainName::fromString('a.example'), null);
		$this->service->createLocal(Port::fromString('3001'), null);
		unlink($this->dir . '/avail/a.example.conf');
		$before = $this->reloader->calls;
		$this->service->renderAll();
		self::assertFileExists($this->dir . '/avail/a.example.conf');
		self::assertSame($before + 1, $this->reloader->calls);
	}

	public function testReloadFailurePropagates(): void
	{
		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('nginx -t');
		$this->service->createDomain(DomainName::fromString('example.com'), null);
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/VhostServiceTest.php`
Erwartet: „Class VhostAdmin\VhostService not found“.

- [ ] **Step 3: Service implementieren**

`src/lib/VhostAdmin/VhostService.php`:
```php
<?php
declare(strict_types=1);

/**
 * Anwendungsfälle der vHost-Verwaltung.
 *
 * Verbindet Repository, Dateisystem, nginx-Renderer, Reloader und certbot.
 * Jede Änderung endet mit dem Neuschreiben der nginx-Dateien und genau einem
 * Reload. Besitzerwechsel geschehen nur als root (im CLI), Tests laufen ohne.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:10
 */

namespace VhostAdmin;

use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\ReloaderInterface;
use VhostAdmin\Ssl\CertbotInterface;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;

final class VhostService
{
	private const SETTING_EMAIL = 'le_email';

	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly ConfigRenderer $renderer,
		private readonly ReloaderInterface $reloader,
		private readonly CertbotInterface $certbot,
	) {
	}

	/**
	 * vHost nach Name laden.
	 *
	 * @throws \RuntimeException wenn unbekannt
	 */
	public function load(string $name): Vhost
	{
		return $this->repository->byName($name) ?? throw new \RuntimeException("Unbekannter vHost: $name");
	}

	/**
	 * Öffentliche Domain anlegen (Docroot /var/www/<domain>[/<subdir>]).
	 */
	public function createDomain(DomainName $domain, ?SubDirectory $subdir, bool $protect = true): Vhost
	{
		return $this->create($domain->value, VhostKind::Domain, null, $subdir, $protect);
	}

	/**
	 * Nur lokal erreichbaren Host anlegen (Docroot /var/www/localhost-<port>[/<subdir>]).
	 */
	public function createLocal(Port $port, ?SubDirectory $subdir, bool $protect = true): Vhost
	{
		return $this->create('localhost:' . $port->value, VhostKind::Localhost, $port->value, $subdir, $protect);
	}

	private function create(string $name, VhostKind $kind, ?int $port, ?SubDirectory $subdir, bool $protect): Vhost
	{
		$vhost = $this->repository->insert($name, $kind, $port, $subdir?->value, $protect);
		$this->makeDirectories($vhost, $subdir);
		$this->writeIndex($vhost);
		$this->render($vhost);
		return $vhost;
	}

	/**
	 * vHost entfernen: nginx-Dateien und Datenbankeintrag; Dateien nur mit $purge.
	 */
	public function remove(Vhost $vhost, bool $purge = false): void
	{
		$files = [
			$this->config->sitesEnabled . '/' . $vhost->slug() . '.conf',
			$this->renderer->serverConfigPath($vhost),
			$this->renderer->authSnippetPath($vhost),
			$this->renderer->htpasswdPath($vhost),
		];
		foreach ($files as $file) {
			if (is_link($file) || file_exists($file)) {
				unlink($file);
			}
		}
		$this->repository->delete($vhost->id);
		if ($purge) {
			$base = $vhost->baseDir($this->config);
			if (str_starts_with($base, $this->config->wwwRoot . '/') && is_dir($base)) {
				exec('rm -rf ' . escapeshellarg($base));
			}
		}
		$this->reloader->reload();
	}

	/**
	 * Verzeichnisschutz ein- oder ausschalten.
	 */
	public function setProtection(Vhost $vhost, bool $on): void
	{
		$this->repository->setProtect($vhost->id, $on);
		$this->render($this->load($vhost->name));
	}

	/**
	 * Benutzer anlegen oder Passwort setzen (SHA-512-crypt, von nginx lesbar).
	 *
	 * @throws \RuntimeException bei leerem Passwort
	 */
	public function addUser(Vhost $vhost, Username $user, string $password): void
	{
		if ($password === '') {
			throw new \RuntimeException('Leeres Passwort');
		}
		$this->repository->upsertUser($vhost->id, $user->value, $this->hashPassword($password));
		$this->render($vhost);
	}

	/**
	 * @throws \RuntimeException wenn der Benutzer nicht existiert
	 */
	public function removeUser(Vhost $vhost, Username $user): void
	{
		if (!$this->repository->deleteUser($vhost->id, $user->value)) {
			throw new \RuntimeException("Benutzer nicht vorhanden: {$user->value}");
		}
		$this->render($vhost);
	}

	/**
	 * IP oder Netz ohne Login freigeben.
	 */
	public function addIp(Vhost $vhost, Cidr $cidr): void
	{
		$this->repository->addIp($vhost->id, $cidr->value);
		$this->render($vhost);
	}

	/**
	 * @throws \RuntimeException wenn die Freigabe nicht existiert
	 */
	public function removeIp(Vhost $vhost, Cidr $cidr): void
	{
		if (!$this->repository->deleteIp($vhost->id, $cidr->value)) {
			throw new \RuntimeException("IP nicht vorhanden: {$cidr->value}");
		}
		$this->render($vhost);
	}

	/**
	 * Zertifikat beschaffen (falls noch keins liegt) und HTTPS einschalten.
	 *
	 * @return string Ausgabe von certbot, leer wenn das Zertifikat schon vorhanden war
	 * @throws \RuntimeException bei localhost, fehlender E-Mail oder certbot-Fehler
	 */
	public function enableSsl(Vhost $vhost): string
	{
		if ($vhost->isLocal()) {
			throw new \RuntimeException("Let's Encrypt nur für echte Domains");
		}
		$email = $this->letsEncryptEmail()
			?? throw new \RuntimeException("Keine Let's-Encrypt-E-Mail hinterlegt (Einstellungen / \"vhost set le_email ...\")");
		$output = '';
		if (!file_exists($this->config->letsEncryptLive . '/' . $vhost->name . '/fullchain.pem')) {
			$output = $this->certbot->obtain($vhost->name, $vhost->baseDir($this->config), $email);
		}
		$this->repository->setSsl($vhost->id, true);
		$this->render($this->load($vhost->name));
		return $output;
	}

	/**
	 * HTTPS abschalten; das Zertifikat bleibt für ein späteres Wiedereinschalten liegen.
	 */
	public function disableSsl(Vhost $vhost): void
	{
		$this->repository->setSsl($vhost->id, false);
		$this->render($this->load($vhost->name));
	}

	/**
	 * Registrierungsadresse für Let's Encrypt setzen; leer löscht sie.
	 *
	 * @throws \InvalidArgumentException bei ungültiger Adresse
	 */
	public function setLetsEncryptEmail(string $email): void
	{
		$email = trim($email);
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new \InvalidArgumentException("Ungültige E-Mail-Adresse: $email");
		}
		$this->repository->setSetting(self::SETTING_EMAIL, $email);
	}

	public function letsEncryptEmail(): ?string
	{
		return $this->repository->setting(self::SETTING_EMAIL);
	}

	/**
	 * htpasswd, Auth-Snippet und Server-Konfiguration schreiben, Symlink setzen, optional neu laden.
	 */
	public function render(Vhost $vhost, bool $reload = true): void
	{
		if (!is_dir($this->config->authDir)) {
			mkdir($this->config->authDir, 0750, true);
			$this->group($this->config->authDir);
		}
		$htpasswd = $this->renderer->htpasswdPath($vhost);
		file_put_contents($htpasswd, $this->renderer->htpasswd($this->repository->users($vhost->id)));
		$this->group($htpasswd);
		chmod($htpasswd, 0640);

		file_put_contents($this->renderer->authSnippetPath($vhost), $this->renderer->authSnippet($vhost, $this->repository->ips($vhost->id)));

		$available = $this->renderer->serverConfigPath($vhost);
		file_put_contents($available, $this->renderer->serverConfig($vhost));
		$enabled = $this->config->sitesEnabled . '/' . $vhost->slug() . '.conf';
		if (!is_link($enabled)) {
			symlink($available, $enabled);
		}
		if ($reload) {
			$this->reloader->reload();
		}
	}

	/**
	 * Alle vHosts neu schreiben und einmal neu laden (Installation, Umzug).
	 */
	public function renderAll(): void
	{
		foreach ($this->repository->all() as $vhost) {
			$this->render($vhost, false);
		}
		$this->reloader->reload();
	}

	/**
	 * Datenbankdatei und -verzeichnis für die Oberfläche (www-data) lesbar machen.
	 * Wirkt nur als root; das CLI ruft sie nach jedem Befehl.
	 */
	public function fixDatabasePermissions(): void
	{
		if (!$this->isRoot()) {
			return;
		}
		$dir = dirname($this->config->dbPath);
		if (is_dir($dir)) {
			chown($dir, $this->config->wwwGroup);
			chgrp($dir, $this->config->wwwGroup);
			chmod($dir, 0770);
		}
		foreach (glob($this->config->dbPath . '*') ?: [] as $file) {
			chown($file, $this->config->wwwGroup);
			chgrp($file, $this->config->wwwGroup);
			chmod($file, 0660);
		}
	}

	private function makeDirectories(Vhost $vhost, ?SubDirectory $subdir): void
	{
		$docroot = $vhost->docroot($this->config);
		if (!is_dir($docroot) && !mkdir($docroot, 0775, true)) {
			throw new \RuntimeException("Kann $docroot nicht anlegen");
		}
		$path = $vhost->baseDir($this->config);
		$this->own($path, 02775);
		foreach ($subdir?->segments() ?? [] as $segment) {
			$path .= '/' . $segment;
			$this->own($path, 02775);
		}
	}

	private function writeIndex(Vhost $vhost): void
	{
		$file = $vhost->docroot($this->config) . '/index.html';
		if (file_exists($file)) {
			return;
		}
		$html = strtr((string)file_get_contents($this->config->templatePath), [
			'{{NAME}}' => htmlspecialchars($vhost->name, ENT_QUOTES, 'UTF-8'),
			'{{DOCROOT}}' => htmlspecialchars($vhost->docroot($this->config), ENT_QUOTES, 'UTF-8'),
		]);
		file_put_contents($file, $html);
		$this->own($file, 0664);
	}

	/**
	 * Besitzer/Gruppe/Rechte setzen; ohne root nur die Rechte.
	 */
	private function own(string $path, int $mode): void
	{
		if ($this->isRoot()) {
			if (!@chown($path, $this->config->wwwOwner)) {
				chown($path, $this->config->wwwGroup);
			}
			chgrp($path, $this->config->wwwGroup);
		}
		chmod($path, $mode);
	}

	private function group(string $path): void
	{
		if ($this->isRoot()) {
			chgrp($path, $this->config->wwwGroup);
		}
	}

	private function isRoot(): bool
	{
		return function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
	}

	/**
	 * SHA-512-crypt-Hash, den nginx (libxcrypt) auswerten kann.
	 */
	private function hashPassword(string $password): string
	{
		return crypt($password, '$6$' . substr(bin2hex(random_bytes(12)), 0, 16) . '$');
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün. Falls `testUsersAndIpsEndUpInFiles` an `crypt()` scheitert, prüfe, dass PHP mit libxcrypt gebaut ist (`php -r 'echo crypt("a", "\$6\$abc\$");'` muss mit `$6$` beginnen).

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/VhostService.php tests/VhostServiceTest.php
git commit -m "VhostService mit Anwendungsfällen und Tests über Fakes"
```

---

### Task 9: Cli\Application und CLI-Einstieg

**Files:**
- Create: `src/lib/VhostAdmin/Cli/Application.php`
- Rewrite: `src/bin/vhost.php` (Composition Root), `src/bin/vhost` (unverändert lassen, nur prüfen)
- Test: `tests/Cli/ApplicationTest.php`

**Interfaces:**
- Consumes: `VhostService`, `VhostRepository`, `Config`, Wertobjekte
- Produces `VhostAdmin\Cli\Application`:
  - `const USAGE` (Hilfetext)
  - `static parse(array $argv): array{command: string, positional: list<string>, options: array<string, string|true>}` – `$argv[0]` ist der Programmname; `--k=v`, `--subdir v` (Wertoption), sonst Flags
  - `__construct(VhostService $service, VhostRepository $repository, Config $config, $stdin, $stdout, $stderr)` (Streams als Ressourcen)
  - `run(array $argv): int` – Exit-Code 0 ok, 1 Fehler (Meldung „Fehler: …“ auf stderr), 2 Usage-Fehler

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`tests/Cli/ApplicationTest.php`:
```php
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
	 * @param list<string> $args
	 * @return array{int, string, string}
	 */
	private function run(array $args, string $stdin = ''): array
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
		[$code, $out] = $this->run(['help']);
		self::assertSame(0, $code);
		self::assertStringContainsString('vhost add <domain>', $out);
		[$code, , $err] = $this->run(['gibtsnicht']);
		self::assertSame(2, $code);
		self::assertStringContainsString('vhost add <domain>', $err);
	}

	public function testMissingArgumentIsExitOne(): void
	{
		[$code, , $err] = $this->run(['add']);
		self::assertSame(1, $code);
		self::assertSame("Fehler: Domain fehlt\n", $err);
	}

	public function testAddListAndRemove(): void
	{
		[$code, $out] = $this->run(['add', 'Test.example', '--subdir', 'public']);
		self::assertSame(0, $code);
		self::assertSame("Angelegt: test.example -> {$this->dir}/www/test.example/public\n", $out);
		self::assertDirectoryExists($this->dir . '/www/test.example/public');

		[$code, $out] = $this->run(['add-local', '3000', '--no-protect']);
		self::assertSame(0, $code);
		self::assertStringContainsString('localhost:3000', $out);

		[, $out] = $this->run(['list']);
		self::assertStringContainsString('test.example', $out);
		self::assertStringContainsString('schutz:an', $out);
		self::assertStringContainsString('localhost:3000', $out);
		self::assertStringContainsString('schutz:aus', $out);

		[$code] = $this->run(['remove', 'test.example', '--purge']);
		self::assertSame(0, $code);
		self::assertDirectoryDoesNotExist($this->dir . '/www/test.example');
		[$code, , $err] = $this->run(['remove', 'test.example']);
		self::assertSame(1, $code);
		self::assertStringContainsString('Unbekannter vHost', $err);
	}

	public function testInvalidDomainIsExitOne(): void
	{
		[$code, , $err] = $this->run(['add', 'localhost:3000']);
		self::assertSame(1, $code);
		self::assertStringContainsString('add-local', $err);
	}

	public function testUserPasswordComesFromStdin(): void
	{
		$this->run(['add', 'a.example']);
		[$code, $out] = $this->run(['user-add', 'a.example', 'alice'], "geheim\n");
		self::assertSame(0, $code);
		self::assertSame("Benutzer alice gespeichert.\n", $out);
		self::assertStringStartsWith('alice:$6$', (string)file_get_contents($this->dir . '/auth/a.example.htpasswd'));
		[$code, , $err] = $this->run(['user-add', 'a.example', 'bob'], "\n");
		self::assertSame(1, $code);
		self::assertStringContainsString('Leeres Passwort', $err);
		[$code] = $this->run(['user-del', 'a.example', 'alice']);
		self::assertSame(0, $code);
		self::assertSame('', file_get_contents($this->dir . '/auth/a.example.htpasswd'));
	}

	public function testProtectIpsAndSsl(): void
	{
		$this->run(['add', 'a.example']);
		[$code, $out] = $this->run(['protect', 'a.example', 'off']);
		self::assertSame(0, $code);
		self::assertSame("Verzeichnisschutz deaktiviert.\n", $out);
		[$code, , $err] = $this->run(['protect', 'a.example', 'maybe']);
		self::assertSame(1, $code);
		self::assertStringContainsString('on|off', $err);

		[$code, $out] = $this->run(['ip-add', 'a.example', '10.0.0.0/08']);
		self::assertSame(0, $code);
		self::assertSame("IP 10.0.0.0/8 freigegeben.\n", $out);
		[$code] = $this->run(['ip-del', 'a.example', '10.0.0.0/8']);
		self::assertSame(0, $code);

		[$code, , $err] = $this->run(['ssl', 'a.example', 'on']);
		self::assertSame(1, $code);
		self::assertStringContainsString('E-Mail', $err);
		[$code] = $this->run(['set', 'le_email', 'admin@example.com']);
		self::assertSame(0, $code);
		[$code, $out] = $this->run(['ssl', 'a.example', 'on']);
		self::assertSame(0, $code);
		self::assertStringContainsString('Simuliertes Zertifikat', $out);
		self::assertStringContainsString("Let's Encrypt aktiviert.", $out);
		[$code, , $err] = $this->run(['set', 'unbekannt', 'x']);
		self::assertSame(1, $code);
		self::assertStringContainsString('Unbekannte Einstellung', $err);
	}

	public function testRenderAndInit(): void
	{
		$this->run(['add', 'a.example']);
		unlink($this->dir . '/avail/a.example.conf');
		[$code, $out] = $this->run(['render']);
		self::assertSame(0, $code);
		self::assertStringContainsString('neu geschrieben', $out);
		self::assertFileExists($this->dir . '/avail/a.example.conf');
		[$code, $out] = $this->run(['init']);
		self::assertSame(0, $code);
		self::assertSame("Datenbank bereit.\n", $out);
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Cli`
Erwartet: „Class VhostAdmin\Cli\Application not found“.

- [ ] **Step 3: Application implementieren**

`src/lib/VhostAdmin/Cli/Application.php`:
```php
<?php
declare(strict_types=1);

/**
 * Kommandozeile "vhost": Argumente auswerten und an den Service weiterreichen.
 *
 * Läuft als root (sudo); die Oberfläche ruft dieselben Befehle über sudoers auf.
 * Passwörter kommen über stdin, nie als Argument (wären in "ps" sichtbar).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:20
 */

namespace VhostAdmin\Cli;

use VhostAdmin\Config;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
use VhostAdmin\Vhost;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class Application
{
	public const USAGE = <<<TXT
vhost – nginx-vHosts verwalten

  vhost list
  vhost add <domain> [--subdir DIR] [--no-protect]
  vhost add-local <port> [--subdir DIR] [--no-protect]
  vhost remove <name> [--purge]
  vhost protect <name> on|off
  vhost user-add <name> <user>        (Passwort per stdin)
  vhost user-del <name> <user>
  vhost ip-add <name> <ip|cidr>
  vhost ip-del <name> <ip|cidr>
  vhost ssl <name> on|off
  vhost set le_email <adresse>
  vhost render [name]
  vhost init

TXT;

	private const VALUE_OPTIONS = ['subdir'];

	/**
	 * @param resource $stdin
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(
		private readonly VhostService $service,
		private readonly VhostRepository $repository,
		private readonly Config $config,
		private $stdin,
		private $stdout,
		private $stderr,
	) {
	}

	/**
	 * Zerlegt argv in Befehl, Positionsargumente und Optionen.
	 *
	 * "--subdir X" und "--subdir=X" tragen einen Wert, alle anderen "--flag" sind Schalter.
	 *
	 * @param list<string> $argv inklusive Programmname an Position 0
	 * @return array{command: string, positional: list<string>, options: array<string, string|true>}
	 */
	public static function parse(array $argv): array
	{
		array_shift($argv);
		$command = array_shift($argv) ?? 'help';
		$positional = [];
		$options = [];
		$count = count($argv);
		for ($i = 0; $i < $count; $i++) {
			$arg = $argv[$i];
			if (!str_starts_with($arg, '--')) {
				$positional[] = $arg;
				continue;
			}
			$body = substr($arg, 2);
			if (str_contains($body, '=')) {
				[$key, $value] = explode('=', $body, 2);
				$options[$key] = $value;
			} elseif (in_array($body, self::VALUE_OPTIONS, true)) {
				$options[$body] = $argv[++$i] ?? '';
			} else {
				$options[$body] = true;
			}
		}
		return ['command' => $command, 'positional' => $positional, 'options' => $options];
	}

	/**
	 * Führt den Befehl aus und liefert den Exit-Code (0 ok, 1 Fehler, 2 Usage).
	 *
	 * @param list<string> $argv
	 */
	public function run(array $argv): int
	{
		['command' => $command, 'positional' => $positional, 'options' => $options] = self::parse($argv);
		if (in_array($command, ['help', '--help', '-h'], true)) {
			$this->out(self::USAGE);
			return 0;
		}
		try {
			$code = $this->dispatch($command, $positional, $options);
		} catch (\Throwable $e) {
			$this->err('Fehler: ' . $e->getMessage() . "\n");
			$code = 1;
		}
		$this->service->fixDatabasePermissions();
		return $code;
	}

	/**
	 * @param list<string> $positional
	 * @param array<string, string|true> $options
	 */
	private function dispatch(string $command, array $positional, array $options): int
	{
		$arg = static fn(int $index, string $what): string => $positional[$index] ?? throw new \RuntimeException("$what fehlt");
		$onOff = static fn(string $value): bool => match ($value) {
			'on' => true,
			'off' => false,
			default => throw new \RuntimeException('Erwartet on|off'),
		};
		$subdir = SubDirectory::fromString(is_string($options['subdir'] ?? null) ? $options['subdir'] : null);
		$protect = !isset($options['no-protect']);

		switch ($command) {
			case 'init':
				$this->out("Datenbank bereit.\n");
				return 0;

			case 'list':
				foreach ($this->repository->all() as $vhost) {
					$this->out(sprintf(
						"%-32s %-9s %-44s schutz:%-3s ssl:%s\n",
						$vhost->name,
						$vhost->kind->value,
						$vhost->docroot($this->config),
						$vhost->protect ? 'an' : 'aus',
						$vhost->ssl ? 'an' : 'aus'
					));
				}
				return 0;

			case 'add':
				$vhost = $this->service->createDomain(DomainName::fromString($arg(0, 'Domain')), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $vhost->docroot($this->config) . "\n");
				return 0;

			case 'add-local':
				$vhost = $this->service->createLocal(Port::fromString($arg(0, 'Port'), $this->config->adminPort), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $vhost->docroot($this->config) . "\n");
				return 0;

			case 'remove':
				$this->service->remove($this->service->load($arg(0, 'Name')), isset($options['purge']));
				$this->out("Entfernt.\n");
				return 0;

			case 'protect':
				$on = $onOff($arg(1, 'on|off'));
				$this->service->setProtection($this->service->load($arg(0, 'Name')), $on);
				$this->out('Verzeichnisschutz ' . ($on ? 'aktiviert' : 'deaktiviert') . ".\n");
				return 0;

			case 'user-add':
				$vhost = $this->service->load($arg(0, 'Name'));
				$user = Username::fromString($arg(1, 'Benutzer'));
				$password = rtrim((string)stream_get_contents($this->stdin), "\r\n");
				$this->service->addUser($vhost, $user, $password);
				$this->out("Benutzer {$user->value} gespeichert.\n");
				return 0;

			case 'user-del':
				$this->service->removeUser($this->service->load($arg(0, 'Name')), Username::fromString($arg(1, 'Benutzer')));
				$this->out("Benutzer entfernt.\n");
				return 0;

			case 'ip-add':
				$cidr = Cidr::fromString($arg(1, 'IP'));
				$this->service->addIp($this->service->load($arg(0, 'Name')), $cidr);
				$this->out("IP {$cidr->value} freigegeben.\n");
				return 0;

			case 'ip-del':
				$this->service->removeIp($this->service->load($arg(0, 'Name')), Cidr::fromString($arg(1, 'IP')));
				$this->out("IP entfernt.\n");
				return 0;

			case 'ssl':
				$on = $onOff($arg(1, 'on|off'));
				$vhost = $this->service->load($arg(0, 'Name'));
				if ($on) {
					$output = $this->service->enableSsl($vhost);
					if ($output !== '') {
						$this->out($output . "\n");
					}
				} else {
					$this->service->disableSsl($vhost);
				}
				$this->out("Let's Encrypt " . ($on ? 'aktiviert' : 'deaktiviert') . ".\n");
				return 0;

			case 'set':
				$key = $arg(0, 'Schlüssel');
				if ($key !== 'le_email') {
					throw new \RuntimeException("Unbekannte Einstellung: $key");
				}
				$this->service->setLetsEncryptEmail($positional[1] ?? '');
				$this->out("Gespeichert.\n");
				return 0;

			case 'render':
				if (isset($positional[0])) {
					$this->service->render($this->service->load($positional[0]));
				} else {
					$this->service->renderAll();
				}
				$this->out("nginx-Konfiguration neu geschrieben.\n");
				return 0;

			default:
				$this->err(self::USAGE);
				return 2;
		}
	}

	private function out(string $text): void
	{
		fwrite($this->stdout, $text);
	}

	private function err(string $text): void
	{
		fwrite($this->stderr, $text);
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: CLI-Einstieg neu schreiben**

`src/bin/vhost.php` (komplett ersetzen):
```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einstieg der Kommandozeile "vhost": baut die Objekte zusammen und startet die Anwendung.
 *
 * Alle Befehle außer "help" verlangen root, weil sie /var/www, /etc/nginx und
 * die Datenbank schreiben und nginx neu laden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:20
 */

require __DIR__ . '/../bootstrap.php';

use VhostAdmin\Cli\Application;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\SystemdReloader;
use VhostAdmin\Ssl\CertbotClient;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

$command = $argv[1] ?? 'help';
if (!in_array($command, ['help', '--help', '-h'], true)) {
	$euid = function_exists('posix_geteuid') ? posix_geteuid() : (int)trim((string)shell_exec('id -u'));
	if ($euid !== 0) {
		fwrite(STDERR, "vhost muss als root laufen (sudo vhost ...)\n");
		exit(1);
	}
}

$config = Config::defaults();
$database = new Database($config);
$database->initSchema();
$repository = new VhostRepository($database);
$service = new VhostService($config, $repository, new ConfigRenderer($config), new SystemdReloader(), new CertbotClient());

exit((new Application($service, $repository, $config, STDIN, STDOUT, STDERR))->run($argv));
```

`src/bin/vhost` bleibt:
```bash
#!/bin/bash
exec /usr/bin/php /opt/vhost-admin/bin/vhost.php "$@"
```

Syntaxprüfung: `php -l src/bin/vhost.php` und `php src/bin/vhost.php help` (muss die Usage zeigen, Exit 0, auch ohne root).

- [ ] **Step 6: Commit**

```bash
git add src/lib/VhostAdmin/Cli/Application.php src/bin/vhost.php tests/Cli/ApplicationTest.php
git commit -m "Cli\\Application mit Tests; bin/vhost.php als Composition Root"
```

---

### Task 10: Web\CommandRunner, Web\AdminPage und index.php

**Files:**
- Create: `src/lib/VhostAdmin/Web/CommandRunner.php`, `src/lib/VhostAdmin/Web/AdminPage.php`, `tests/Support/echo-command.php`
- Rewrite: `src/public/index.php`
- Test: `tests/Web/AdminPageTest.php`

**Interfaces:**
- Consumes: `Config`, `VhostRepository`, `Vhost`
- Produces:
  - `Web\CommandRunner::__construct(Config $config, array $prefix = ['sudo', '-n'])`, `run(array $args, ?string $stdin = null): array{int, string}` (Exit-Code, stdout+stderr getrimmt)
  - `Web\AdminPage::__construct(VhostRepository $repository, CommandRunner $runner, Config $config, array &$session)`
    - `csrfToken(): string`, `isValidCsrf(string $token): bool`
    - `static commandFor(string $action, array $post): ?array{args: list<string>, stdin: ?string}`
    - `static redirectTarget(string $action, int $exitCode, array $post): string`
    - `handlePost(array $post): string` (führt aus, setzt Flash, liefert Redirect-Ziel)
    - `takeFlash(): ?array{0: string, 1: string}` (Typ `ok`/`err`, Text; wird beim Lesen gelöscht)
    - `vhosts(): list<Vhost>`, `vhost(string $name): ?Vhost`, `users(Vhost): list<array{username: string, hash: string}>`, `ips(Vhost): list<string>`, `letsEncryptEmail(): ?string`

- [ ] **Step 1: Fehlschlagenden Test und Echo-Skript schreiben**

`tests/Support/echo-command.php` (steht im CommandRunner-Test anstelle von `sudo vhost`):
```php
<?php
declare(strict_types=1);

/**
 * Test-Hilfsskript: gibt Argumente und stdin aus; Exit 3, wenn das erste Argument "fail" ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:30
 */

array_shift($argv);
echo 'ARGS=' . implode('|', $argv) . "\n";
echo 'STDIN=' . stream_get_contents(STDIN) . "\n";
if (($argv[0] ?? '') === 'fail') {
	fwrite(STDERR, "Fehler: Simulation\n");
	exit(3);
}
```

`tests/Web/AdminPageTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests der Web-Logik: Aktionen → CLI-Argumente, Flash, Redirects, CSRF.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:30
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
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Web`
Erwartet: „Class VhostAdmin\Web\CommandRunner not found“.

- [ ] **Step 3: CommandRunner und AdminPage implementieren**

`src/lib/VhostAdmin/Web/CommandRunner.php`:
```php
<?php
declare(strict_types=1);

/**
 * Führt das CLI "vhost" aus der Oberfläche heraus aus (standardmäßig per sudo).
 *
 * Die Oberfläche läuft als www-data und hat selbst keine Rechte; sudoers
 * erlaubt ihr genau dieses eine Programm. Argumente gehen als Array an
 * proc_open, es gibt keine Shell dazwischen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:30
 */

namespace VhostAdmin\Web;

use VhostAdmin\Config;

final class CommandRunner
{
	/**
	 * @param list<string> $prefix Programme vor dem CLI, z. B. ["sudo", "-n"]; Tests übergeben ["php"]
	 */
	public function __construct(private readonly Config $config, private readonly array $prefix = ['sudo', '-n'])
	{
	}

	/**
	 * Startet das CLI mit den Argumenten; optional wird stdin (Passwort) übergeben.
	 *
	 * @param list<string> $args
	 * @return array{int, string} Exit-Code und gesamte Ausgabe (stdout + stderr, getrimmt)
	 */
	public function run(array $args, ?string $stdin = null): array
	{
		$command = array_merge($this->prefix, [$this->config->vhostBinary], $args);
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			return [1, 'Konnte vhost nicht starten.'];
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), trim($output)];
	}
}
```

`src/lib/VhostAdmin/Web/AdminPage.php`:
```php
<?php
declare(strict_types=1);

/**
 * Logik der Verwaltungsoberfläche: Formularaktionen, CSRF, Flash-Meldungen, Lesezugriffe.
 *
 * Schreibende Aktionen laufen ausschließlich über das CLI (CommandRunner);
 * die Oberfläche selbst schreibt nie in Datenbank oder Dateisystem.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:30
 */

namespace VhostAdmin\Web;

use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostRepository;

final class AdminPage
{
	/** @var array<string, mixed> */
	private array $session;

	/**
	 * @param array<string, mixed> $session Sitzungsdaten (per Referenz, z. B. $_SESSION)
	 */
	public function __construct(
		private readonly VhostRepository $repository,
		private readonly CommandRunner $runner,
		private readonly Config $config,
		array &$session,
	) {
		$this->session = &$session;
	}

	/**
	 * CSRF-Token der Sitzung; wird beim ersten Zugriff erzeugt.
	 */
	public function csrfToken(): string
	{
		if (!isset($this->session['csrf']) || !is_string($this->session['csrf'])) {
			$this->session['csrf'] = bin2hex(random_bytes(16));
		}
		return $this->session['csrf'];
	}

	public function isValidCsrf(string $token): bool
	{
		return $token !== '' && hash_equals($this->csrfToken(), $token);
	}

	/**
	 * Übersetzt eine Formularaktion in CLI-Argumente (und stdin für Passwörter).
	 *
	 * @param array<string, mixed> $post
	 * @return ?array{args: list<string>, stdin: ?string} null bei unbekannter Aktion
	 */
	public static function commandFor(string $action, array $post): ?array
	{
		$field = static fn(string $key): string => trim((string)($post[$key] ?? ''));
		$name = $field('name');
		return match ($action) {
			'create' => [
				'args' => array_merge(['add', $field('domain')], $field('subdir') !== '' ? ['--subdir', $field('subdir')] : []),
				'stdin' => null,
			],
			'protect' => ['args' => ['protect', $name, $field('state')], 'stdin' => null],
			'user_add' => ['args' => ['user-add', $name, $field('username')], 'stdin' => (string)($post['password'] ?? '') . "\n"],
			'user_del' => ['args' => ['user-del', $name, $field('username')], 'stdin' => null],
			'ip_add' => ['args' => ['ip-add', $name, $field('cidr')], 'stdin' => null],
			'ip_del' => ['args' => ['ip-del', $name, $field('cidr')], 'stdin' => null],
			'ssl' => ['args' => ['ssl', $name, $field('state')], 'stdin' => null],
			'remove' => ['args' => ['remove', $name], 'stdin' => null],
			'email' => ['args' => ['set', 'le_email', $field('le_email')], 'stdin' => null],
			default => null,
		};
	}

	/**
	 * Ziel nach einer Aktion: Detailseite des vHosts, nach Anlegen die neue Domain, sonst Übersicht.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function redirectTarget(string $action, int $exitCode, array $post): string
	{
		if ($action === 'create') {
			return $exitCode === 0 ? '/?v=' . rawurlencode(strtolower(trim((string)($post['domain'] ?? '')))) : '/';
		}
		if (in_array($action, ['remove', 'email'], true)) {
			return '/';
		}
		return '/?v=' . rawurlencode(trim((string)($post['name'] ?? '')));
	}

	/**
	 * Führt die Aktion aus, merkt sich das Ergebnis als Flash und liefert das Redirect-Ziel.
	 *
	 * @param array<string, mixed> $post
	 */
	public function handlePost(array $post): string
	{
		$action = (string)($post['action'] ?? '');
		$command = self::commandFor($action, $post);
		if ($command === null) {
			return '/';
		}
		[$code, $output] = $this->runner->run($command['args'], $command['stdin']);
		$this->session['flash'] = [
			$code === 0 ? 'ok' : 'err',
			$output !== '' ? $output : ($code === 0 ? 'Erledigt.' : "Fehler (Exit $code)"),
		];
		return self::redirectTarget($action, $code, $post);
	}

	/**
	 * Flash-Meldung abholen und löschen.
	 *
	 * @return ?array{0: string, 1: string}
	 */
	public function takeFlash(): ?array
	{
		$flash = $this->session['flash'] ?? null;
		unset($this->session['flash']);
		return is_array($flash) ? $flash : null;
	}

	/** @return list<Vhost> */
	public function vhosts(): array
	{
		return $this->repository->all();
	}

	public function vhost(string $name): ?Vhost
	{
		return $this->repository->byName($name);
	}

	/** @return list<array{username: string, hash: string}> */
	public function users(Vhost $vhost): array
	{
		return $this->repository->users($vhost->id);
	}

	/** @return list<string> */
	public function ips(Vhost $vhost): array
	{
		return $this->repository->ips($vhost->id);
	}

	public function letsEncryptEmail(): ?string
	{
		return $this->repository->setting('le_email');
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alle Tests grün.

- [ ] **Step 5: `src/public/index.php` komplett ersetzen**

```php
<?php
declare(strict_types=1);

/**
 * Verwaltungsoberfläche (Docroot /var/www/localhost-8080, nur 127.0.0.1:8080).
 *
 * Diese Datei ist das Template; alle Logik liegt in VhostAdmin\Web\AdminPage.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:30
 */

$bootstrap = is_file('/opt/vhost-admin/bootstrap.php') ? '/opt/vhost-admin/bootstrap.php' : dirname(__DIR__) . '/bootstrap.php';
require $bootstrap;

use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostRepository;
use VhostAdmin\Web\AdminPage;
use VhostAdmin\Web\CommandRunner;

session_start();
$config = Config::defaults();
$page = new AdminPage(new VhostRepository(new Database($config)), new CommandRunner($config), $config, $_SESSION);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$page->isValidCsrf((string)($_POST['csrf'] ?? ''))) {
		http_response_code(403);
		exit('Ungültiges Formular-Token.');
	}
	set_time_limit(180);
	header('Location: ' . $page->handlePost($_POST));
	exit;
}

$csrf = $page->csrfToken();
$flash = $page->takeFlash();
$email = $page->letsEncryptEmail();
$view = isset($_GET['v']) ? $page->vhost((string)$_GET['v']) : null;
if (isset($_GET['v']) && $view === null) {
	http_response_code(404);
}

/**
 * HTML-Escaping für Ausgaben.
 */
function h(mixed $value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Kleines Inline-Formular mit einem Button (Aktion + versteckte Felder).
 *
 * @param array<string, string> $fields
 */
function form(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string
{
	global $csrf;
	$html = '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>';
	$html .= '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
	foreach ($fields as $key => $value) {
		$html .= '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
	}
	return $html . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}

/**
 * Statuskennzeichen an/aus.
 */
function badge(bool $on, string $yes, string $no): string
{
	return '<span class="badge ' . ($on ? 'on' : 'off') . '">' . h($on ? $yes : $no) . '</span>';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>vhost-admin<?= $view ? ' – ' . h($view->name) : '' ?></title>
<style>
	:root { --bg:#f6f7f9; --card:#fff; --line:#e3e6ea; --txt:#1f2328; --mut:#6b7280; --acc:#2563eb; --ok:#15803d; --err:#b91c1c; }
	* { box-sizing:border-box; }
	body { margin:0; padding:1.5rem 1rem; background:var(--bg); color:var(--txt); font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
	main { max-width:960px; margin:0 auto; }
	header { display:flex; align-items:baseline; gap:1rem; margin-bottom:1.25rem; }
	header h1 { margin:0; font-size:1.4rem; }
	header a { color:var(--mut); text-decoration:none; }
	h2 { font-size:1.05rem; margin:0 0 .75rem; }
	.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; }
	table { width:100%; border-collapse:collapse; }
	th, td { text-align:left; padding:.5rem .4rem; border-bottom:1px solid var(--line); vertical-align:middle; }
	th { color:var(--mut); font-weight:600; font-size:.85rem; }
	tr:last-child td { border-bottom:0; }
	code { background:#eef0f3; padding:.1em .4em; border-radius:4px; font-size:.9em; }
	a { color:var(--acc); }
	.badge { display:inline-block; padding:.1em .55em; border-radius:999px; font-size:.78rem; font-weight:600; }
	.badge.on { background:#dcfce7; color:var(--ok); } .badge.off { background:#eef0f3; color:var(--mut); }
	.badge.local { background:#e0e7ff; color:#3730a3; }
	form.inline { display:inline; margin:0; }
	form.row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
	input[type=text], input[type=password], input[type=email] { padding:.45rem .6rem; border:1px solid #cfd4da; border-radius:6px; font:inherit; min-width:12rem; }
	button { padding:.4rem .8rem; border:1px solid #cfd4da; border-radius:6px; background:#fff; font:inherit; cursor:pointer; }
	button.primary { background:var(--acc); border-color:var(--acc); color:#fff; }
	button.danger { color:var(--err); border-color:#f3c2c2; }
	button.small { padding:.15rem .5rem; font-size:.82rem; }
	.flash { padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; white-space:pre-wrap; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85rem; }
	.flash.ok { background:#dcfce7; color:var(--ok); } .flash.err { background:#fee2e2; color:var(--err); }
	.muted { color:var(--mut); font-size:.88rem; }
	.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(20rem,1fr)); gap:1rem; }
	dl { display:grid; grid-template-columns:max-content 1fr; gap:.3rem 1rem; margin:0; }
	dt { color:var(--mut); } dd { margin:0; }
</style>
</head>
<body>
<main>
<header>
	<h1><a href="/">vhost-admin</a></h1>
	<?php if ($view): ?><span class="muted">/ <?= h($view->name) ?></span><?php endif ?>
</header>

<?php if ($flash): ?>
	<div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif ?>

<?php if (isset($_GET['v']) && !$view): ?>
	<div class="card">Unbekannter vHost: <code><?= h($_GET['v']) ?></code></div>

<?php elseif ($view): $isDomain = !$view->isLocal(); $users = $page->users($view); $ips = $page->ips($view); ?>
	<div class="card">
		<dl>
			<dt>Typ</dt><dd><?= $isDomain ? 'Domain (öffentlich)' : '<span class="badge local">localhost</span> nur lokal auf 127.0.0.1:' . h($view->port) ?></dd>
			<dt>Basisordner</dt><dd><code><?= h($view->baseDir($config)) ?></code></dd>
			<dt>Docroot</dt><dd><code><?= h($view->docroot($config)) ?></code></dd>
			<dt>Aufruf</dt><dd><a href="<?= h($isDomain ? ($view->ssl ? 'https' : 'http') . '://' . $view->name : 'http://localhost:' . $view->port) ?>/" target="_blank"><?= h($isDomain ? $view->name : 'localhost:' . $view->port) ?></a></dd>
			<dt>Angelegt</dt><dd><?= h($view->createdAt) ?> UTC</dd>
		</dl>
	</div>

	<div class="grid">
		<div class="card">
			<h2>Verzeichnisschutz <?= badge($view->protect, 'aktiv', 'aus') ?></h2>
			<?php if ($view->protect): ?>
				<p class="muted">Zugriff nur mit freigegebener IP <em>oder</em> Benutzer/Passwort. Ohne Einträge ist der Ordner komplett gesperrt.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'off'], 'Schutz abschalten', 'danger', 'Verzeichnisschutz wirklich abschalten? Der Docroot ist dann frei erreichbar.') ?>
			<?php else: ?>
				<p class="muted">Der Docroot ist ohne Anmeldung erreichbar.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'on'], 'Schutz einschalten', 'primary') ?>
			<?php endif ?>

			<h2 style="margin-top:1.25rem">Benutzer</h2>
			<?php if ($users): ?>
				<table><?php foreach ($users as $u): ?>
					<tr><td><code><?= h($u['username']) ?></code></td>
						<td style="text-align:right"><?= form('user_del', ['name' => $view->name, 'username' => $u['username']], 'entfernen', 'small danger', 'Benutzer ' . $u['username'] . ' entfernen?') ?></td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="muted">Keine Benutzer.</p><?php endif ?>
			<form method="post" class="row" style="margin-top:.5rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="user_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="username" placeholder="Benutzername" required autocomplete="off">
				<input type="password" name="password" placeholder="Passwort" required autocomplete="new-password">
				<button class="primary">Anlegen / Passwort setzen</button>
			</form>

			<h2 style="margin-top:1.25rem">Freigegebene IPs</h2>
			<?php if ($ips): ?>
				<table><?php foreach ($ips as $ip): ?>
					<tr><td><code><?= h($ip) ?></code></td>
						<td style="text-align:right"><?= form('ip_del', ['name' => $view->name, 'cidr' => $ip], 'entfernen', 'small danger', 'IP ' . $ip . ' entfernen?') ?></td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="muted">Keine IPs.</p><?php endif ?>
			<form method="post" class="row" style="margin-top:.5rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="ip_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="cidr" placeholder="IP oder CIDR, z.B. 203.0.113.5 oder 10.0.0.0/8" required style="min-width:20rem">
				<button class="primary">Freigeben</button>
			</form>
		</div>

		<div>
			<?php if ($isDomain): ?>
			<div class="card">
				<h2>Let's Encrypt <?= badge($view->ssl, 'HTTPS aktiv', 'aus') ?></h2>
				<?php if ($view->ssl): ?>
					<p class="muted">HTTP wird auf HTTPS umgeleitet. Verlängerung übernimmt der certbot-Timer automatisch.</p>
					<?= form('ssl', ['name' => $view->name, 'state' => 'off'], 'HTTPS abschalten', 'danger', 'HTTPS für ' . $view->name . ' abschalten? Das Zertifikat bleibt gespeichert.') ?>
				<?php elseif (!$email): ?>
					<p class="muted">Bitte zuerst auf der Übersicht eine Let's-Encrypt-E-Mail hinterlegen.</p>
					<button disabled>Zertifikat holen</button>
				<?php else: ?>
					<p class="muted">Die Domain muss per DNS auf diesen Server zeigen und Port 80 muss aus dem Internet erreichbar sein. Der Vorgang dauert einige Sekunden.</p>
					<?= form('ssl', ['name' => $view->name, 'state' => 'on'], 'Zertifikat holen & HTTPS einschalten', 'primary') ?>
				<?php endif ?>
			</div>
			<?php endif ?>

			<div class="card">
				<h2>Entfernen</h2>
				<p class="muted">Entfernt nginx-Konfiguration und Datenbankeintrag. Die Dateien unter <code><?= h($view->baseDir($config)) ?></code> bleiben erhalten.</p>
				<?= form('remove', ['name' => $view->name], 'vHost entfernen', 'danger', $view->name . ' wirklich entfernen?') ?>
			</div>
		</div>
	</div>

<?php else: ?>
	<div class="card">
		<h2>vHosts</h2>
		<table>
			<tr><th>Name</th><th>Docroot</th><th>Schutz</th><th>HTTPS</th></tr>
			<tr>
				<td>localhost:<?= h($config->adminPort) ?> <span class="badge local">lokal</span> <span class="muted">diese Oberfläche</span></td>
				<td><code><?= h(__DIR__) ?></code></td>
				<td><span class="muted">–</span></td>
				<td><span class="muted">–</span></td>
			</tr>
			<?php foreach ($page->vhosts() as $v): ?>
			<tr>
				<td><a href="/?v=<?= h(rawurlencode($v->name)) ?>"><?= h($v->name) ?></a>
					<?php if ($v->isLocal()): ?> <span class="badge local">lokal</span><?php endif ?></td>
				<td><code><?= h($v->docroot($config)) ?></code></td>
				<td><?= badge($v->protect, 'aktiv', 'aus') ?></td>
				<td><?= !$v->isLocal() ? badge($v->ssl, 'aktiv', 'aus') : '<span class="muted">–</span>' ?></td>
			</tr>
			<?php endforeach ?>
		</table>
	</div>

	<div class="grid">
		<div class="card">
			<h2>Neue Domain</h2>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">
				<input type="text" name="domain" placeholder="example.com" required autocomplete="off" pattern="[A-Za-z0-9.-]+\.[A-Za-z]{2,}">
				<input type="text" name="subdir" placeholder="Unterordner (optional), z.B. public" autocomplete="off">
				<button class="primary">Anlegen</button>
			</form>
			<p class="muted">Legt <code>/var/www/&lt;domain&gt;/[unterordner]</code> mit einer Start-Seite an. Der Docroot ist zunächst gesperrt (Verzeichnisschutz ohne Benutzer/IP).<br>
			localhost-Hosts werden per CLI angelegt: <code>sudo vhost add-local &lt;port&gt;</code></p>
		</div>
		<div class="card">
			<h2>Einstellungen</h2>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="email">
				<label for="le_email" class="muted">Let's-Encrypt-E-Mail</label>
				<input type="email" id="le_email" name="le_email" value="<?= h($email) ?>" placeholder="admin@example.com">
				<button class="primary">Speichern</button>
			</form>
			<p class="muted">Wird bei Let's Encrypt für Ablauf-Warnungen registriert. Ohne Adresse ist der HTTPS-Schalter deaktiviert.</p>
		</div>
	</div>
<?php endif ?>
</main>
</body>
</html>
```
Prüfen: `php -l src/public/index.php`.

- [ ] **Step 6: Commit**

```bash
git add src/lib/VhostAdmin/Web src/public/index.php tests/Web tests/Support/echo-command.php
git commit -m "Web\\CommandRunner und Web\\AdminPage mit Tests; index.php als reines Template"
```

---

### Task 11: Alte prozedurale Dateien entfernen, Installer anpassen, Smoke-Test

**Files:**
- Delete: `src/lib/config.php`, `src/lib/db.php`, `src/lib/vhost.php`
- Modify: `src/install.sh`
- Create: `debugging/smoke-test.sh`

**Interfaces:**
- Consumes: alles aus Task 1–10
- Produces: lauffähige Installation unter `/opt/vhost-admin` (bootstrap.php, lib/, bin/, templates/), Oberfläche unter `/var/www/localhost-8080`

- [ ] **Step 1: Alte Dateien löschen und Aufrufer prüfen**

```bash
cd /home/user/vhost-admin
git rm -q src/lib/config.php src/lib/db.php src/lib/vhost.php
grep -rn "lib/db.php\|lib/vhost.php\|lib/config.php\|vh_\|vhost_all\|cfg()" src tests --include='*.php' --include='*.sh'
```
Erwartet: keine Treffer (außer in diesem Plan). `phpunit` weiterhin grün.

- [ ] **Step 2: `src/install.sh` anpassen**

Im Abschnitt „Anwendung nach $APP“ die Kopierbefehle ersetzen durch:
```bash
echo "== Anwendung nach $APP (Besitzer von /var/www/*: $OWNER)"
install -d -m 755 "$APP" "$APP/bin" "$APP/templates"
rm -rf "$APP/lib" "$APP/public"
cp -r "$SRC/lib" "$APP/lib"
find "$APP/lib" -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
install -m 644 "$SRC/bootstrap.php" "$APP/bootstrap.php"
install -m 644 "$SRC"/templates/index.html "$APP/templates/"
install -m 755 "$SRC"/bin/vhost.php "$APP/bin/"
install -m 755 "$SRC"/bin/vhost /usr/local/sbin/vhost
sed -i "s|'wwwOwner' => '[^']*'|'wwwOwner' => '$OWNER'|" "$APP/lib/VhostAdmin/Config.php"
grep -q "'wwwOwner' => '$OWNER'" "$APP/lib/VhostAdmin/Config.php" || { echo "Besitzer konnte nicht gesetzt werden" >&2; exit 1; }
```
Die Paketliste erweitern: `apt-get install -y -q nginx php-fpm php-cli php-sqlite3 certbot phpunit`.
Der Rest (Oberfläche nach `/var/www/localhost-8080`, sudoers, nginx, certbot-Hook, Dienste, `vhost init`, `vhost render`) bleibt unverändert. Anschließend `bash -n src/install.sh`.

- [ ] **Step 3: Smoke-Test-Skript schreiben**

`debugging/smoke-test.sh`:
```bash
#!/usr/bin/env bash
# Ende-zu-Ende-Prüfung der installierten Version über CLI und Oberfläche.
# Legt Test-vHosts an, prüft die HTTP-Antworten und räumt wieder auf.
# Aufruf: sudo ./debugging/smoke-test.sh
set -euo pipefail

fail() { echo "FEHLER: $*" >&2; exit 1; }
expect() { # expect <erwartet> <beschreibung> <curl-args...>
	local want="$1" what="$2"; shift 2
	local got; got=$(curl -s -o /dev/null -w '%{http_code}' "$@")
	[ "$got" = "$want" ] && echo "ok   $what -> $got" || fail "$what: erwartet $want, bekommen $got"
}

echo "== CLI"
vhost add smoke-test.example --subdir public >/dev/null
expect 401 "gesperrt" -H 'Host: smoke-test.example' http://127.0.0.1/
printf 'geheim\n' | vhost user-add smoke-test.example alice >/dev/null
expect 200 "Login" -u alice:geheim -H 'Host: smoke-test.example' http://127.0.0.1/
expect 401 "falsches Passwort" -u alice:falsch -H 'Host: smoke-test.example' http://127.0.0.1/
vhost ip-add smoke-test.example 127.0.0.1 >/dev/null
expect 200 "IP-Freigabe" -H 'Host: smoke-test.example' http://127.0.0.1/
vhost ip-del smoke-test.example 127.0.0.1 >/dev/null
vhost protect smoke-test.example off >/dev/null
expect 200 "Schutz aus" -H 'Host: smoke-test.example' http://127.0.0.1/
curl -s -H 'Host: smoke-test.example' http://127.0.0.1/ | grep -q '<h1>200</h1>' && echo "ok   Startseite" || fail "Startseite fehlt"
expect 000 "unbekannter Host (444)" -H 'Host: nix.example' http://127.0.0.1/
vhost ssl smoke-test.example on >/dev/null 2>&1 && fail "SSL ohne E-Mail darf nicht klappen" || echo "ok   SSL ohne E-Mail abgelehnt"

echo "== localhost"
vhost add-local 3999 >/dev/null
ss -ltn | grep -q '127.0.0.1:3999' && echo "ok   bindet 127.0.0.1" || fail "Port 3999 nicht gebunden"
ss -ltn | grep -q '0.0.0.0:3999' && fail "Port 3999 öffentlich gebunden" || true
expect 401 "localhost gesperrt" http://127.0.0.1:3999/
vhost protect localhost:3999 off >/dev/null
expect 200 "localhost offen" http://127.0.0.1:3999/
vhost add-local 8080 >/dev/null 2>&1 && fail "8080 darf nicht anlegbar sein" || echo "ok   8080 reserviert"

echo "== Oberfläche"
JAR=$(mktemp)
CSRF=$(curl -s -c "$JAR" -b "$JAR" http://127.0.0.1:8080/ | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$CSRF" ] || fail "kein CSRF-Token"
expect 403 "CSRF-Schutz" -d 'csrf=x&action=remove&name=smoke-test.example' http://127.0.0.1:8080/
expect 302 "Domain anlegen" -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=create&domain=smoke-ui.example" http://127.0.0.1:8080/
curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-ui.example' | grep -q 'flash ok' && echo "ok   Flash ok" || fail "Flash fehlt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=create&domain=localhost:3998" http://127.0.0.1:8080/
curl -s -c "$JAR" -b "$JAR" http://127.0.0.1:8080/ | grep -q 'flash err' && echo "ok   localhost über UI abgelehnt" || fail "localhost über UI nicht abgelehnt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF" -d 'action=user_add&name=smoke-ui.example&username=bob' --data-urlencode 'password=p@ss wörd!' http://127.0.0.1:8080/
expect 200 "UI-Benutzer" -u 'bob:p@ss wörd!' -H 'Host: smoke-ui.example' http://127.0.0.1/
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "csrf=$CSRF&action=remove&name=smoke-ui.example" http://127.0.0.1:8080/
rm -f "$JAR"

echo "== Aufräumen"
vhost remove smoke-test.example --purge >/dev/null
vhost remove localhost:3999 --purge >/dev/null
rm -rf /var/www/smoke-ui.example
vhost list | grep -q smoke && fail "Reste in der Datenbank" || echo "ok   sauber"
echo "ALLE PRÜFUNGEN BESTANDEN"
```
`chmod +x debugging/smoke-test.sh`.

- [ ] **Step 4: Installieren und Smoke-Test ausführen**

```bash
cd /home/user/vhost-admin
sudo -n ./install.sh
ls /opt/vhost-admin            # bin bootstrap.php lib templates
sudo -n vhost list
sudo -n ./debugging/smoke-test.sh
```
Erwartet: `ALLE PRÜFUNGEN BESTANDEN`. Schlägt eine Prüfung fehl, gilt die globale CLAUDE.md: erst prüfen, ob der Test richtig prüft, dann den Code korrigieren – nicht den Test weichspülen.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Prozedurale Altdateien entfernt, Installer auf Klassenstruktur umgestellt, Smoke-Test"
```

---

### Task 12: build.sh, .claude/CLAUDE.md und Pflichtdokumentation

**Files:**
- Create: `build.sh`, `.claude/CLAUDE.md`, `README.md`, `BUGS.md`, `FEATURES.md`, `OPTIMIZE.md`, `MEMORY.md`, `dev-log/2026-09-17-dev.log`, `research/OPTIMIZED_WORKER.md`
- Create (Hauptsitzung, nicht Subagent): `~/USER.md`

**Interfaces:**
- Produces: `./build.sh [--no-push]` – führt Tests aus, erhöht `src/build.txt`, erzeugt `build/vhost-admin.tar.gz`, committet „Build N“ und pusht (ohne `--no-push`).

- [ ] **Step 1: `build.sh` schreiben**

```bash
#!/usr/bin/env bash
# Build: Tests → Buildnummer +1 → build/vhost-admin.tar.gz → Commit „Build N“ → Push.
# Aufruf: ./build.sh [--no-push]   (--no-push: nur bauen und committen)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
PUSH=1
[ "${1:-}" = "--no-push" ] && PUSH=0

echo "== Tests"
phpunit

echo "== Buildnummer"
BUILD=$(( $(cat src/build.txt) + 1 ))
echo "$BUILD" > src/build.txt
echo "Build $BUILD"

echo "== Paket"
mkdir -p build
rm -f build/vhost-admin.tar.gz
tar --transform 's,^,vhost-admin/,' -czf build/vhost-admin.tar.gz src install.sh README.md
ls -la build/vhost-admin.tar.gz

echo "== Git"
git add -A
git commit -q -m "Build $BUILD" || echo "nichts zu committen"
if [ "$PUSH" = 1 ]; then
	git push origin main
fi
echo "Fertig: Build $BUILD"
```
`chmod +x build.sh`. **Nicht ausführen** – der Build läuft in Task 13 aus der Hauptsitzung.

- [ ] **Step 2: `.claude/CLAUDE.md` des Projekts schreiben**

```markdown
# vhost-admin – Projektwissen

nginx-vHost-Verwaltung für Ubuntu-LXCs: PHP 8.5/SQLite-Oberfläche auf 127.0.0.1:8080, CLI `vhost` (root), Let's Encrypt per certbot-Webroot.

## Befehle
- Tests: `phpunit` (Wurzelverzeichnis; PHPUnit aus dem Ubuntu-Paket)
- Build + Push: `./build.sh` (nur aus der Hauptsitzung; `--no-push` zum Probieren)
- Installation/Update auf dem LXC: `sudo ./install.sh [--owner BENUTZER]`
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
- localhost-Host anlegen: `sudo vhost add-local <port> [--subdir DIR]` (die Oberfläche kann das absichtlich nicht)

## Struktur
- `src/lib/VhostAdmin/` – Klassen (Namespace `VhostAdmin`, Autoloader `src/bootstrap.php`)
  - `Value/*` validierende Wertobjekte · `Vhost` Entität · `VhostRepository` SQL · `Nginx\ConfigRenderer` reine Textausgabe · `VhostService` Anwendungsfälle · `Cli\Application` CLI · `Web\AdminPage` Oberfläche
- `src/public/index.php` – Template der Oberfläche (Docroot `/var/www/localhost-8080`)
- `src/etc/` – nginx-, sudoers-, certbot-Dateien; `src/install.sh` – Installer
- `tests/` – PHPUnit; `tests/Support/` – TempDir, FakeReloader, FakeCertbot
- Installationsziel: `/opt/vhost-admin` (Code), `/usr/local/sbin/vhost`, `/var/lib/vhost-admin/vhosts.sqlite`, `/etc/nginx/auth`

## Regeln (zusätzlich zur globalen CLAUDE.md)
- Tests laufen ohne root: Pfade über `Config::fromArray`, Reload/certbot über Fakes.
- nginx-Blöcke in `ConfigRenderer` sind mit 4 Leerzeichen eingerückt (nginx-Konvention); PHP-Code mit Tabs.
- Schreibende Aktionen nur über das CLI; `www-data` darf per sudoers ausschließlich `/usr/local/sbin/vhost`.
- Jeder neue Host startet gesperrt (Schutz ohne Benutzer/IP). Freigabe: IP **oder** Login (`satisfy any`).

## Bewusste Abweichungen von der globalen CLAUDE.md
- Kein Windows/ARM-Cross-Build, kein Windows-Setup, keine `.pid`: Linux-spezifische nginx/systemd/sudoers-Verwaltung ohne eigenen Daemon.
- Domains binden öffentlich (0.0.0.0:80/443) – Nutzerentscheidung vom 2026-09-17, weil Let's Encrypt und Webauftritt es erfordern. localhost-Hosts und Oberfläche binden nur 127.0.0.1.
- PHP-Projekt: gepusht werden nur vollständige Builds (`build.sh`).
```

- [ ] **Step 3: `README.md` (Endnutzerhandbuch) schreiben**

```markdown
# vhost-admin

Verwaltung von nginx-vHosts auf einem Ubuntu-LXC: Domains anlegen, Verzeichnisschutz mit Benutzern und IP-Freigaben, Let's-Encrypt-Zertifikate – über eine kleine Web-Oberfläche (nur lokal erreichbar) oder die Kommandozeile.

## Installation

```bash
git clone git@github.com:WaschbaerImHaus/basic-nginx-admin.git
cd basic-nginx-admin
sudo ./install.sh              # Besitzer der Web-Ordner = aufrufender Benutzer
sudo ./install.sh --owner max  # oder ein anderer Benutzer
```

Der Installer richtet nginx, PHP-FPM, certbot und PHPUnit ein, legt die Oberfläche unter `/var/www/localhost-8080` ab, erlaubt `www-data` per sudoers genau den Befehl `/usr/local/sbin/vhost` und ersetzt die nginx-Standardseite durch einen Catch-all, der unbekannte Hostnamen abweist. Das Skript ist mehrfach ausführbar (Update).

Alternativ aus dem Build-Paket: `tar xzf vhost-admin.tar.gz && sudo vhost-admin/install.sh`.

## Oberfläche

`http://127.0.0.1:8080` – nur vom Server selbst erreichbar. Von außen per SSH-Tunnel: `ssh -L 8080:127.0.0.1:8080 <server>`.

- **Übersicht:** alle vHosts mit Docroot, Schutz- und HTTPS-Status; Formular „Neue Domain“ (optional mit Unterordner als Docroot); Einstellung der Let's-Encrypt-E-Mail.
- **Detailseite:** Verzeichnisschutz ein-/ausschalten, Benutzer anlegen (oder Passwort neu setzen) und entfernen, IPs/Netze freigeben, HTTPS ein-/ausschalten, vHost entfernen (Dateien bleiben erhalten).

## Pfade

| Host | Docroot |
|---|---|
| `example.com` | `/var/www/example.com/` (oder `/var/www/example.com/<unterordner>/`) |
| `localhost:3000` | `/var/www/localhost-3000/` |

Beim Anlegen entsteht eine bunte `index.html` („200“), sofern noch keine liegt. Die Ordner gehören dem bei der Installation gewählten Benutzer (Gruppe `www-data`).

## Verzeichnisschutz

Jeder neue Host ist zunächst gesperrt: Schutz aktiv, aber ohne Benutzer und ohne IP – niemand kommt hinein. Freigabe: eine IP/ein Netz **oder** ein gültiger Login genügt. Oder den Schutz ganz abschalten. Der Pfad `/.well-known/acme-challenge/` bleibt immer frei, damit certbot arbeiten kann.

## Let's Encrypt

1. E-Mail-Adresse in den Einstellungen hinterlegen.
2. Domain per DNS auf den Server zeigen lassen; Port 80 muss aus dem Internet erreichbar sein.
3. Auf der Detailseite „Zertifikat holen & HTTPS einschalten“.

HTTP wird danach auf HTTPS umgeleitet; die Verlängerung übernimmt der certbot-Timer, nginx wird per Deploy-Hook neu geladen.

## Kommandozeile

```
sudo vhost list
sudo vhost add <domain> [--subdir DIR] [--no-protect]
sudo vhost add-local <port> [--subdir DIR] [--no-protect]   # nur 127.0.0.1
sudo vhost remove <name> [--purge]                           # --purge löscht auch /var/www/<name>
sudo vhost protect <name> on|off
printf 'passwort\n' | sudo vhost user-add <name> <user>
sudo vhost user-del <name> <user>
sudo vhost ip-add <name> <ip|cidr>
sudo vhost ip-del <name> <ip|cidr>
sudo vhost ssl <name> on|off
sudo vhost set le_email <adresse>
sudo vhost render [name]                                     # nginx-Dateien neu schreiben
```

localhost-Hosts lassen sich nur über die Kommandozeile anlegen; die Oberfläche listet sie nur.

## Umzug auf einen anderen Server

`/var/lib/vhost-admin`, `/var/www` und `/etc/letsencrypt` mitnehmen, dann `sudo ./install.sh` – die nginx-Konfigurationen werden aus der Datenbank neu erzeugt.

## Entwicklung

- Tests: `phpunit`
- Build (Tests, Buildnummer, `build/vhost-admin.tar.gz`, Commit, Push): `./build.sh`
- Ende-zu-Ende-Prüfung der Installation: `sudo ./debugging/smoke-test.sh`
```

- [ ] **Step 4: BUGS.md, FEATURES.md, OPTIMIZE.md, MEMORY.md, dev-log, OPTIMIZED_WORKER.md schreiben**

`BUGS.md`:
```markdown
# Bugs

## Offen

(keine)

## Behoben

| Datum | Bug | Ursache | Lösung |
|---|---|---|---|
| 2026-09-17 | Änderungen (IP-Freigabe, Schutz aus, neuer localhost-Port) wurden direkt nach dem CLI-Aufruf noch nicht wirksam | `systemctl reload nginx` kehrt zurück, bevor der Master die alten Worker ersetzt hat | `Nginx\SystemdReloader` wartet nach dem Reload, bis die alten Worker-PIDs verschwunden sind (max. 5 s) |
```

`FEATURES.md`:
```markdown
# Features

## Implementiert

- Domains und localhost-Hosts mit Docroot unter `/var/www/`, optional mit Unterordner
- Bunte Startseite „200“ beim Anlegen
- Verzeichnisschutz je Host: Benutzer (SHA-512-crypt) und IP-Freigaben, Logik „IP oder Login“, ganz abschaltbar
- Let's Encrypt (certbot-Webroot) mit HTTP→HTTPS-Redirect und automatischer Verlängerung
- localhost-Hosts binden ausschließlich an 127.0.0.1/[::1]
- Web-Oberfläche auf 127.0.0.1:8080 mit CSRF-Schutz; schreibt nur über das CLI (sudoers)
- CLI `vhost` mit allen Funktionen; Passwörter per stdin
- Catch-all-Server, der unbekannte Hostnamen mit 444 abweist
- Installer `install.sh` (idempotent), Build-Paket `build/vhost-admin.tar.gz`

## Offen

- `www.`-Alias für Domains (heute nur als separate Domain mit eigenem Docroot möglich)
- PHP-FPM für normale vHosts (heute nur statische Dateien)
- Backup/Export der Datenbank und Docroots
- Basic-Auth für die Oberfläche selbst, falls sie einmal im LAN erreichbar sein soll
- IPv6-Änderungen zur Laufzeit (die IPv6-Erkennung läuft nur beim Rendern)
```

`OPTIMIZE.md`:
```markdown
# Optimierungsvorschläge

- `VhostService::render` schreibt drei Dateien und lädt nginx neu; bei vielen Änderungen hintereinander (Skripte) könnte ein „Batch-Modus“ Reloads sparen (`renderAll` macht das bereits für die Installation).
- `SystemdReloader` pollt bis 5 s mit 50-ms-Schritten; ein `inotify` auf `/run/nginx.pid` wäre eleganter, lohnt aber erst bei häufigen Aufrufen.
- `index.php` mischt Template und Hilfsfunktionen; ein kleines Template-Objekt würde die Datei halbieren.
- Die Oberfläche liest die Datenbank direkt und schreibt über das CLI – bei Wachstum wäre eine JSON-Schnittstelle des CLI (`--json`) sauberer als Text-Flashes.
- `Config::fromArray` mit `new self(...array)` ist elegant, aber die Reihenfolge der Konstruktorparameter ist implizit an die Schlüsselnamen gebunden; bei Erweiterung Named Arguments beibehalten.
```

`MEMORY.md`:
```markdown
# Sitzungsgedächtnis

## 2026-09-17
- Projekt in einer Sitzung aufgesetzt: nginx + PHP 8.5/SQLite-Verwaltung, CLI `vhost`, Oberfläche auf 127.0.0.1:8080, Let's-Encrypt-Schalter, Verzeichnisschutz „IP oder Login“.
- Nutzerentscheidungen: Oberfläche nur lokal; Rechte über ein einziges CLI + sudoers; Schutzlogik `satisfy any`; LE-E-Mail als Einstellung; Domains binden öffentlich (Abweichung von der globalen CLAUDE.md); Arbeit direkt auf `main`; PHPUnit aus dem Ubuntu-Paket; Build = Tarball.
- Bug gefunden und behoben: asynchroner nginx-Reload (siehe BUGS.md).
- Oberfläche vom `/opt`-Pfad nach `/var/www/localhost-8080` verschoben, damit sie derselben Konvention folgt wie alle Hosts.
- Repo: `git@github.com:WaschbaerImHaus/basic-nginx-admin.git`, Identität `WaschbaerImHaus <mf-public-github@proton.me>`, SSH-Key `~/.ssh/bitbucket` repo-lokal in `core.sshCommand`.
- Umbau auf OOP/Tests/Build nach globaler CLAUDE.md (Spec `docs/superpowers/specs/2026-09-17-restructure-design.md`, Plan `docs/superpowers/plans/2026-09-17-restructure.md`).
```

`dev-log/2026-09-17-dev.log` (Uhrzeiten beim Schreiben anpassen):
```
2026-09-17 08:48  nginx, PHP-FPM 8.5, certbot installiert; Paket vhost-admin geschrieben (CLI, Oberfläche, Installer).
2026-09-17 08:53  Ende-zu-Ende-Test: Reloads griffen verzögert – Ursache asynchroner nginx-Reload, Warten auf alte Worker eingebaut.
2026-09-17 09:14  Oberfläche nach /var/www/localhost-8080 verschoben; Repo auf GitHub angelegt.
2026-09-17 09:45  Globale CLAUDE.md auf das Projekt angewandt: Spec und Plan geschrieben, Entscheidungen des Nutzers eingeholt.
2026-09-17 HH:MM  Umbau: src/-Struktur, Namespace VhostAdmin, Wertobjekte, Repository, Renderer, Service, CLI, Web; PHPUnit-Tests grün.
2026-09-17 HH:MM  Installer und Smoke-Test angepasst; Build 1 erzeugt und gepusht.
```

`research/OPTIMIZED_WORKER.md`:
```markdown
# OPTIMIZED_WORKER – was ich über vhost-admin weiß und wie ich hier arbeite

Stand: 2026-09-17

## Das Projekt in drei Sätzen
Ein LXC-Verwaltungswerkzeug: nginx-vHosts als Datensätze in SQLite, aus denen `Nginx\ConfigRenderer` deterministisch Konfigurationsdateien erzeugt. Alles Schreibende läuft über das root-CLI `vhost`; die PHP-Oberfläche (www-data) liest nur und ruft das CLI per sudoers. Sicherheit kommt aus strenger Validierung der Wertobjekte, nicht aus Vertrauen in die Oberfläche.

## Arbeitsweise, die sich bewährt hat
- Erst Tests (PHPUnit, ohne root, Temp-Verzeichnisse, Fakes für Reload/certbot), dann Code.
- nginx-Ausgaben zeichengenau testen – Abweichungen in Whitespace sind sonst unsichtbar.
- Nach jeder Installation `sudo ./debugging/smoke-test.sh`; er hat den Reload-Race gefunden.
- Nie das CLI weichspülen, damit die Oberfläche „einfacher“ wird: die Oberfläche ist unprivilegiert, das CLI ist die Sicherheitsgrenze.

## Fallstricke
- `nginx -s reload` ist asynchron → `SystemdReloader` wartet auf alte Worker.
- `jq` ist auf diesem LXC nicht installiert; Skripte nutzen PHP oder Python für JSON.
- PHP-FPM-Socket heißt `/run/php/php-fpm.sock` (versionsunabhängiger Link).
- `crypt()` mit `$6$` funktioniert mit libxcrypt; bcrypt wäre in nginx nicht garantiert.

## Offene Fragen an den Nutzer (bei Gelegenheit)
- Sollen Domains einen `www.`-Alias bekommen?
- Wird PHP in normalen vHosts gebraucht?
```

- [ ] **Step 5: `~/USER.md` (nur Hauptsitzung)**

```markdown
# USER.md – Chat-Schreibstil des Nutzers

Stand: 2026-09-17

- Schreibt Deutsch, durchgehend klein, knapp, ohne Anrede oder Höflichkeitsfloskeln; Befehle im Imperativ („installiere nginx“, „behebe das“).
- Hängt bei größeren Aufträgen „fragen?“ an: will vor der Umsetzung gezielte Rückfragen und wählt in der Regel die empfohlene Option.
- Antwortet auf Rückfragen mit einem Wort („passt“, „passt.“) – das ist die Freigabe.
- Meldet Probleme als Beobachtung („ich sehe weder modelnamen noch ctx“) und erwartet Ursache + Behebung, keine Diskussion.
- Legt Wert auf Konsistenz (z. B. Oberfläche unter derselben Pfadkonvention wie alle Hosts) und auf dauerhafte Lösungen (Skripte, Settings statt Handgriffe).
- Erwartet, dass Ergebnisse committet und gepusht werden, ohne nachzufragen.
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "build.sh, Projekt-CLAUDE.md und Pflichtdokumentation"
```

---

### Task 13: Sicherheitsreview, erster Build und Push (nur Hauptsitzung)

**Files:**
- Create: `SECURITY_RISKS.md`, `SECURITY_FIXED.md`
- Modify: `src/build.txt` (durch `build.sh`)

- [ ] **Step 1: Sicherheitsreview**

In der Hauptsitzung den Skill `security-review` über die Änderungen laufen lassen (Vorgaben des Plugins `security-guidance` beachten). Ergebnis eintragen:

`SECURITY_FIXED.md` (Beispielrahmen, mit den echten Funden füllen):
```markdown
# Behobene Sicherheitsrisiken

| Datum | Risiko | Behebung |
|---|---|---|
| 2026-09-17 | Passwörter als CLI-Argument wären in `ps` sichtbar | Übergabe per stdin (`Cli\Application`, `Web\CommandRunner`) |
| 2026-09-17 | Eingaben aus der Oberfläche könnten nginx-Direktiven injizieren (`allow 1.2.3.4; deny …`) | Wertobjekte `Cidr`, `DomainName`, `SubDirectory`, `Username` validieren streng; nur validierte Werte landen in Konfigurationstexten |
| 2026-09-17 | `--purge` könnte außerhalb von `/var/www` löschen | `VhostService::remove` prüft `str_starts_with($base, wwwRoot . '/')` |
```

`SECURITY_RISKS.md`:
```markdown
# Offene Sicherheitsrisiken

| Risiko | Einschätzung | Warum offen |
|---|---|---|
| `www-data` darf `/usr/local/sbin/vhost` als root ausführen | mittel | Bewusste Architektur; das CLI validiert alle Argumente. Ein Fehler in der Validierung wäre eine lokale Privilegieneskalation – deshalb Tests für alle Wertobjekte. |
| Oberfläche ohne eigene Anmeldung | niedrig | Bindet nur 127.0.0.1; wer auf dem LXC ist, hat ohnehin sudo. Bei LAN-Zugang Basic-Auth ergänzen (FEATURES.md). |
| Zertifikatsverwaltung vertraut certbot und DNS | niedrig | Standardrisiko von Let's Encrypt. |
```
Weitere Funde des Reviews ergänzen; automatisch behebbare direkt beheben, Tests dazu schreiben.

- [ ] **Step 2: Build ausführen**

```bash
cd /home/user/vhost-admin
./build.sh
```
Erwartet: Tests grün, `src/build.txt` = 1, `build/vhost-admin.tar.gz` vorhanden, Commit „Build 1“ auf `origin/main`.

- [ ] **Step 3: Installation aus dem Build prüfen**

```bash
sudo -n ./install.sh
sudo -n ./debugging/smoke-test.sh
```
Erwartet: `ALLE PRÜFUNGEN BESTANDEN`.

---

## Selbstprüfung des Plans

- **Spec-Abdeckung:** Abschnitt 3 (Struktur) → Task 1, 11, 12; Abschnitt 4 (Klassen) → Task 2–10; Abschnitt 5 (Tests) → in jedem Task; Abschnitt 6 (Build) → Task 12/13; Abschnitt 7 (Doku) → Task 12/13; Abschnitt 8 (Abweichungen) → `.claude/CLAUDE.md` in Task 12.
- **Platzhalter:** Die einzigen bewusst offenen Stellen sind Uhrzeiten (`HH:MM`) im dev-log und die Tabellen des Sicherheitsreviews, die mit echten Funden gefüllt werden.
- **Typkonsistenz:** `VhostRepository::users()` liefert `list<array{username, hash}>`, `ips()` liefert `list<string>` – `ConfigRenderer::htpasswd/authSnippet`, `VhostService::render` und `AdminPage::users/ips` nutzen genau diese Formen. `Config::fromArray` verlangt die Schlüssel aus Task 2; alle Tests verwenden nur diese. `Port::fromString(string, int)` wird im CLI mit `$this->config->adminPort` aufgerufen.
