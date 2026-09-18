# vHost-Verzeichnisstruktur – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jeder vHost erhält unterhalb von `/var/www/<slug>/` die Ordner `web/`, `conf/`, `cert/`, `private/` und `logs/`; bestehende Installationen werden beim Update automatisch migriert.

**Architecture:** Eine neue Klasse `VhostLayout` wird zur einzigen Quelle für Pfade und Soll-Rechte; `Vhost::baseDir()`/`docroot()` entfallen. Ein neues Wertobjekt `NginxSnippet` prüft das über die Oberfläche gepflegte Konfigurations-Snippet gegen eine Positivliste, bevor nginx es sieht. Die Migration bestehender Hosts läuft über eine eigene Klasse `Migration\LayoutMigrator`, aufgerufen vom neuen CLI-Befehl `migrate-layout`.

**Tech Stack:** PHP 8.5 (pdo_sqlite, posix), PHPUnit 13, nginx 1.28, certbot, Bash, logrotate.

**Spec:** `docs/superpowers/specs/2026-09-18-vhost-verzeichnisstruktur-design.md`

## Global Constraints

- Einrückung am Zeilenanfang ausschließlich mit **Tabulatoren** in PHP, XML und Bash. **Ausnahme:** nginx-Textblöcke in Heredocs (`<<<NG … NG;`) und die erwarteten Strings in den Renderer-Tests sind mit **vier Leerzeichen** eingerückt – das ist Inhalt, keine Code-Einrückung.
- Bezeichner (Klassen, Methoden, Variablen) **englisch**; DocBlocks, Kommentare, Fehlermeldungen und Oberflächentexte **deutsch** mit echten Umlauten.
- Jede PHP-Datei beginnt mit `<?php` + `declare(strict_types=1);` und trägt einen deutschen DocBlock mit `@author Kurt Ingwer` und `@version Letzte Änderung: YYYY-MM-DD HH:MM` (Uhrzeit des tatsächlichen Schreibens, per `date '+%Y-%m-%d %H:%M'`).
- **Jede Klasse und jede öffentliche Methode – auch `__construct` und `__toString` – bekommt einen deutschen DocBlock.**
- Testhilfsmethoden für CLI-Läufe heißen `runCli()`, nie `run()` (`PHPUnit\Framework\TestCase::run()` ist `final`).
- Tests laufen **ohne root** und fassen keine Systempfade an: Pfade kommen aus `Config::fromArray([...])` mit Temp-Verzeichnissen, nginx-Reload und certbot über die Fakes in `tests/Support/`.
- Kein `git push` aus einem Subagenten. Gepusht wird ausschließlich über `./build.sh` aus der Hauptsitzung.
- Auf dem System laufen zwei produktive vHosts des Nutzers (`mfsrv.de`, `mfsvr.de`) und die Oberfläche `localhost-8080`. Kein Task darf sie löschen oder ihre Erreichbarkeit dauerhaft beeinträchtigen.
- Rechte-Tabelle (aus der Spec, Abschnitt 3) – gilt überall identisch:

| Ordner | Besitzer:Gruppe | Modus |
|---|---|---|
| `web/` (und `web/<subdir>`) | `<owner>:www-data` | `02775` |
| `conf/` | `root:www-data` | `0750` |
| `cert/` | `root:<owner>` | `0750` |
| `private/` | `<owner>:<owner>` | `0750` |
| `logs/` | `root:<owner>` | `0750` |
| Datei `conf/custom.conf` | `root:www-data` | `0640` |

`<owner>` ist `Config::$wwwOwner`.

## Dateistruktur (Ergebnis)

```
src/lib/VhostAdmin/
├── DirectorySpec.php          NEU  Wertobjekt: Pfad + Soll-Besitzer/Gruppe/Modus + Beschreibung
├── VhostLayout.php            NEU  alle Pfade eines vHosts + directories() + needsMigration()
├── Value/NginxSnippet.php     NEU  Positivlistenprüfung des Konfigurations-Snippets
├── Migration/LayoutMigrator.php NEU  Migration alter Hosts auf die neue Struktur
├── Vhost.php                  GEÄNDERT  baseDir()/docroot() entfallen
├── Nginx/ConfigRenderer.php   GEÄNDERT  Pfade aus VhostLayout, logs/, include conf/*.conf
├── VhostService.php           GEÄNDERT  Verzeichnisse aus directories(), cert-Symlinks, setSnippet()
├── Cli/Application.php        GEÄNDERT  Befehle conf, fix-permissions, migrate-layout
└── Web/AdminPage.php          GEÄNDERT  Aktion conf, Snippet lesen

src/public/index.php           GEÄNDERT  Textfeld für das Snippet, Pfade aus VhostLayout
src/etc/nginx-admin.conf       GEÄNDERT  Docroot /var/www/localhost-8080/web
src/etc/logrotate-vhost-admin  NEU       Rotation für /var/www/*/logs/*.log
src/install.sh                 GEÄNDERT  Oberfläche nach web/, logrotate, migrate-layout
debugging/smoke-test.sh        GEÄNDERT  Snippet-Durchgang

tests/
├── VhostLayoutTest.php        NEU
├── Value/NginxSnippetTest.php NEU
├── Migration/LayoutMigratorTest.php NEU
├── VhostTest.php              GEÄNDERT  Pfadtests entfallen
├── Nginx/ConfigRendererTest.php GEÄNDERT  neue Sollausgaben
├── VhostServiceTest.php       GEÄNDERT  Ordner/Rechte, Snippet
├── Cli/ApplicationTest.php    GEÄNDERT  neue Befehle
└── Web/AdminPageTest.php      GEÄNDERT  Aktion conf
```

---

### Task 1: DirectorySpec und VhostLayout

**Files:**
- Create: `src/lib/VhostAdmin/DirectorySpec.php`, `src/lib/VhostAdmin/VhostLayout.php`, `tests/VhostLayoutTest.php`
- Modify: `src/lib/VhostAdmin/Vhost.php` (Methoden `baseDir()` und `docroot()` löschen, ca. Zeile 144-159), `tests/VhostTest.php` (Pfadtests entfernen)

**Interfaces:**
- Consumes: `VhostAdmin\Config` (`$wwwRoot`, `$wwwOwner`, `$wwwGroup`), `VhostAdmin\Vhost` (`slug()`, `$subdir`, `$name`)
- Produces:
  - `VhostAdmin\DirectorySpec` – `final class`, `__construct(public readonly string $path, public readonly string $owner, public readonly string $group, public readonly int $mode, public readonly string $description)`
  - `VhostAdmin\VhostLayout::__construct(Config $config)` mit `baseDir(Vhost): string`, `webDir(Vhost): string`, `docroot(Vhost): string`, `confDir(Vhost): string`, `confFile(Vhost): string`, `certDir(Vhost): string`, `privateDir(Vhost): string`, `logsDir(Vhost): string`, `accessLog(Vhost): string`, `errorLog(Vhost): string`, `directories(Vhost): list<DirectorySpec>`, `needsMigration(Vhost): bool`

- [ ] **Step 1: Failing test schreiben**

`tests/VhostLayoutTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Pfad- und Rechteverwaltung eines vHosts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:00
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;

final class VhostLayoutTest extends TestCase
{
	private VhostLayout $layout;

	protected function setUp(): void
	{
		$this->layout = new VhostLayout(Config::fromArray([
			'wwwRoot' => '/srv/www',
			'wwwOwner' => 'max',
			'wwwGroup' => 'www-data',
		]));
	}

	public function testPathsForDomain(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		self::assertSame('/srv/www/example.com', $this->layout->baseDir($v));
		self::assertSame('/srv/www/example.com/web', $this->layout->webDir($v));
		self::assertSame('/srv/www/example.com/web', $this->layout->docroot($v));
		self::assertSame('/srv/www/example.com/conf', $this->layout->confDir($v));
		self::assertSame('/srv/www/example.com/conf/custom.conf', $this->layout->confFile($v));
		self::assertSame('/srv/www/example.com/cert', $this->layout->certDir($v));
		self::assertSame('/srv/www/example.com/private', $this->layout->privateDir($v));
		self::assertSame('/srv/www/example.com/logs', $this->layout->logsDir($v));
		self::assertSame('/srv/www/example.com/logs/access.log', $this->layout->accessLog($v));
		self::assertSame('/srv/www/example.com/logs/error.log', $this->layout->errorLog($v));
	}

	public function testDocrootWithSubdirectoryLiesUnderWeb(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'www/src', true, false);
		self::assertSame('/srv/www/example.com/web', $this->layout->webDir($v));
		self::assertSame('/srv/www/example.com/web/www/src', $this->layout->docroot($v));
	}

	public function testPathsForLocalhost(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		self::assertSame('/srv/www/localhost-3000', $this->layout->baseDir($v));
		self::assertSame('/srv/www/localhost-3000/web', $this->layout->docroot($v));
		self::assertSame('/srv/www/localhost-3000/logs/access.log', $this->layout->accessLog($v));
	}

	public function testDirectoriesCarryOwnerGroupAndMode(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$specs = $this->layout->directories($v);
		$byPath = [];
		foreach ($specs as $spec) {
			self::assertInstanceOf(DirectorySpec::class, $spec);
			$byPath[$spec->path] = $spec;
		}
		self::assertSame(
			['/srv/www/example.com', '/srv/www/example.com/web', '/srv/www/example.com/conf',
				'/srv/www/example.com/cert', '/srv/www/example.com/private', '/srv/www/example.com/logs'],
			array_keys($byPath)
		);
		$expect = static function (DirectorySpec $s, string $owner, string $group, int $mode): void {
			self::assertSame($owner, $s->owner, $s->path);
			self::assertSame($group, $s->group, $s->path);
			self::assertSame($mode, $s->mode, $s->path);
			self::assertNotSame('', $s->description, $s->path);
		};
		$expect($byPath['/srv/www/example.com'], 'max', 'www-data', 02775);
		$expect($byPath['/srv/www/example.com/web'], 'max', 'www-data', 02775);
		$expect($byPath['/srv/www/example.com/conf'], 'root', 'www-data', 0750);
		$expect($byPath['/srv/www/example.com/cert'], 'root', 'max', 0750);
		$expect($byPath['/srv/www/example.com/private'], 'max', 'max', 0750);
		$expect($byPath['/srv/www/example.com/logs'], 'root', 'max', 0750);
	}

	public function testDirectoriesIncludeSubdirectorySegments(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'www/src', true, false);
		$paths = array_map(static fn(DirectorySpec $s): string => $s->path, $this->layout->directories($v));
		self::assertContains('/srv/www/example.com/web/www', $paths);
		self::assertContains('/srv/www/example.com/web/www/src', $paths);
		$webIndex = array_search('/srv/www/example.com/web', $paths, true);
		$leafIndex = array_search('/srv/www/example.com/web/www/src', $paths, true);
		self::assertLessThan($leafIndex, $webIndex, 'web/ muss vor seinen Unterordnern stehen');
	}

	public function testNeedsMigration(): void
	{
		$dir = TempDir::create();
		try {
			$layout = new VhostLayout(Config::fromArray(['wwwRoot' => $dir, 'wwwOwner' => 'max']));
			$v = new Vhost(1, 'alt.example', VhostKind::Domain, null, null, true, false);
			self::assertFalse($layout->needsMigration($v), 'ohne Basisordner nichts zu migrieren');
			mkdir($dir . '/alt.example');
			file_put_contents($dir . '/alt.example/index.html', 'alt');
			self::assertTrue($layout->needsMigration($v));
			mkdir($dir . '/alt.example/web');
			self::assertFalse($layout->needsMigration($v));
		} finally {
			TempDir::remove($dir);
		}
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/VhostLayoutTest.php`
Erwartet: `Class "VhostAdmin\DirectorySpec" not found`.

- [ ] **Step 3: `DirectorySpec` implementieren**

`src/lib/VhostAdmin/DirectorySpec.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein Verzeichnis mit seinen Soll-Rechten.
 *
 * Wird von VhostLayout::directories() geliefert und von Service, Installer und
 * Migration gleichermaßen angewandt, damit die Rechte nicht auseinanderlaufen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:00
 */

namespace VhostAdmin;

final class DirectorySpec
{
	/**
	 * @param string $path        absoluter Pfad des Verzeichnisses
	 * @param string $owner       Soll-Besitzer (Benutzername)
	 * @param string $group       Soll-Gruppe
	 * @param int    $mode        Soll-Rechte als Oktalzahl, z.B. 02775
	 * @param string $description kurze deutsche Beschreibung für Meldungen
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $owner,
		public readonly string $group,
		public readonly int $mode,
		public readonly string $description,
	) {
	}
}
```

- [ ] **Step 4: `VhostLayout` implementieren**

`src/lib/VhostAdmin/VhostLayout.php`:
```php
<?php
declare(strict_types=1);

/**
 * Pfade und Soll-Rechte eines vHosts.
 *
 * Einzige Quelle für das Verzeichnis-Layout: /var/www/<slug>/ enthält web/ (Docroot),
 * conf/ (von der Oberfläche gepflegtes nginx-Snippet), cert/ (Symlinks auf die
 * Zertifikate), private/ (nicht ausgeliefert) und logs/ (Zugriffs- und Fehlerlog).
 *
 * www-data darf ausschließlich in web/ schreiben; ein dort untergeschobener Symlink
 * erreicht damit weder Snippet noch Zertifikatsverweise noch Logs.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:00
 */

namespace VhostAdmin;

final class VhostLayout
{
	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Basisordner des vHosts unterhalb der Web-Wurzel.
	 */
	public function baseDir(Vhost $vhost): string
	{
		return $this->config->wwwRoot . '/' . $vhost->slug();
	}

	/**
	 * Ordner, aus dem ausgeliefert wird (ohne optionalen Unterordner).
	 */
	public function webDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/web';
	}

	/**
	 * Tatsächlicher Docroot: web/ plus optionalen Unterordner.
	 */
	public function docroot(Vhost $vhost): string
	{
		return $this->webDir($vhost) . ($vhost->subdir !== null ? '/' . $vhost->subdir : '');
	}

	/**
	 * Ordner des von der Oberfläche gepflegten nginx-Snippets.
	 */
	public function confDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/conf';
	}

	/**
	 * Datei des nginx-Snippets.
	 */
	public function confFile(Vhost $vhost): string
	{
		return $this->confDir($vhost) . '/custom.conf';
	}

	/**
	 * Ordner mit den Symlinks auf die Let's-Encrypt-Zertifikate.
	 */
	public function certDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/cert';
	}

	/**
	 * Ordner für Dateien, die nicht ausgeliefert werden sollen.
	 */
	public function privateDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/private';
	}

	/**
	 * Ordner der nginx-Logdateien dieses vHosts.
	 */
	public function logsDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/logs';
	}

	/**
	 * Zugriffslog dieses vHosts.
	 */
	public function accessLog(Vhost $vhost): string
	{
		return $this->logsDir($vhost) . '/access.log';
	}

	/**
	 * Fehlerlog dieses vHosts.
	 */
	public function errorLog(Vhost $vhost): string
	{
		return $this->logsDir($vhost) . '/error.log';
	}

	/**
	 * Alle anzulegenden Verzeichnisse mit ihren Soll-Rechten, Eltern vor Kindern.
	 *
	 * @return list<DirectorySpec>
	 */
	public function directories(Vhost $vhost): array
	{
		$owner = $this->config->wwwOwner;
		$group = $this->config->wwwGroup;
		$specs = [
			new DirectorySpec($this->baseDir($vhost), $owner, $group, 02775, 'Basisordner des vHosts'),
			new DirectorySpec($this->webDir($vhost), $owner, $group, 02775, 'Docroot (wird ausgeliefert)'),
			new DirectorySpec($this->confDir($vhost), 'root', $group, 0750, 'nginx-Snippet der Oberfläche'),
			new DirectorySpec($this->certDir($vhost), 'root', $owner, 0750, 'Symlinks auf die Zertifikate'),
			new DirectorySpec($this->privateDir($vhost), $owner, $owner, 0750, 'nicht ausgelieferte Dateien'),
			new DirectorySpec($this->logsDir($vhost), 'root', $owner, 0750, 'Logdateien dieses vHosts'),
		];
		$path = $this->webDir($vhost);
		foreach ($vhost->subdir !== null ? explode('/', $vhost->subdir) : [] as $segment) {
			$path .= '/' . $segment;
			$specs[] = new DirectorySpec($path, $owner, $group, 02775, 'Unterordner des Docroots');
		}
		return $specs;
	}

	/**
	 * Muss dieser vHost auf die neue Struktur gebracht werden?
	 *
	 * Das ist genau dann der Fall, wenn der Basisordner existiert, aber noch kein web/
	 * enthält – dann liegen die Dateien noch flach im Basisordner.
	 */
	public function needsMigration(Vhost $vhost): bool
	{
		return is_dir($this->baseDir($vhost)) && !is_dir($this->webDir($vhost));
	}
}
```

- [ ] **Step 5: Test ausführen – muss bestehen**

Run: `phpunit tests/VhostLayoutTest.php`
Erwartet: 6 Tests grün.

- [ ] **Step 6: `Vhost::baseDir()`/`docroot()` entfernen und Aufrufer umstellen**

In `src/lib/VhostAdmin/Vhost.php` die beiden Methoden samt DocBlocks löschen (ca. Zeile 144-159); `use`-Zeile für `Config` entfernen, falls sie danach ungenutzt ist (`grep -n "Config" src/lib/VhostAdmin/Vhost.php` prüfen).

In `tests/VhostTest.php` die Testmethoden entfernen, die nur `baseDir()`/`docroot()` prüfen (`testDomainPaths`, `testDomainWithSubdirectory`, `testLocalhostPaths` – deren Pfadzusicherungen sind jetzt in `VhostLayoutTest` abgedeckt). Erhalten bleiben alle Tests zu `fromRow()`, Revalidierung und `slug()`; enthält ein solcher Test nebenbei eine `docroot()`-Zusicherung (z. B. `testFromRowTreatsEmptySubdirAsNull`), ersetze dort nur diese eine Zeile durch eine Prüfung auf `$v->subdir` bzw. `$v->slug()`.

Danach die verbleibenden Aufrufer umstellen – `grep -rn "baseDir(\|docroot(" src/ tests/ --include='*.php'` zeigt sie:
- `src/lib/VhostAdmin/Nginx/ConfigRenderer.php` – in Task 3
- `src/lib/VhostAdmin/VhostService.php` – in Task 4
- `src/lib/VhostAdmin/Cli/Application.php` – hier: Konstruktor um `VhostLayout $layout` erweitern (nach `Config $config`), die drei `docroot(...)`-Aufrufe auf `$this->layout->docroot($vhost)` ändern; in `tests/Cli/ApplicationTest.php` und `src/bin/vhost.php` die Konstruktion entsprechend anpassen.
- `src/public/index.php` – hier: `$layout = new VhostLayout($config);` neben `$config` anlegen und die vier Aufrufe auf `$layout->…($view)` ändern.

- [ ] **Step 7: Gesamte Suite ausführen**

Run: `phpunit`
Erwartet: alle Tests grün (147 − entfernte Pfadtests + 6 neue). Schlägt etwas fehl, das nur an einer vergessenen Aufrufstelle liegt, dort nachziehen.

- [ ] **Step 8: Commit**

```bash
git add src/lib/VhostAdmin/DirectorySpec.php src/lib/VhostAdmin/VhostLayout.php src/lib/VhostAdmin/Vhost.php src/lib/VhostAdmin/Cli/Application.php src/public/index.php src/bin/vhost.php tests/VhostLayoutTest.php tests/VhostTest.php tests/Cli/ApplicationTest.php
git commit -m "VhostLayout als einzige Quelle für Pfade und Soll-Rechte"
```

---

### Task 2: Wertobjekt NginxSnippet

**Files:**
- Create: `src/lib/VhostAdmin/Value/NginxSnippet.php`, `tests/Value/NginxSnippetTest.php`

**Interfaces:**
- Produces: `VhostAdmin\Value\NginxSnippet` – `final class`, privater Konstruktor, `public readonly string $value`, `static fromString(string $text): NginxSnippet`, `__toString(): string`, `isEmpty(): bool`. Wirft `\InvalidArgumentException` mit deutscher Meldung inklusive Zeilennummer.

- [ ] **Step 1: Failing test schreiben**

`tests/Value/NginxSnippetTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests für die Prüfung des nginx-Snippets aus der Oberfläche.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:10
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\NginxSnippet;

final class NginxSnippetTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function allowedSnippets(): iterable
	{
		yield 'Größenlimit' => ["client_max_body_size 64m;\n"];
		yield 'Ablauf' => ["expires 7d;\n"];
		yield 'Header' => ["add_header X-Frame-Options SAMEORIGIN;\n"];
		yield 'Header-Modul' => ["more_set_headers \"Server: nginx\";\n"];
		yield 'Zeichensatz' => ["charset utf-8;\n"];
		yield 'Verzeichnisliste' => ["autoindex on;\n"];
		yield 'Indexdateien' => ["index index.php index.html;\n"];
		yield 'Fehlerseite' => ["error_page 404 /404.html;\n"];
		yield 'Umschreibung' => ["rewrite ^/alt/(.*)$ /neu/\$1 permanent;\n"];
		yield 'Rückgabe' => ["return 301 https://example.com\$request_uri;\n"];
		yield 'Dateisuche' => ["try_files \$uri \$uri/ /index.php?\$args;\n"];
		yield 'Rate' => ["limit_rate 200k;\nlimit_rate_after 1m;\n"];
		yield 'gzip' => ["gzip on;\ngzip_types text/css application/javascript;\n"];
		yield 'Block' => ["location /api {\n\tproxy_pass http://127.0.0.1:9000;\n\tproxy_set_header Host \$host;\n}\n"];
		yield 'verschachtelt' => ["location /a {\n\tlocation /a/b {\n\t\texpires 1d;\n\t}\n}\n"];
		yield 'Kommentar und Leerzeilen' => ["# nur ein Kommentar\n\n\texpires 1d;\n\n"];
		yield 'leer' => [''];
		yield 'nur Leerraum' => ["\n  \n"];
	}

	#[DataProvider('allowedSnippets')]
	public function testAcceptsAllowedDirectives(string $text): void
	{
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testEmptyIsRecognised(): void
	{
		self::assertTrue(NginxSnippet::fromString('')->isEmpty());
		self::assertTrue(NginxSnippet::fromString("\n \n")->isEmpty());
		self::assertFalse(NginxSnippet::fromString("expires 1d;\n")->isEmpty());
		self::assertSame("expires 1d;\n", (string)NginxSnippet::fromString("expires 1d;\n"));
	}

	/** @return iterable<string, array{string, string}> */
	public static function forbiddenSnippets(): iterable
	{
		yield 'root' => ["root /etc;\n", 'root'];
		yield 'alias' => ["alias /etc;\n", 'alias'];
		yield 'listen' => ["listen 8081;\n", 'listen'];
		yield 'server_name' => ["server_name boese.example;\n", 'server_name'];
		yield 'ssl_certificate' => ["ssl_certificate /tmp/x.pem;\n", 'ssl_certificate'];
		yield 'auth_basic' => ["auth_basic off;\n", 'auth_basic'];
		yield 'satisfy' => ["satisfy any;\n", 'satisfy'];
		yield 'allow' => ["allow all;\n", 'allow'];
		yield 'deny' => ["deny all;\n", 'deny'];
		yield 'include' => ["include /etc/passwd;\n", 'include'];
		yield 'access_log' => ["access_log /tmp/x.log;\n", 'access_log'];
		yield 'error_log' => ["error_log /tmp/x.log;\n", 'error_log'];
		yield 'load_module' => ["load_module modules/x.so;\n", 'load_module'];
		yield 'user' => ["user root;\n", 'user'];
		yield 'if' => ["if (\$host) {\n\treturn 404;\n}\n", 'if'];
		yield 'unbekannt' => ["gibtsnicht an;\n", 'gibtsnicht'];
	}

	#[DataProvider('forbiddenSnippets')]
	public function testRejectsForbiddenDirectives(string $text, string $directive): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($directive);
		NginxSnippet::fromString($text);
	}

	public function testRejectsBlockEscape(): void
	{
		$escape = "expires 1d;\n}\nserver {\n\tlisten 8081;\n\troot /etc;\n}\n";
		try {
			NginxSnippet::fromString($escape);
			self::fail('Ausbruch aus dem server-Block muss abgelehnt werden');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Zeile 2', $e->getMessage());
			self::assertStringContainsString('schließende', $e->getMessage());
		}
	}

	public function testRejectsUnbalancedBraces(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('geschlossen');
		NginxSnippet::fromString("location /a {\n\texpires 1d;\n");
	}

	public function testRejectsTooDeepNesting(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('verschachtelt');
		NginxSnippet::fromString("location /a {\n\tlocation /b {\n\t\tlocation /c {\n\t\t\texpires 1d;\n\t\t}\n\t}\n}\n");
	}

	public function testRejectsOversizedSnippet(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('64');
		NginxSnippet::fromString(str_repeat("expires 1d;\n", 6000));
	}

	public function testErrorMessageNamesTheLine(): void
	{
		try {
			NginxSnippet::fromString("expires 1d;\n# Kommentar\n\nroot /etc;\n");
			self::fail('Exception erwartet');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Zeile 4', $e->getMessage());
			self::assertStringContainsString('root', $e->getMessage());
		}
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Value/NginxSnippetTest.php`
Erwartet: `Class "VhostAdmin\Value\NginxSnippet" not found`.

- [ ] **Step 3: Implementieren**

`src/lib/VhostAdmin/Value/NginxSnippet.php`:
```php
<?php
declare(strict_types=1);

/**
 * Wertobjekt: das über die Oberfläche gepflegte nginx-Snippet eines vHosts.
 *
 * Geprüft wird gegen eine Positivliste: Unbekannte Direktiven gelten als verboten.
 * Wichtigste Prüfung ist die Klammerbilanz – ohne sie könnte eine schließende
 * Klammer den umgebenden server-Block beenden und danach ein eigener server-Block
 * mit beliebigen Direktiven folgen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:10
 */

namespace VhostAdmin\Value;

final class NginxSnippet
{
	/** Direktiven, die unverändert erlaubt sind. */
	private const ALLOWED = [
		'client_max_body_size', 'expires', 'add_header', 'more_set_headers', 'charset',
		'autoindex', 'index', 'error_page', 'rewrite', 'return', 'try_files',
		'limit_rate', 'limit_rate_after',
	];

	/** Präfixe, unter denen alle Direktiven erlaubt sind. */
	private const ALLOWED_PREFIXES = ['gzip', 'proxy_'];

	/** Einzige Direktive, die einen Block öffnen darf. */
	private const BLOCK_DIRECTIVE = 'location';

	private const MAX_BYTES = 65536;
	private const MAX_DEPTH = 2;

	private function __construct(public readonly string $value)
	{
	}

	/**
	 * Prüft den Text und erzeugt das Wertobjekt.
	 *
	 * @throws \InvalidArgumentException mit Zeilennummer und beanstandeter Direktive
	 */
	public static function fromString(string $text): self
	{
		if (strlen($text) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Das Snippet ist größer als 64 KB.');
		}
		$depth = 0;
		$lines = preg_split('/\R/', $text) ?: [];
		foreach ($lines as $index => $line) {
			$number = $index + 1;
			$trimmed = trim($line);
			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				continue;
			}
			if (str_starts_with($trimmed, '}')) {
				if ($depth === 0) {
					throw new \InvalidArgumentException(
						"Zeile $number: schließende Klammer ohne geöffneten Block – das würde den server-Block verlassen."
					);
				}
				$depth--;
				$trimmed = trim(substr($trimmed, 1));
				if ($trimmed === '') {
					continue;
				}
			}
			$directive = strtolower((string)(preg_split('/[\s;{]/', $trimmed, 2)[0] ?? ''));
			if ($directive === '') {
				continue;
			}
			$opensBlock = str_ends_with($trimmed, '{');
			if ($opensBlock) {
				if ($directive !== self::BLOCK_DIRECTIVE) {
					throw new \InvalidArgumentException(
						"Zeile $number: nur \"location\" darf einen Block öffnen, nicht \"$directive\"."
					);
				}
				$depth++;
				if ($depth > self::MAX_DEPTH) {
					throw new \InvalidArgumentException(
						"Zeile $number: Blöcke dürfen höchstens " . self::MAX_DEPTH . " Ebenen tief verschachtelt sein."
					);
				}
				continue;
			}
			if (!self::isAllowed($directive)) {
				throw new \InvalidArgumentException("Zeile $number: Direktive \"$directive\" ist nicht erlaubt.");
			}
		}
		if ($depth !== 0) {
			throw new \InvalidArgumentException('Es wurde ein Block geöffnet, aber nicht geschlossen.');
		}
		return new self($text);
	}

	/**
	 * Enthält das Snippet außer Leerraum nichts?
	 */
	public function isEmpty(): bool
	{
		return trim($this->value) === '';
	}

	/**
	 * Der geprüfte Text, wie er in die Datei geschrieben wird.
	 */
	public function __toString(): string
	{
		return $this->value;
	}

	/**
	 * Steht die Direktive auf der Positivliste oder unter einem erlaubten Präfix?
	 */
	private static function isAllowed(string $directive): bool
	{
		if (in_array($directive, self::ALLOWED, true)) {
			return true;
		}
		foreach (self::ALLOWED_PREFIXES as $prefix) {
			if (str_starts_with($directive, $prefix)) {
				return true;
			}
		}
		return false;
	}
}
```

- [ ] **Step 4: Test ausführen – muss bestehen**

Run: `phpunit tests/Value/NginxSnippetTest.php`
Erwartet: alle Tests grün. Schlägt ein Datenprovider-Fall fehl, zuerst prüfen, ob der Testfall die Spec korrekt wiedergibt, dann die Implementierung.

- [ ] **Step 5: Gesamte Suite und Commit**

```bash
phpunit
git add src/lib/VhostAdmin/Value/NginxSnippet.php tests/Value/NginxSnippetTest.php
git commit -m "Wertobjekt NginxSnippet mit Positivliste und Klammerprüfung"
```

---

### Task 3: ConfigRenderer auf das neue Layout umstellen

**Files:**
- Modify: `src/lib/VhostAdmin/Nginx/ConfigRenderer.php` (Konstruktor, `serverConfig()` ca. Zeile 87-137)
- Test: `tests/Nginx/ConfigRendererTest.php` (Sollausgaben anpassen)

**Interfaces:**
- Consumes: `VhostAdmin\VhostLayout` (`docroot()`, `webDir()`, `baseDir()`, `accessLog()`, `errorLog()`, `confDir()`) aus Task 1
- Produces: `ConfigRenderer::__construct(Config $config, VhostLayout $layout)` – **zweiter Parameter ist neu**; alle bisherigen Methodennamen bleiben unverändert (`htpasswdPath`, `authSnippetPath`, `serverConfigPath`, `htpasswd`, `authSnippet`, `serverConfig`).

- [ ] **Step 1: Testerwartungen anpassen (Test zuerst)**

In `tests/Nginx/ConfigRendererTest.php` die Hilfsmethode `renderer()` auf den neuen Konstruktor umstellen und `wwwOwner` mitgeben:
```php
	private function renderer(bool $ipv6 = true): ConfigRenderer
	{
		$config = Config::fromArray([
			'wwwRoot' => '/var/www', 'wwwOwner' => 'user', 'authDir' => '/etc/nginx/auth',
			'sitesAvailable' => '/etc/nginx/sites-available', 'letsEncryptLive' => '/etc/letsencrypt/live',
			'ipv6' => $ipv6,
		]);
		return new ConfigRenderer($config, new VhostLayout($config));
	}
```
Dazu `use VhostAdmin\VhostLayout;` ergänzen.

Den gemeinsamen Block in `testDomainWithoutSsl` so erwarten (Docroot jetzt unter `web/`, Logs im vHost, `include` für das Snippet):
```php
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
        root /var/www/example.com/web;
    }
    root /var/www/example.com/web/public;
    index index.html index.htm;
    access_log /var/www/example.com/logs/access.log;
    error_log  /var/www/example.com/logs/error.log;
    include /etc/nginx/auth/example.com.conf;
    include /var/www/example.com/conf/*.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}
```

`testLocalhostBindsLoopbackOnly` entsprechend:
```php
	public function testLocalhostBindsLoopbackOnly(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		$expected = <<<NG
# generiert von vhost – nicht manuell bearbeiten
server {
    listen 127.0.0.1:3000;
    listen [::1]:3000;
    server_name localhost;
    root /var/www/localhost-3000/web;
    index index.html index.htm;
    access_log /var/www/localhost-3000/logs/access.log;
    error_log  /var/www/localhost-3000/logs/error.log;
    include /etc/nginx/auth/localhost-3000.conf;
    include /var/www/localhost-3000/conf/*.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}
```

In `testDomainWithSslRedirectsAndServes443` zwei Zusicherungen ergänzen (der Rest bleibt):
```php
		self::assertStringContainsString('root /var/www/example.com/web;', $out);
		self::assertSame(1, substr_count($out, 'include /var/www/example.com/conf/*.conf;'));
		self::assertStringContainsString('access_log /var/www/example.com/logs/access.log;', $out);
```
Die bestehende Zusicherung `self::assertSame(2, substr_count($out, 'server {'));` und die ACME-Zählung `self::assertSame(1, substr_count($out, 'acme-challenge'));` bleiben unverändert.

Neuer Test, der die ACME-Location festnagelt (certbot muss weiter in `web/.well-known/` schreiben):
```php
	public function testAcmeLocationPointsToWebDirectoryEvenWithSubdirectory(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public', true, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("        root /var/www/example.com/web;\n", $out);
		self::assertStringContainsString('    root /var/www/example.com/web/public;', $out);
	}
```

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/Nginx`
Erwartet: FAIL – der Konstruktor nimmt noch keinen zweiten Parameter bzw. die Sollausgaben stimmen nicht.

- [ ] **Step 3: Renderer umstellen**

In `src/lib/VhostAdmin/Nginx/ConfigRenderer.php`:

Konstruktor und `use`:
```php
use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;
```
```php
	/**
	 * @param Config      $config Pfade der Installation
	 * @param VhostLayout $layout Quelle für Docroot, Logdateien und Snippet-Ordner
	 */
	public function __construct(private readonly Config $config, private readonly VhostLayout $layout)
	{
	}
```

In `serverConfig()` den gemeinsamen Block ersetzen:
```php
		$root = $this->layout->docroot($v);
		$accessLog = $this->layout->accessLog($v);
		$errorLog = $this->layout->errorLog($v);
		$authInclude = $this->authSnippetPath($v);
		$customInclude = $this->layout->confDir($v) . '/*.conf';
		$common = <<<NG
    root $root;
    index index.html index.htm;
    access_log $accessLog;
    error_log  $errorLog;
    include $authInclude;
    include $customInclude;
    location / {
        try_files \$uri \$uri/ =404;
    }
NG;
```
Die Zeile `$slug = $v->slug();` entfällt, falls `$slug` danach nicht mehr gebraucht wird (`grep -n '\$slug' src/lib/VhostAdmin/Nginx/ConfigRenderer.php` prüfen – die Pfadmethoden nutzen `slug()` selbst).

Im ACME-Block `$base` durch das Web-Verzeichnis ersetzen:
```php
		$acmeRoot = $this->layout->webDir($v);
		$acme = <<<NG
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root $acmeRoot;
    }
NG;
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit tests/Nginx`
Erwartet: grün. Danach `phpunit` gesamt – `VhostServiceTest` und andere schlagen jetzt fehl, weil sie den Renderer mit einem Parameter bauen; stelle **nur die Konstruktion** in den betroffenen Testdateien und in `src/bin/vhost.php` um (`new ConfigRenderer($config, new VhostLayout($config))`), nicht deren Erwartungen – die kommen in Task 4.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/Nginx/ConfigRenderer.php tests/Nginx/ConfigRendererTest.php src/bin/vhost.php tests/VhostServiceTest.php tests/Cli/ApplicationTest.php
git commit -m "Renderer nutzt VhostLayout: Docroot unter web/, Logs im vHost, Snippet-include"
```

---

### Task 4: Service legt die neue Struktur an

**Files:**
- Modify: `src/lib/VhostAdmin/VhostService.php` (Konstruktor, `create()`, `makeDirectories()`, `writeIndex()`, `remove()`, `enableSsl()`, `purgeBaseDir()` – alle Stellen mit `baseDir(`/`docroot(`)
- Test: `tests/VhostServiceTest.php`

**Interfaces:**
- Consumes: `VhostLayout` (Task 1), `DirectorySpec` (Task 1)
- Produces: `VhostService::__construct(Config, VhostRepository, ConfigRenderer, ReloaderInterface, CertbotInterface, VhostLayout)` – **sechster Parameter neu**; neue öffentliche Methode `applyPermissions(Vhost $vhost): void` (setzt die Soll-Rechte aller Verzeichnisse neu, nur als root wirksam).

- [ ] **Step 1: Failing tests schreiben**

In `tests/VhostServiceTest.php` den Aufbau um das Layout erweitern:
```php
	private VhostLayout $layout;
```
und in `setUp()` nach dem `Config`-Aufbau:
```php
		$this->layout = new VhostLayout($this->config);
		$this->service = new VhostService(
			$this->config, $this->repo, new ConfigRenderer($this->config, $this->layout),
			$this->reloader, $this->certbot, $this->layout
		);
```
Dazu `use VhostAdmin\VhostLayout;`. In der `Config::fromArray([...])` dieses Tests `'wwwOwner' => 'user'` ergänzen, damit die Rechte-Erwartungen eindeutig sind.

Der bestehende Test `testCreateDomainWithSubdirectoryCreatesFilesAndReloadsOnce` erwartet Pfade ohne `web/` – passe seine Pfadzusicherungen an (`…/www/example.com/web/public/html`, `index.html` darin) und ergänze die neuen Ordner:
```php
	public function testCreateDomainCreatesFullLayout(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), SubDirectory::fromString('public/html'));
		$base = $this->dir . '/www/example.com';
		self::assertDirectoryExists($base . '/web/public/html');
		self::assertDirectoryExists($base . '/conf');
		self::assertDirectoryExists($base . '/cert');
		self::assertDirectoryExists($base . '/private');
		self::assertDirectoryExists($base . '/logs');
		self::assertFileExists($base . '/web/public/html/index.html');
		self::assertStringContainsString('<h1>200</h1>', (string)file_get_contents($base . '/web/public/html/index.html'));
		self::assertStringContainsString('example.com', (string)file_get_contents($base . '/web/public/html/index.html'));
		self::assertSame(1, $this->reloader->calls);
	}

	public function testCreateAppliesModesFromLayout(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$expected = [];
		foreach ($this->layout->directories($v) as $spec) {
			$expected[$spec->path] = $spec->mode;
		}
		foreach ($expected as $path => $mode) {
			self::assertDirectoryExists($path);
			// Ohne root werden Besitzer und Gruppe nicht gesetzt, die Rechte aber schon.
			self::assertSame(
				sprintf('%04o', $mode & 07777),
				sprintf('%04o', (fileperms($path) ?: 0) & 07777),
				"Modus von $path"
			);
		}
	}

	public function testLocalhostAlsoGetsLayout(): void
	{
		$v = $this->service->createLocal(Port::fromString('3000'), null, false);
		$base = $this->dir . '/www/localhost-3000';
		foreach (['web', 'conf', 'cert', 'private', 'logs'] as $sub) {
			self::assertDirectoryExists($base . '/' . $sub);
		}
		self::assertStringContainsString('root ' . $base . '/web;', (string)file_get_contents($this->dir . '/avail/localhost-3000.conf'));
	}

	public function testEnableSslCreatesCertificateSymlinks(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->enableSsl($v);
		$certDir = $this->dir . '/www/example.com/cert';
		self::assertTrue(is_link($certDir . '/fullchain.pem'));
		self::assertTrue(is_link($certDir . '/privkey.pem'));
		self::assertSame($this->dir . '/le/example.com/fullchain.pem', readlink($certDir . '/fullchain.pem'));
	}

	public function testApplyPermissionsRepairsModes(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		chmod($this->dir . '/www/example.com/conf', 0777);
		$this->service->applyPermissions($v);
		self::assertSame('0750', sprintf('%04o', (fileperms($this->dir . '/www/example.com/conf') ?: 0) & 07777));
	}
```
Ersetze den alten `testCreateDomainWithSubdirectoryCreatesFilesAndReloadsOnce` durch `testCreateDomainCreatesFullLayout`. In allen weiteren bestehenden Tests dieser Datei, die Pfade wie `'/www/example.com/index.html'` oder `'/www/example.com/public'` erwarten, jeweils `web/` einfügen (`grep -n "www/example.com" tests/VhostServiceTest.php` zeigt die Stellen; auch `testCreateKeepsExistingIndex` und `testRemoveKeepsFilesUnlessPurged` betroffen). `testCreateKeepsExistingIndex` muss die vorhandene Datei künftig unter `web/` ablegen:
```php
		mkdir($this->dir . '/www/example.com/web', 0777, true);
		file_put_contents($this->dir . '/www/example.com/web/index.html', 'eigene Seite');
```

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/VhostServiceTest.php`
Erwartet: FAIL – Konstruktor kennt den sechsten Parameter nicht, `applyPermissions()` fehlt, Ordner werden nicht angelegt.

- [ ] **Step 3: Service umstellen**

`src/lib/VhostAdmin/VhostService.php`:

Konstruktor erweitern (und `use VhostAdmin\VhostLayout;` – die Klasse liegt im selben Namespace, also kein `use` nötig):
```php
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly ConfigRenderer $renderer,
		private readonly ReloaderInterface $reloader,
		private readonly CertbotInterface $certbot,
		private readonly VhostLayout $layout,
	) {
	}
```

`makeDirectories()` vollständig ersetzen – sie arbeitet jetzt die Liste aus dem Layout ab:
```php
	/**
	 * Alle Verzeichnisse des vHosts anlegen und ihre Soll-Rechte setzen.
	 *
	 * Die Liste kommt aus VhostLayout::directories() (Eltern vor Kindern), damit Service,
	 * Installer und Migration dieselben Rechte anwenden. Jedes Verzeichnis wird einzeln
	 * geprüft und angelegt: innerhalb des Basisordners (für www-data beschreibbar) könnte
	 * ein Segment ein untergeschobener Symlink sein.
	 */
	private function makeDirectories(Vhost $vhost): void
	{
		foreach ($this->layout->directories($vhost) as $spec) {
			$this->makeDirectory($spec->path);
			$this->applySpec($spec);
		}
	}

	/**
	 * Legt ein einzelnes Verzeichnis an, falls es fehlt.
	 *
	 * @throws \RuntimeException wenn der Pfad ein Symlink ist oder sich nicht anlegen lässt
	 */
	private function makeDirectory(string $path): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
		if (!is_dir($path) && !mkdir($path, 0775, true)) {
			throw new \RuntimeException("Kann $path nicht anlegen");
		}
	}

	/**
	 * Setzt Besitzer, Gruppe und Rechte eines Verzeichnisses gemäß Vorgabe.
	 *
	 * Besitzer und Gruppe nur als root; die Rechte werden immer gesetzt, damit die
	 * Tests ohne root dieselbe Wirkung prüfen können.
	 *
	 * @throws \RuntimeException wenn der Pfad ein Symlink ist
	 */
	private function applySpec(DirectorySpec $spec): void
	{
		if (is_link($spec->path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: {$spec->path}");
		}
		if ($this->isRoot()) {
			if (!@chown($spec->path, $spec->owner)) {
				chown($spec->path, $this->config->wwwGroup);
			}
			chgrp($spec->path, $spec->group);
		}
		chmod($spec->path, $spec->mode);
	}
```
Der Aufruf in `create()` verliert seinen zweiten Parameter: `$this->makeDirectories($vhost);`.

Neue öffentliche Methode (direkt nach `render()` einfügen):
```php
	/**
	 * Soll-Rechte aller Verzeichnisse eines vHosts neu setzen.
	 *
	 * Für den CLI-Befehl "fix-permissions" nach manuellen Eingriffen.
	 */
	public function applyPermissions(Vhost $vhost): void
	{
		foreach ($this->layout->directories($vhost) as $spec) {
			if (is_dir($spec->path)) {
				$this->applySpec($spec);
			}
		}
	}
```

Alle verbleibenden `$vhost->baseDir($this->config)` durch `$this->layout->baseDir($vhost)` und `$vhost->docroot($this->config)` durch `$this->layout->docroot($vhost)` ersetzen (in `remove()`, `purgeBaseDir()`, `writeIndex()`, `enableSsl()`). In `writeIndex()` bleibt die Symlink-Prüfung unverändert; die eigene Methode `own()` wird nur noch dort gebraucht – lass sie stehen.

In `enableSsl()` nach dem erfolgreichen Setzen des Flags die Symlinks anlegen:
```php
		$this->repository->setSsl($vhost->id, true);
		$this->linkCertificates($vhost);
		$this->render($this->load($vhost->name));
		return $output;
```
und die neue private Methode ergänzen:
```php
	/**
	 * Legt in cert/ Symlinks auf die Zertifikatsdateien der Domain an.
	 *
	 * Reine Sichtbarkeit: die ssl_certificate-Direktiven zeigen weiterhin direkt nach
	 * /etc/letsencrypt/live, damit eine defekte Symlink-Kette den Start von nginx nicht
	 * verhindern kann.
	 */
	private function linkCertificates(Vhost $vhost): void
	{
		$live = $this->config->letsEncryptLive . '/' . $vhost->name;
		$certDir = $this->layout->certDir($vhost);
		if (!is_dir($certDir)) {
			return;
		}
		foreach (['fullchain.pem', 'privkey.pem'] as $file) {
			$link = $certDir . '/' . $file;
			if (is_link($link)) {
				unlink($link);
			}
			if (!file_exists($link)) {
				symlink($live . '/' . $file, $link);
			}
		}
	}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alles grün. Die Konstruktion in `src/bin/vhost.php` und `tests/Cli/ApplicationTest.php` um den sechsten Parameter erweitern.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/VhostService.php tests/VhostServiceTest.php src/bin/vhost.php tests/Cli/ApplicationTest.php
git commit -m "Service legt web/conf/cert/private/logs mit Soll-Rechten an, cert-Symlinks bei HTTPS"
```

---

### Task 5: Snippet setzen mit Rücknahme

**Files:**
- Modify: `src/lib/VhostAdmin/VhostService.php` (neue Methode `setSnippet()`, Rücknahme in `render()` erweitern)
- Test: `tests/VhostServiceTest.php`

**Interfaces:**
- Consumes: `Value\NginxSnippet` (Task 2), `VhostLayout::confFile()` (Task 1)
- Produces: `VhostService::setSnippet(Vhost $vhost, NginxSnippet $snippet): void` – schreibt `conf/custom.conf` (leeres Snippet löscht die Datei), rendert neu; schlägt der Reload fehl, wird die Datei auf den vorherigen Stand zurückgesetzt und die Ausnahme weitergeworfen. `VhostService::snippet(Vhost $vhost): string` liefert den aktuellen Inhalt (leer, wenn keine Datei existiert).

- [ ] **Step 1: Failing tests schreiben**

```php
	public function testSetSnippetWritesFileAndReloads(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$before = $this->reloader->calls;
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		self::assertFileExists($file);
		self::assertSame("expires 1d;\n", file_get_contents($file));
		self::assertSame("expires 1d;\n", $this->service->snippet($v));
		self::assertSame($before + 1, $this->reloader->calls);
		self::assertSame('0640', sprintf('%04o', (fileperms($file) ?: 0) & 07777));
	}

	public function testSetSnippetReplacesAndEmptiesFile(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$this->service->setSnippet($v, NginxSnippet::fromString("autoindex on;\n"));
		self::assertSame("autoindex on;\n", file_get_contents($file));
		$this->service->setSnippet($v, NginxSnippet::fromString(''));
		self::assertFileDoesNotExist($file);
		self::assertSame('', $this->service->snippet($v));
	}

	public function testSetSnippetRestoresPreviousContentWhenReloadFails(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$this->reloader->failWith = 'nginx -t fehlgeschlagen: kaputt';
		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("autoindex on;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('nginx -t', $e->getMessage());
		}
		self::assertSame("expires 1d;\n", file_get_contents($file), 'alter Stand muss zurück sein');
	}

	public function testSetSnippetRemovesNewFileWhenReloadFails(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException) {
		}
		self::assertFileDoesNotExist($file, 'neu angelegte Datei muss wieder weg sein');
	}
```
Dazu `use VhostAdmin\Value\NginxSnippet;` in der Testdatei ergänzen.

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/VhostServiceTest.php`
Erwartet: `Call to undefined method VhostAdmin\VhostService::setSnippet()`.

- [ ] **Step 3: Implementieren**

In `src/lib/VhostAdmin/VhostService.php` `use VhostAdmin\Value\NginxSnippet;` zu den bestehenden `use`-Zeilen ergänzen und beide Methoden einfügen (nach `disableSsl()`):
```php
	/**
	 * Konfigurations-Snippet des vHosts setzen.
	 *
	 * Der Text ist durch NginxSnippet bereits gegen die Positivliste geprüft. Schlägt
	 * danach "nginx -t" fehl (z.B. wegen einer syntaktisch falschen, aber erlaubten
	 * Direktive), wird die Datei auf den vorherigen Stand zurückgesetzt und die
	 * Ausnahme weitergeworfen – die Oberfläche zeigt dann die nginx-Meldung an.
	 *
	 * Ein leeres Snippet entfernt die Datei.
	 */
	public function setSnippet(Vhost $vhost, NginxSnippet $snippet): void
	{
		$file = $this->layout->confFile($vhost);
		if (is_link($file)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $file");
		}
		$previous = is_file($file) ? (string)file_get_contents($file) : null;
		if ($snippet->isEmpty()) {
			if ($previous !== null) {
				unlink($file);
			}
		} else {
			file_put_contents($file, $snippet->value);
			if ($this->isRoot()) {
				chown($file, 'root');
				chgrp($file, $this->config->wwwGroup);
			}
			chmod($file, 0640);
		}
		try {
			$this->render($this->load($vhost->name));
		} catch (\Throwable $e) {
			if ($previous === null) {
				if (is_file($file)) {
					unlink($file);
				}
			} else {
				file_put_contents($file, $previous);
				chmod($file, 0640);
			}
			throw $e;
		}
	}

	/**
	 * Aktueller Inhalt des Konfigurations-Snippets; leer, wenn keines gesetzt ist.
	 */
	public function snippet(Vhost $vhost): string
	{
		$file = $this->layout->confFile($vhost);
		return is_file($file) ? (string)file_get_contents($file) : '';
	}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alles grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/VhostService.php tests/VhostServiceTest.php
git commit -m "Snippet setzen mit Rücknahme bei fehlgeschlagenem nginx-Test"
```

---

### Task 6: LayoutMigrator

**Files:**
- Create: `src/lib/VhostAdmin/Migration/LayoutMigrator.php`, `tests/Migration/LayoutMigratorTest.php`

**Interfaces:**
- Consumes: `VhostLayout` (Task 1), `DirectorySpec` (Task 1), `VhostRepository`, `Config`
- Produces: `VhostAdmin\Migration\LayoutMigrator::__construct(Config $config, VhostRepository $repository, VhostLayout $layout)` mit:
  - `pending(): list<Vhost>` – Hosts, deren Basisordner existiert, aber kein `web/` enthält
  - `migrate(Vhost $vhost): void` – legt `web/` an, verschiebt alles außer den fünf Strukturordnern hinein, legt die übrigen Ordner an, setzt die Rechte, verschiebt vorhandene Logs aus `/var/log/nginx/<slug>.*.log` nach `logs/`
  - `backup(array $vhosts, string $targetDir): string` – Tar-Archiv aller betroffenen Basisordner, gibt den Pfad zurück; wirft bei Fehlschlag

- [ ] **Step 1: Failing test schreiben**

`tests/Migration/LayoutMigratorTest.php`:
```php
<?php
declare(strict_types=1);

/**
 * Tests der Migration alter vHost-Verzeichnisse auf die neue Struktur.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:30
 */

namespace Tests\Migration;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Migration\LayoutMigrator;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostLayout;

final class LayoutMigratorTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private VhostLayout $layout;
	private LayoutMigrator $migrator;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/www');
		mkdir($this->dir . '/nginxlogs');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'wwwOwner' => 'user',
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->layout = new VhostLayout($this->config);
		$this->migrator = new LayoutMigrator($this->config, $this->repo, $this->layout, $this->dir . '/nginxlogs');
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testPendingListsOnlyOldHosts(): void
	{
		$alt = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$neu = $this->repo->insert('neu.example', VhostKind::Domain, null, null, true);
		mkdir($this->dir . '/www/alt.example');
		mkdir($this->dir . '/www/neu.example/web', 0775, true);
		$names = array_map(static fn($v) => $v->name, $this->migrator->pending());
		self::assertSame(['alt.example'], $names);
	}

	public function testMigrateMovesContentIntoWebAndCreatesFolders(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, 'www/src', true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base . '/www/src', 0775, true);
		file_put_contents($base . '/www/src/index.html', 'Inhalt');
		file_put_contents($base . '/.htaccess-Rest', 'egal');
		file_put_contents($this->dir . '/nginxlogs/alt.example.access.log', "zugriff\n");
		file_put_contents($this->dir . '/nginxlogs/alt.example.error.log', "fehler\n");

		$this->migrator->migrate($v);

		self::assertSame('Inhalt', file_get_contents($base . '/web/www/src/index.html'));
		self::assertFileExists($base . '/web/.htaccess-Rest');
		self::assertDirectoryDoesNotExist($base . '/www');
		foreach (['web', 'conf', 'cert', 'private', 'logs'] as $sub) {
			self::assertDirectoryExists($base . '/' . $sub);
		}
		self::assertSame("zugriff\n", file_get_contents($base . '/logs/access.log'));
		self::assertSame("fehler\n", file_get_contents($base . '/logs/error.log'));
		self::assertFileDoesNotExist($this->dir . '/nginxlogs/alt.example.access.log');
		self::assertSame('0750', sprintf('%04o', (fileperms($base . '/conf') ?: 0) & 07777));
		self::assertSame('2775', sprintf('%04o', (fileperms($base . '/web') ?: 0) & 07777));
	}

	public function testMigrateIsIdempotent(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		file_put_contents($base . '/index.html', 'Inhalt');
		$this->migrator->migrate($v);
		self::assertSame([], $this->migrator->pending());
		$this->migrator->migrate($v);
		self::assertSame('Inhalt', file_get_contents($base . '/web/index.html'));
		self::assertFileDoesNotExist($base . '/web/web');
	}

	public function testMigrateSkipsMissingBaseDirectory(): void
	{
		$v = $this->repo->insert('fehlt.example', VhostKind::Domain, null, null, true);
		$this->migrator->migrate($v);
		self::assertDirectoryDoesNotExist($this->dir . '/www/fehlt.example');
	}

	public function testBackupCreatesArchiveContainingTheFiles(): void
	{
		$v = $this->repo->insert('alt.example', VhostKind::Domain, null, null, true);
		$base = $this->dir . '/www/alt.example';
		mkdir($base);
		file_put_contents($base . '/index.html', 'Inhalt');
		$target = $this->dir . '/backups';
		mkdir($target);
		$archive = $this->migrator->backup([$v], $target);
		self::assertFileExists($archive);
		self::assertStringStartsWith($target . '/vhost-admin-migration-', $archive);
		exec('tar tzf ' . escapeshellarg($archive), $out, $code);
		self::assertSame(0, $code);
		self::assertNotEmpty(preg_grep('#alt\.example/index\.html$#', $out));
	}

	public function testBackupOfNothingThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->migrator->backup([], $this->dir . '/backups');
	}
}
```

- [ ] **Step 2: Test ausführen – muss fehlschlagen**

Run: `phpunit tests/Migration`
Erwartet: `Class "VhostAdmin\Migration\LayoutMigrator" not found`.

- [ ] **Step 3: Implementieren**

`src/lib/VhostAdmin/Migration/LayoutMigrator.php`:
```php
<?php
declare(strict_types=1);

/**
 * Bringt vorhandene vHosts von der flachen Struktur auf web/conf/cert/private/logs.
 *
 * Alte Installationen hatten den Docroot direkt im Basisordner. Die Migration legt
 * web/ an, verschiebt alles Vorhandene dorthin und erzeugt die übrigen Ordner. Der
 * Wert "subdir" in der Datenbank bleibt unverändert: aus <base>/www/src wird
 * <base>/web/www/src.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-18 10:30
 */

namespace VhostAdmin\Migration;

use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;

final class LayoutMigrator
{
	/** Ordner der neuen Struktur, die beim Verschieben liegen bleiben. */
	private const STRUCTURE = ['web', 'conf', 'cert', 'private', 'logs'];

	/**
	 * @param string $nginxLogDir Verzeichnis der bisherigen nginx-Logdateien
	 */
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly VhostLayout $layout,
		private readonly string $nginxLogDir = '/var/log/nginx',
	) {
	}

	/**
	 * vHosts, die noch die alte Struktur haben.
	 *
	 * @return list<Vhost>
	 */
	public function pending(): array
	{
		return array_values(array_filter(
			$this->repository->all(),
			fn(Vhost $vhost): bool => $this->layout->needsMigration($vhost)
		));
	}

	/**
	 * Sichert die Basisordner der übergebenen vHosts als Tar-Archiv.
	 *
	 * @param list<Vhost> $vhosts
	 * @return string Pfad des erzeugten Archivs
	 * @throws \RuntimeException wenn nichts zu sichern ist oder tar fehlschlägt
	 */
	public function backup(array $vhosts, string $targetDir): string
	{
		if ($vhosts === []) {
			throw new \RuntimeException('Keine vHosts zu sichern.');
		}
		if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
			throw new \RuntimeException("Kann Sicherungsverzeichnis nicht anlegen: $targetDir");
		}
		$archive = $targetDir . '/vhost-admin-migration-' . date('Ymd-His') . '.tar.gz';
		$slugs = [];
		foreach ($vhosts as $vhost) {
			if (is_dir($this->layout->baseDir($vhost))) {
				$slugs[] = escapeshellarg($vhost->slug());
			}
		}
		if ($slugs === []) {
			throw new \RuntimeException('Keine vorhandenen Basisordner zu sichern.');
		}
		$command = sprintf(
			'tar czf %s -C %s %s 2>&1',
			escapeshellarg($archive),
			escapeshellarg($this->config->wwwRoot),
			implode(' ', $slugs)
		);
		exec($command, $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException("Sicherung fehlgeschlagen:\n" . implode("\n", $output));
		}
		return $archive;
	}

	/**
	 * Bringt einen einzelnen vHost auf die neue Struktur. Mehrfach aufrufbar.
	 */
	public function migrate(Vhost $vhost): void
	{
		$base = $this->layout->baseDir($vhost);
		if (!is_dir($base)) {
			return;
		}
		if ($this->layout->needsMigration($vhost)) {
			$this->moveContentIntoWeb($base, $this->layout->webDir($vhost));
		}
		foreach ($this->layout->directories($vhost) as $spec) {
			$this->ensureDirectory($spec);
		}
		$this->moveLogs($vhost);
	}

	/**
	 * Verschiebt den bisherigen Inhalt des Basisordners nach web/.
	 */
	private function moveContentIntoWeb(string $base, string $webDir): void
	{
		if (!is_dir($webDir) && !mkdir($webDir, 0775)) {
			throw new \RuntimeException("Kann $webDir nicht anlegen");
		}
		foreach (scandir($base) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || in_array($entry, self::STRUCTURE, true)) {
				continue;
			}
			if (!rename($base . '/' . $entry, $webDir . '/' . $entry)) {
				throw new \RuntimeException("Kann $base/$entry nicht nach $webDir verschieben");
			}
		}
	}

	/**
	 * Verschiebt vorhandene nginx-Logdateien des vHosts nach logs/.
	 */
	private function moveLogs(Vhost $vhost): void
	{
		$pairs = [
			$this->nginxLogDir . '/' . $vhost->slug() . '.access.log' => $this->layout->accessLog($vhost),
			$this->nginxLogDir . '/' . $vhost->slug() . '.error.log' => $this->layout->errorLog($vhost),
		];
		foreach ($pairs as $from => $to) {
			if (is_file($from) && !is_link($from) && !file_exists($to)) {
				rename($from, $to);
			}
		}
	}

	/**
	 * Legt ein Verzeichnis an, falls es fehlt, und setzt seine Soll-Rechte.
	 */
	private function ensureDirectory(DirectorySpec $spec): void
	{
		if (is_link($spec->path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: {$spec->path}");
		}
		if (!is_dir($spec->path) && !mkdir($spec->path, 0775, true)) {
			throw new \RuntimeException("Kann {$spec->path} nicht anlegen");
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			if (!@chown($spec->path, $spec->owner)) {
				chown($spec->path, $this->config->wwwGroup);
			}
			chgrp($spec->path, $spec->group);
		}
		chmod($spec->path, $spec->mode);
	}
}
```

- [ ] **Step 4: Tests ausführen – müssen bestehen**

Run: `phpunit`
Erwartet: alles grün.

- [ ] **Step 5: Commit**

```bash
git add src/lib/VhostAdmin/Migration/LayoutMigrator.php tests/Migration/LayoutMigratorTest.php
git commit -m "LayoutMigrator: bestehende vHosts auf die neue Struktur bringen"
```

---

### Task 7: CLI-Befehle conf, fix-permissions und migrate-layout

**Files:**
- Modify: `src/lib/VhostAdmin/Cli/Application.php` (`USAGE`, Konstruktor, `dispatch()`), `src/bin/vhost.php` (Composition Root)
- Test: `tests/Cli/ApplicationTest.php`

**Interfaces:**
- Consumes: `VhostService::setSnippet()`/`snippet()`/`applyPermissions()` (Tasks 4/5), `Value\NginxSnippet` (Task 2), `Migration\LayoutMigrator` (Task 6)
- Produces: `Cli\Application::__construct(VhostService, VhostRepository, Config, VhostLayout, LayoutMigrator, $stdin, $stdout, $stderr)` – Reihenfolge verbindlich; drei neue Befehle.

- [ ] **Step 1: Failing tests schreiben**

In `tests/Cli/ApplicationTest.php` den Aufbau erweitern (`use VhostAdmin\Migration\LayoutMigrator;`, `use VhostAdmin\VhostLayout;`) und in `runCli()` die Anwendung mit den neuen Parametern bauen:
```php
		$app = new Application(
			$this->service, $this->repo, $this->config, $this->layout,
			new LayoutMigrator($this->config, $this->repo, $this->layout, $this->dir . '/nginxlogs'),
			$in, $out, $err
		);
```
In `setUp()` `$this->layout = new VhostLayout($this->config);` setzen, `mkdir($this->dir . '/nginxlogs');` ergänzen und Service/Renderer wie in Task 4 mit dem Layout bauen.

Neue Tests:
```php
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

	public function testUsageListsNewCommands(): void
	{
		[, $out] = $this->runCli(['help']);
		foreach (['vhost conf <name>', 'vhost fix-permissions', 'vhost migrate-layout'] as $line) {
			self::assertStringContainsString($line, $out);
		}
	}
```
Dazu `use VhostAdmin\VhostKind;` ergänzen, falls noch nicht vorhanden. Der Migrationstest braucht ein Sicherungsziel innerhalb des Temp-Verzeichnisses – dafür bekommt `Application` das Verzeichnis nicht als Parameter, sondern der Befehl nutzt `Config::$backupDir`. Ergänze in `src/lib/VhostAdmin/Config.php` eine weitere Eigenschaft `string $backupDir` mit Standardwert `/var/backups` (in `defaults()`-Array und Konstruktor, mit DocBlock-Zeile) und setze sie im Test auf `$this->dir . '/backups'`. Ergänze in `tests/ConfigTest.php` eine Zusicherung `self::assertSame('/var/backups', $c->backupDir);` in `testDefaultsPointToProductionPaths`.

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/Cli`
Erwartet: FAIL – Konstruktor und Befehle fehlen.

- [ ] **Step 3: Implementieren**

In `src/lib/VhostAdmin/Cli/Application.php`:

`USAGE` um drei Zeilen erweitern (nach `vhost set le_email <adresse>`):
```
  vhost conf <name>                   (nginx-Snippet per stdin; leer = entfernen)
  vhost fix-permissions [name]
  vhost migrate-layout
```

Konstruktor erweitern:
```php
	public function __construct(
		private readonly VhostService $service,
		private readonly VhostRepository $repository,
		private readonly Config $config,
		private readonly VhostLayout $layout,
		private readonly LayoutMigrator $migrator,
		private $stdin,
		private $stdout,
		private $stderr,
	) {
	}
```
mit `use VhostAdmin\Migration\LayoutMigrator;`, `use VhostAdmin\VhostLayout;`, `use VhostAdmin\Value\NginxSnippet;`.

Drei `case`-Zweige in `dispatch()` ergänzen (vor `case 'render':`):
```php
			case 'conf':
				$vhost = $this->service->load($arg(0, 'Name'));
				$snippet = NginxSnippet::fromString((string)stream_get_contents($this->stdin));
				$this->service->setSnippet($vhost, $snippet);
				$this->out($snippet->isEmpty() ? "Konfiguration entfernt.\n" : "Konfiguration übernommen.\n");
				return 0;

			case 'fix-permissions':
				$targets = isset($positional[0]) ? [$this->service->load($positional[0])] : $this->repository->all();
				foreach ($targets as $vhost) {
					$this->service->applyPermissions($vhost);
					$this->out("Rechte gesetzt: {$vhost->name}\n");
				}
				$this->out(count($targets) . " vHost(s) bearbeitet.\n");
				return 0;

			case 'migrate-layout':
				$pending = $this->migrator->pending();
				if ($pending === []) {
					$this->out("Nichts zu migrieren.\n");
					return 0;
				}
				$archive = $this->migrator->backup($pending, $this->config->backupDir);
				$this->out("Sicherung: $archive\n");
				foreach ($pending as $vhost) {
					$this->migrator->migrate($vhost);
					$this->out("Migriert: {$vhost->name} -> " . $this->layout->docroot($vhost) . "\n");
				}
				$this->service->renderAll();
				$this->out(count($pending) . " vHost(s) migriert.\n");
				return 0;
```

- [ ] **Step 4: `src/bin/vhost.php` anpassen**

Die Composition Root um Layout und Migrator erweitern:
```php
$config = Config::defaults();
$database = new Database($config);
$database->initSchema();
$repository = new VhostRepository($database);
$layout = new VhostLayout($config);
$service = new VhostService(
	$config, $repository, new ConfigRenderer($config, $layout),
	new SystemdReloader(), new CertbotClient(), $layout
);
$migrator = new LayoutMigrator($config, $repository, $layout);

exit((new Application($service, $repository, $config, $layout, $migrator, STDIN, STDOUT, STDERR))->run($argv));
```
mit den passenden `use`-Zeilen. Prüfen: `php -l src/bin/vhost.php` und `php src/bin/vhost.php help` (Usage, Exit 0, ohne root).

- [ ] **Step 5: Tests ausführen und committen**

```bash
phpunit
git add src/lib/VhostAdmin/Cli/Application.php src/lib/VhostAdmin/Config.php src/bin/vhost.php tests/Cli/ApplicationTest.php tests/ConfigTest.php
git commit -m "CLI: conf, fix-permissions und migrate-layout"
```

---

### Task 8: Oberfläche – Textfeld für das Snippet

**Files:**
- Modify: `src/lib/VhostAdmin/Web/AdminPage.php` (`commandFor()`, neue Methode `snippet()`), `src/public/index.php` (Karte mit Textfeld)
- Test: `tests/Web/AdminPageTest.php`

**Interfaces:**
- Consumes: `VhostLayout::confFile()` (Task 1)
- Produces: `AdminPage::__construct(VhostRepository, CommandRunner, Config, VhostLayout, array &$session)` – **vierter Parameter neu, Session bleibt letzter**; `AdminPage::snippet(Vhost $vhost): string` liest `conf/custom.conf` (leer, wenn nicht vorhanden oder nicht lesbar); `commandFor('conf', ['name' => …, 'snippet' => …])` → `['args' => ['conf', $name], 'stdin' => $text]`.

- [ ] **Step 1: Failing tests schreiben**

In `tests/Web/AdminPageTest.php` den Aufbau um das Layout erweitern und ergänzen:
```php
	public function testCommandForConfPassesTextOnStdin(): void
	{
		self::assertSame(
			['args' => ['conf', 'a.de'], 'stdin' => "expires 1d;\n"],
			AdminPage::commandFor('conf', ['name' => 'a.de', 'snippet' => "expires 1d;\n"])
		);
		self::assertSame(
			['args' => ['conf', 'a.de'], 'stdin' => ''],
			AdminPage::commandFor('conf', ['name' => 'a.de', 'snippet' => ''])
		);
	}

	public function testConfRedirectsToDetailPage(): void
	{
		self::assertSame('/?v=a.de', AdminPage::redirectTarget('conf', 0, ['name' => 'a.de']));
		self::assertSame('/?v=a.de', AdminPage::redirectTarget('conf', 1, ['name' => 'a.de']));
	}

	public function testSnippetReadsFileOrEmpty(): void
	{
		$v = $this->repo->insert('a.de', VhostKind::Domain, null, null, true);
		self::assertSame('', $this->page->snippet($v));
		mkdir($this->dir . '/www/a.de/conf', 0750, true);
		file_put_contents($this->dir . '/www/a.de/conf/custom.conf', "expires 1d;\n");
		self::assertSame("expires 1d;\n", $this->page->snippet($v));
	}
```
Die `Config::fromArray([...])` dieses Tests braucht dafür `'wwwRoot' => $this->dir . '/www'`; lege `mkdir($this->dir . '/www');` in `setUp()` an.

Wichtig: Der bestehende Test `testCommandForMapsEveryAction` muss weiterhin `null` für unbekannte Aktionen liefern – lass ihn unverändert und ergänze nur die neuen Fälle.

- [ ] **Step 2: Tests ausführen – müssen fehlschlagen**

Run: `phpunit tests/Web`
Erwartet: FAIL – `commandFor('conf', …)` liefert `null`, `snippet()` fehlt.

- [ ] **Step 3: `AdminPage` erweitern**

Konstruktor um `private readonly VhostLayout $layout` vor `array &$session` erweitern (`use VhostAdmin\VhostLayout;`).

In `commandFor()` vor `default =>` ergänzen:
```php
			'conf' => ['args' => ['conf', $name], 'stdin' => (string)($post['snippet'] ?? '')],
```

Neue Methode (nach `letsEncryptEmail()`):
```php
	/**
	 * Aktuelles nginx-Snippet des vHosts für die Anzeige im Textfeld.
	 *
	 * Die Oberfläche liest die Datei nur; geschrieben wird sie ausschließlich vom
	 * root-CLI. Ist sie nicht lesbar, bleibt das Feld leer.
	 */
	public function snippet(Vhost $vhost): string
	{
		$file = $this->layout->confFile($vhost);
		return is_file($file) && is_readable($file) ? (string)file_get_contents($file) : '';
	}
```

- [ ] **Step 4: Template erweitern**

In `src/public/index.php` die Konstruktion anpassen:
```php
$layout = new VhostLayout($config);
$page = new AdminPage(new VhostRepository(new Database($config)), new CommandRunner($config), $config, $layout, $_SESSION);
```
(`use VhostAdmin\VhostLayout;` ergänzen.)

In der Detailansicht die Pfadangaben erweitern – in der `<dl>` nach der Docroot-Zeile:
```php
			<dt>Ordner</dt><dd><code><?= h($layout->webDir($view)) ?></code> (web), <code><?= h($layout->privateDir($view)) ?></code> (privat), <code><?= h($layout->logsDir($view)) ?></code> (Logs)</dd>
```

Nach der Karte „Let's Encrypt“ (bzw. bei localhost-Hosts nach der Typ-Karte) eine neue Karte einfügen:
```php
			<div class="card">
				<h2>Eigene nginx-Direktiven</h2>
				<p class="muted">Wird als <code><?= h($layout->confFile($view)) ?></code> gespeichert und in den server-Block eingebunden. Erlaubt sind unter anderem <code>client_max_body_size</code>, <code>expires</code>, <code>add_header</code>, <code>gzip*</code>, <code>rewrite</code>, <code>return</code>, <code>try_files</code>, <code>error_page</code>, <code>proxy_*</code> und <code>location</code>-Blöcke. Direktiven, die Docroot, Zertifikat oder Verzeichnisschutz betreffen, werden abgelehnt.</p>
				<form method="post">
					<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="conf"><input type="hidden" name="name" value="<?= h($view->name) ?>">
					<textarea name="snippet" rows="10" spellcheck="false"><?= h($page->snippet($view)) ?></textarea>
					<button class="primary">Übernehmen</button>
				</form>
				<p class="muted">Leeres Feld entfernt die Datei. Schlägt der nginx-Test fehl, bleibt die bisherige Fassung aktiv und die Meldung erscheint oben.</p>
			</div>
```

Im `<style>`-Block eine Regel für das Textfeld ergänzen (direkt nach der `input`-Regel):
```css
	textarea { width:100%; padding:.5rem .6rem; border:1px solid #cfd4da; border-radius:6px; font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace; resize:vertical; }
```

Prüfen: `php -l src/public/index.php`.

- [ ] **Step 5: Tests ausführen und committen**

```bash
phpunit
git add src/lib/VhostAdmin/Web/AdminPage.php src/public/index.php tests/Web/AdminPageTest.php
git commit -m "Oberfläche: Textfeld für eigene nginx-Direktiven"
```

---

### Task 9: Installer, logrotate und Smoke-Test

**Files:**
- Create: `src/etc/logrotate-vhost-admin`
- Modify: `src/etc/nginx-admin.conf`, `src/install.sh`, `debugging/smoke-test.sh`

**Interfaces:**
- Consumes: CLI-Befehl `migrate-layout` (Task 7)
- Produces: Installation, bei der die Oberfläche aus `/var/www/localhost-8080/web` ausgeliefert wird, `/etc/logrotate.d/vhost-admin` existiert und bestehende Hosts migriert sind.

- [ ] **Step 1: nginx-Konfiguration der Oberfläche anpassen**

In `src/etc/nginx-admin.conf` den Docroot eine Ebene tiefer legen:
```
    root /var/www/localhost-8080/web;
```
Der vorgeschaltete `default_server`-Block mit `return 444;` und `server_name localhost 127.0.0.1;` bleiben unverändert.

- [ ] **Step 2: logrotate-Vorlage anlegen**

`src/etc/logrotate-vhost-admin`:
```
# Logdateien der einzelnen vHosts (siehe vhost-admin).
# Rotation läuft als root; nginx öffnet die Dateien danach neu.
/var/www/*/logs/*.log {
	daily
	rotate 14
	missingok
	notifempty
	compress
	delaycompress
	create 0640 root root
	sharedscripts
	postrotate
		[ -f /run/nginx.pid ] && nginx -s reopen > /dev/null 2>&1 || true
	endscript
}
```
Hinweis: Die Gruppe in `create` wird vom Installer auf den gewählten Besitzer gesetzt (nächster Schritt), damit du die rotierten Dateien weiter lesen kannst.

- [ ] **Step 3: `src/install.sh` erweitern**

Den Abschnitt für die Oberfläche (bisher `install -m 644 "$SRC"/public/*.php /var/www/localhost-8080/`) ersetzen:
```bash
echo "== Oberfläche nach /var/www/localhost-8080/web"
install -d -m 2775 -o "$OWNER" -g www-data /var/www/localhost-8080 /var/www/localhost-8080/web
install -d -m 750 -o root -g www-data /var/www/localhost-8080/conf
install -d -m 750 -o root -g "$OWNER" /var/www/localhost-8080/cert /var/www/localhost-8080/logs
install -d -m 750 -o "$OWNER" -g "$OWNER" /var/www/localhost-8080/private
# Aus einer früheren Fassung liegt index.php eventuell noch flach im Basisordner.
if [ -f /var/www/localhost-8080/index.php ]; then
	mv /var/www/localhost-8080/index.php /var/www/localhost-8080/web/index.php
fi
install -m 644 -o "$OWNER" -g www-data "$SRC"/public/*.php /var/www/localhost-8080/web/
```

Nach dem certbot-Hook-Abschnitt einen neuen Abschnitt für logrotate einfügen:
```bash
echo "== logrotate"
sed "s/create 0640 root root/create 0640 root $OWNER/" "$SRC/etc/logrotate-vhost-admin" > /etc/logrotate.d/vhost-admin
chmod 644 /etc/logrotate.d/vhost-admin
logrotate -d /etc/logrotate.d/vhost-admin >/dev/null 2>&1 || { echo "logrotate-Konfiguration ist fehlerhaft" >&2; exit 1; }
```

Im Abschnitt „Datenbank und nginx-Configs“ nach `vhost init` und **vor** `vhost render` die Migration aufrufen:
```bash
/usr/local/sbin/vhost init
/usr/local/sbin/vhost migrate-layout
/usr/local/sbin/vhost render
```

Prüfen: `bash -n src/install.sh`.

- [ ] **Step 4: Installation ausführen und prüfen**

```bash
sudo -n ./install.sh
sudo -n nginx -t
sudo -n vhost list
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/
ls -la /var/www/mfsvr.de/
ls -la /var/www/localhost-8080/
ls -la /var/backups/ | grep vhost-admin-migration
```
Erwartet: `nginx -t` erfolgreich; die beiden produktiven Domains weiterhin gelistet, ihr Docroot jetzt unter `…/web/www/src`; Oberfläche liefert HTTP 200 aus `/var/www/localhost-8080/web`; in jedem Basisordner die fünf Unterordner mit den Rechten aus der Tabelle; ein Sicherungsarchiv unter `/var/backups/`.

Prüfe außerdem, dass die Inhalte der produktiven Hosts erhalten sind: `ls -la /var/www/mfsvr.de/web/www/src/` muss dieselben Dateien zeigen wie vor der Migration (Vergleich mit dem Sicherungsarchiv: `tar tzf /var/backups/vhost-admin-migration-*.tar.gz | grep mfsvr`).

Schlägt etwas fehl: **nichts im laufenden System von Hand reparieren**, sondern mit NEEDS_CONTEXT melden und die Ausgaben wörtlich mitliefern. Das Sicherungsarchiv erlaubt eine Rücknahme.

- [ ] **Step 5: Smoke-Test erweitern**

In `debugging/smoke-test.sh` nach dem Abschnitt „Oberfläche“ einen neuen Abschnitt ergänzen (die Variablen `JAR` und `CSRF` sind dort vorhanden):
```bash
echo "== Eigene Direktiven"
vhost add smoke-conf.example >/dev/null
vhost protect smoke-conf.example off >/dev/null
CSRF2=$(curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example' | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$CSRF2" ] || fail "kein CSRF-Token auf der Detailseite"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF2" -d 'action=conf&name=smoke-conf.example' --data-urlencode 'snippet=add_header X-Smoke-Test bestanden;' http://127.0.0.1:8080/
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   Snippet gespeichert" || fail "Snippet nicht gespeichert"
curl -s -D - -o /dev/null -H 'Host: smoke-conf.example' http://127.0.0.1/ | grep -qi 'X-Smoke-Test: bestanden' && echo "ok   Snippet wirkt" || fail "Header aus dem Snippet fehlt"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$CSRF2" -d 'action=conf&name=smoke-conf.example' --data-urlencode 'snippet=root /etc;' http://127.0.0.1:8080/
grep -q 'X-Smoke-Test' /var/www/smoke-conf.example/conf/custom.conf && echo "ok   verbotene Direktive abgelehnt, alte Fassung aktiv" || fail "verbotene Direktive hat das Snippet überschrieben"
curl -s -c "$JAR" -b "$JAR" 'http://127.0.0.1:8080/?v=smoke-conf.example' | grep -q 'flash err' && echo "ok   Fehlermeldung angezeigt" || fail "keine Fehlermeldung in der Oberfläche"
for sub in web conf cert private logs; do
	[ -d "/var/www/smoke-conf.example/$sub" ] || fail "Ordner $sub fehlt"
done
echo "ok   Struktur vollständig"
```
Die `cleanup()`-Funktion am Anfang des Skripts um den neuen Host erweitern:
```bash
	vhost remove smoke-conf.example --purge >/dev/null 2>&1
	rm -rf /var/www/smoke-conf.example
```

- [ ] **Step 6: Smoke-Test ausführen**

Run: `sudo -n ./debugging/smoke-test.sh`
Erwartet: `ALLE PRÜFUNGEN BESTANDEN`. Danach `sudo -n vhost list` – nur `mfsrv.de` und `mfsvr.de` dürfen übrig sein.

- [ ] **Step 7: Commit**

```bash
git add src/etc/nginx-admin.conf src/etc/logrotate-vhost-admin src/install.sh debugging/smoke-test.sh
git commit -m "Installer: Oberfläche unter web/, logrotate, Migration beim Update"
```

---

### Task 10: Dokumentation und Build

**Files:**
- Modify: `README.md`, `.claude/CLAUDE.md`, `FEATURES.md`, `MEMORY.md`, `OPTIMIZE.md`, `BUGS.md`, `dev-log/2026-09-18-dev.log` (neu anlegen), `research/OPTIMIZED_WORKER.md`

- [ ] **Step 1: `README.md` aktualisieren**

Im Abschnitt „Pfade“ die Tabelle ersetzen:

| Host | Struktur |
|---|---|
| `example.com` | `/var/www/example.com/` mit `web/` (Docroot, mit Unterordner `web/<unterordner>/`), `conf/`, `cert/`, `private/`, `logs/` |
| `localhost:3000` | `/var/www/localhost-3000/` mit denselben fünf Ordnern |

Dazu einen neuen Abschnitt „Eigene nginx-Direktiven“ mit: wo das Textfeld ist, was erlaubt ist, was bei einem Fehler passiert, wo die Datei liegt. Einen Abschnitt „Logdateien“ mit `[domain]/logs/access.log`, `error.log`, Rotation täglich/14 Tage. Die CLI-Liste um `conf`, `fix-permissions` und `migrate-layout` erweitern. Im Abschnitt „Umzug auf einen anderen Server“ ergänzen, dass `install.sh` bestehende Hosts automatisch migriert und vorher nach `/var/backups/` sichert.

- [ ] **Step 2: `.claude/CLAUDE.md` aktualisieren**

Unter „Struktur“ die neuen Klassen aufnehmen (`DirectorySpec`, `VhostLayout`, `Value\NginxSnippet`, `Migration\LayoutMigrator`) und ergänzen, dass `Vhost` keine Pfadmethoden mehr hat – Pfade kommen ausschließlich aus `VhostLayout`. Unter „Regeln“ zwei Punkte ergänzen:
- `www-data` darf ausschließlich in `web/` schreiben; `conf/`, `cert/`, `logs/` gehören root (Rechte-Tabelle in der Spec vom 2026-09-18).
- Das Snippet in `conf/custom.conf` wird nie von Hand gepflegt, sondern über die Oberfläche; `NginxSnippet` prüft gegen eine Positivliste inklusive Klammerbilanz.

Testanzahl auf den neuen Stand bringen (`phpunit` zeigt sie).

- [ ] **Step 3: `FEATURES.md`, `BUGS.md`, `OPTIMIZE.md`, `MEMORY.md`, dev-log**

- `FEATURES.md`: Den offenen Punkt zur Verzeichnisstruktur nach „Implementiert“ verschieben und um die tatsächlich gebauten Teile ergänzen (fünf Ordner, Snippet mit Positivliste, cert-Symlinks, Logs je vHost mit Rotation, `migrate-layout`, `fix-permissions`). Unter „Offen“ neu aufnehmen: Log-Anzeige in der Oberfläche, eigene Zertifikate in `cert/` (beides bewusst zurückgestellt).
- `BUGS.md`: nur ergänzen, falls bei der Umsetzung etwas gefunden wurde.
- `OPTIMIZE.md`: verbliebene Kleinbefunde belassen; ergänzen, dass `VhostService` mit dem Snippet-Teil weiter gewachsen ist und eine Aufteilung (Dateisystemoperationen in eine eigene Klasse) beim nächsten Refactoring lohnt.
- `MEMORY.md`: Abschnitt für 2026-09-18 mit den Entscheidungen des Nutzers (Struktur für alle Hosts, `--subdir` unter `web/`, `conf/` nur über die Oberfläche mit Positivliste, `cert/` nur Symlinks, Logs mit Leserecht für den Besitzer, automatische Migration mit Sicherung) und dem Hinweis, dass `conf/` für `www-data` lesbar sein muss, damit die Oberfläche das Feld füllen kann.
- `dev-log/2026-09-18-dev.log`: Einträge für Spec, Plan, die zehn Tasks, Installation, Smoke-Test und Build (Uhrzeiten mit `date '+%H:%M'`).
- `research/OPTIMIZED_WORKER.md`: Abschnitt „Was ich am 2026-09-18 gelernt habe“ mit: `conf/` braucht Leserecht für `www-data`; die Klammerbilanz ist die eigentliche Sicherheitsprüfung des Snippets; `realpath()` in Löschpfaden kann Symlinks zum Angriffsvektor machen (Regression vom Vortag).

- [ ] **Step 4: Build und Push (nur aus der Hauptsitzung)**

```bash
phpunit
./build.sh
```
Erwartet: Tests grün, `src/build.txt` auf 2, `build/vhost-admin.tar.gz` neu, Commit „Build 2“ auf `origin/main`.

- [ ] **Step 5: Abschließende Prüfung des laufenden Systems**

```bash
sudo -n nginx -t
sudo -n vhost list
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: mfsvr.de' http://127.0.0.1/
```
Erwartet: nginx in Ordnung, beide Domains gelistet, Oberfläche 200, `mfsvr.de` weiterhin mit 401 (Verzeichnisschutz aktiv) bzw. dem Status von vorher.

---

## Selbstprüfung des Plans

- **Spec-Abdeckung:** Abschnitt 3 (Verzeichnisse und Rechte) → Tasks 1, 4, 9; Abschnitt 4 (`VhostLayout`) → Task 1; Abschnitt 5 (`NginxSnippet`) → Task 2; Abschnitt 6 (Ablauf beim Speichern) → Tasks 5, 7, 8; Abschnitt 7 (Renderer, cert-Symlinks) → Tasks 3, 4; Abschnitt 8 (Migration) → Tasks 6, 7, 9; Abschnitt 9 (Logrotate) → Task 9; Abschnitt 10 (CLI-Befehle) → Task 7; Abschnitt 11 (Tests) → in jedem Task; Abschnitt 12 (Abgrenzung) → Task 10 (`FEATURES.md`).
- **Platzhalter:** keine offenen Stellen; die einzigen variablen Werte sind Uhrzeiten (`date '+%H:%M'`) und die Testanzahl, die `phpunit` liefert.
- **Typkonsistenz:** `VhostLayout` liefert überall `string`; `directories()` gibt `list<DirectorySpec>`; `DirectorySpec::$mode` ist `int` (oktal) und wird in Tests mit `sprintf('%04o', …)` verglichen. Konstruktor-Reihenfolgen sind durchgehend festgelegt: `ConfigRenderer(Config, VhostLayout)`; `VhostService(Config, VhostRepository, ConfigRenderer, ReloaderInterface, CertbotInterface, VhostLayout)`; `Cli\Application(VhostService, VhostRepository, Config, VhostLayout, LayoutMigrator, $stdin, $stdout, $stderr)`; `Web\AdminPage(VhostRepository, CommandRunner, Config, VhostLayout, &$session)`; `LayoutMigrator(Config, VhostRepository, VhostLayout, string $nginxLogDir = '/var/log/nginx')`. `NginxSnippet::fromString()` wirft `\InvalidArgumentException`, `VhostService::setSnippet()` wirft weiter, was der Reloader wirft (`\RuntimeException`). Neue Config-Eigenschaft `backupDir` (Standard `/var/backups`) wird in Task 7 eingeführt und in Task 9 vom Installer nicht überschrieben.
- **Reihenfolge-Abhängigkeit:** Task 3 lässt die Renderer-Erwartungen anderer Testdateien vorübergehend scheitern; deshalb steht dort ausdrücklich, nur die Konstruktion anzupassen, und Task 4 richtet die Erwartungen. Beide Tasks müssen in dieser Reihenfolge laufen.
