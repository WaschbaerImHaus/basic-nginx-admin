# Honigtopf auslagern – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Honigtopf (Auswertung, Ansicht bienchen.mfsvr.de, Honigtopf-Seite mit Ködern, Netztabelle) wird ein eigenes Projekt `~/honeypot` mit eigenem Repo, Build, Installer und Timern; vhost-admin bleibt reine Verwaltung.

**Architecture:** `git filter-repo` zieht die Honigtopf-Pfade samt Historie aus einer Kopie von vhost-admin und benennt sie in die Struktur der globalen CLAUDE.md um (alles unter `src/`). Der neue Installer übernimmt den Betrieb (Units, Einstellungen, Netztabelle) idempotent; vhost-admin verliert alle Honigtopf-Teile und räumt seine alten Units ab. Die einzige Verbindung bleibt das CLI `vhost`.

**Tech Stack:** PHP 8.5, SQLite, PHPUnit 13 (Ubuntu-Paket), bash, systemd, nginx, git-filter-repo (Ubuntu-Paket, installiert).

**Spec:** `docs/superpowers/specs/2026-10-15-honigtopf-auslagern-design.md` (in vhost-admin)

## Global Constraints

- Kommentare, DocBlocks, Meldungen: Deutsch mit echten Umlauten; Bezeichner englisch; Autor „Kurt Ingwer“; DocBlock mit `@version Letzte Änderung: <JJJJ-MM-TT HH:MM>` bei jeder geänderten PHP-Datei.
- Einrückung am Zeilenanfang nur mit Tabulatoren (nginx-Texte im Code: 4 Leerzeichen, wie bisher).
- Repo honeypot: `git@bitbucket.org:von-null/honeypot.git`; Repo vhost-admin: GitHub wie bisher.
- Subagenten pushen nie, führen `build.sh` nie aus und benutzen kein `sudo` (außer wo ein Schritt es ausdrücklich verlangt – in diesem Plan nirgends für Subagenten).
- Daten unter `/var/www/*/private/honeypot*` werden nie gelöscht oder verschoben.
- Pfade nach der Trennung (Spec 3): `/opt/honeypot`, `/etc/default/honeypot`, `/var/lib/honeypot/networks.txt`, `/var/lib/honeypot/research`, `/etc/nginx/conf.d/honeypot-decoy.conf`, Units `honeypot-analyse.{service,timer}` (stündlich :20) und `honeypot-networks.{service,timer}` (So 04:40, ±1 h).
- Der Honigtopf darf nichts aus `VhostAdmin\` benutzen; vhost-admin nur über das CLI `vhost`.

## Review Focus

1. **Laufender Betrieb während der Umstellung** – die Ansicht zeigt nach der Installation des Honigtopfs dieselben Tage; keine Stunde fällt aus (Task 4: Zählung der Tage vorher/nachher, Timer-Liste).
2. **Doppelte Auswertung** – alte und neue Timer dürfen nicht beide laufen (zwei Schreiber auf `honeypot.sqlite`): der neue Installer schaltet die alten Units ab (Test in Task 2: Installer-Text enthält `disable --now vhost-admin-honeypot.timer`; Prüfung in Task 4).
3. **Ansicht findet nach dem Umzug ihre Klassen nicht** – `install-dashboard.sh` sucht Klassen/Vorlagen im Projekt (`src/…`) und in der Installation (`/opt/honeypot/…`); beide Pfade werden in Task 2 geprüft.
4. **Köder-Konfiguration doppelt** – `vhost-admin-decoy.conf` und `honeypot-decoy.conf` gleichzeitig ließen nginx wegen doppelter `map`/`log_format` scheitern: `install-site.sh` entfernt die alte Datei, bevor es die neue schreibt (Task 2).
5. **vhost-admin-Installer belebt den Honigtopf wieder oder löscht Daten** – nach Task 3 enthält `src/install.sh` keinen Aufruf von `install-dashboard.sh` mehr und löscht nichts unter `/var/www` (Test in Task 3).

---

### Task 1: Neues Projekt mit Historie und grünen Tests

**Files (alle in `~/honeypot`):**
- Create (filter-repo): siehe Umbenennungstabelle unten
- Create: `src/bootstrap.php`, `src/build.txt`, `phpunit.xml`, `build.sh`, `.gitignore`, `claude.sh` (Kopie `~/claude.sh`), `tests/Support/TempDir.php`, `tests/Support/FakeDns.php` (Kopien aus vhost-admin, Namespace bleibt `Tests\Support`)
- Modify: Pfadverweise in `tests/Honeypot/DecoysTest.php`, `SitePageTest.php`, `SiteQualityTest.php`, `src/bin/analyse.php`, `src/bin/update-networks.php`, `src/bin/make-decoys.php`, `src/lib/Honeypot/Decoys.php`, `src/public/dashboard/index.php`, `src/public/dashboard/bootstrap.php`, `debugging/meadow-check.sh`

**Interfaces:**
- Produces: Projekt `~/honeypot` auf Branch `main`, `origin` = Bitbucket (nicht gepusht), `phpunit` grün; Struktur wie in der Tabelle; `src/bootstrap.php` lädt nur `Honeypot\` aus `src/lib/Honeypot/` und `Tests\` aus `tests/`.

- [ ] **Step 1: Kopie filtern** (Arbeitsordner im Scratchpad, nicht in `~/vhost-admin` selbst)

```bash
S=/tmp/claude-1000/honeypot-split; rm -rf "$S"; git clone -q ~/vhost-admin "$S" && cd "$S"
git filter-repo --force \
	--path src/lib/Honeypot/ --path src/public/honeypot/ --path honeypot/ --path tests/Honeypot/ \
	--path research/honeypot/ --path debugging/meadow-check.sh \
	--path docs/specs/2026-09-22-honeypot-dashboard.md \
	--path docs/superpowers/specs/2026-10-15-honigtopf-annehmen-design.md \
	--path docs/superpowers/specs/2026-10-15-honigtopf-auslagern-design.md \
	--path src/etc/vhost-admin-honeypot.service --path src/etc/vhost-admin-honeypot.timer \
	--path src/etc/vhost-admin-networks.service --path src/etc/vhost-admin-networks.timer \
	--path-rename src/public/honeypot/:src/public/dashboard/ \
	--path-rename honeypot/site/:src/site/ \
	--path-rename honeypot/install-dashboard.sh:src/install-dashboard.sh \
	--path-rename honeypot/install-site.sh:src/install-site.sh \
	--path-rename honeypot/:src/bin/ \
	--path-rename research/honeypot/:research/ \
	--path-rename docs/specs/:docs/ \
	--path-rename src/etc/vhost-admin-honeypot.service:src/etc/honeypot-analyse.service \
	--path-rename src/etc/vhost-admin-honeypot.timer:src/etc/honeypot-analyse.timer \
	--path-rename src/etc/vhost-admin-networks.service:src/etc/honeypot-networks.service \
	--path-rename src/etc/vhost-admin-networks.timer:src/etc/honeypot-networks.timer
git ls-files | sed 's|/[^/]*$||' | sort -u
```

Expected: nur `debugging`, `docs`, `docs/superpowers/specs`, `research…`, `src/bin`, `src/etc`, `src/lib/Honeypot`, `src/public/dashboard`, `src/site…`, `tests/Honeypot`; **kein** `honeypot/`, kein `src/public/honeypot`. Falls filter-repo eine Umbenennung nicht greift (Reihenfolge), die Liste prüfen und den Befehl mit angepasster Reihenfolge wiederholen. `git log --oneline | wc -l` > 1 (Historie da).

- [ ] **Step 2: Nach `~/honeypot` holen**

```bash
[ -e ~/honeypot ] && { echo "~/honeypot existiert schon – abbrechen und melden"; exit 1; }
git clone -q "$S" ~/honeypot && cd ~/honeypot
git remote remove origin; git remote add origin git@bitbucket.org:von-null/honeypot.git
git config core.sshCommand "ssh -i ~/.ssh/bitbucket -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
git branch -M main
```

- [ ] **Step 3: Test zuerst – `tests/BootstrapTest.php`**

```php
<?php
declare(strict_types=1);

/**
 * Prüft das Gerüst des eigenständigen Honigtopf-Projekts: Autoloader und Buildnummer.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: <JJJJ-MM-TT HH:MM>
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
	public function testLoadsHoneypotClasses(): void
	{
		self::assertTrue(class_exists(\Honeypot\Period::class));
	}

	public function testDoesNotKnowTheAdministration(): void
	{
		self::assertFalse(class_exists('VhostAdmin\\Config'), 'Der Honigtopf darf die Verwaltung nicht laden');
	}

	public function testBuildNumberIsANumber(): void
	{
		self::assertMatchesRegularExpression('/^\d+$/', trim((string)file_get_contents(dirname(__DIR__) . '/src/build.txt')));
	}
}
```

Run: `phpunit` → FAIL (kein phpunit.xml / Autoloader).

- [ ] **Step 4: Gerüst**

`src/bootstrap.php`:
```php
<?php
declare(strict_types=1);

/**
 * Autoloader des Honigtopfs: Honeypot\Foo liegt in lib/Honeypot/Foo.php, Tests\Foo in
 * tests/Foo.php (nur im Projektverzeichnis vorhanden). Der Honigtopf kennt die
 * Verwaltung (vhost-admin) nur über deren Kommandozeile `vhost`, nie über ihre Klassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: <JJJJ-MM-TT HH:MM>
 */

spl_autoload_register(static function (string $class): void {
	$roots = ['Honeypot\\' => __DIR__ . '/lib/Honeypot/', 'Tests\\' => dirname(__DIR__) . '/tests/'];
	foreach ($roots as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) {
				require $file;
			}
			return;
		}
	}
});
```

`src/build.txt`: `0`

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="src/bootstrap.php" colors="true" failOnWarning="true" failOnRisky="true" cacheDirectory=".phpunit.cache">
	<testsuites>
		<testsuite name="honeypot">
			<directory>tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`build.sh` (ausführbar):
```bash
#!/usr/bin/env bash
# Build: Tests → Buildnummer +1 → build/honeypot.tar.gz → Commit „Build N“ → Push.
# Aufruf: ./build.sh [--no-push]   (--no-push: nur bauen und committen)
# Autor: Kurt Ingwer
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
PUSH=1
[ "${1:-}" = "--no-push" ] && PUSH=0
echo "== Tests"; phpunit
BUILD=$(( $(cat src/build.txt) + 1 )); echo "$BUILD" > src/build.txt; echo "== Build $BUILD"
mkdir -p build; rm -f build/honeypot.tar.gz
tar --transform 's,^,honeypot/,' -czf build/honeypot.tar.gz src docs README.md
git add -A
git commit -q -m "Build $BUILD" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" || echo "nichts zu committen"
if [ "$PUSH" = 1 ]; then git push -q origin HEAD:main; fi
echo "Fertig: Build $BUILD"
```

`.gitignore`:
```
build/*.tar.gz
.phpunit.cache/
.phpunit.result.cache
.superpowers/
```
plus `build/.gitkeep`, leere Ordner mit `.gitkeep`: `claude-generated/ dev-log/`. `claude.sh` aus `~/claude.sh` kopieren (Rechte erhalten). `tests/Support/TempDir.php` und `tests/Support/FakeDns.php` unverändert aus `~/vhost-admin/tests/Support/` kopieren.

- [ ] **Step 5: Pfadverweise umstellen** (Projekt: `src/…`; Installation `/opt/honeypot` = Inhalt von `src/`, also `bin/`, `lib/`, `public/dashboard/`, `site/`)

| Datei | alt | neu |
|---|---|---|
| `tests/Honeypot/DecoysTest.php` (2×), `SitePageTest.php`, `SiteQualityTest.php` | `dirname(__DIR__, 2) . '/honeypot/site/…'` | `dirname(__DIR__, 2) . '/src/site/…'` |
| `src/bin/analyse.php`, `src/bin/update-networks.php` | `$bootstrap = dirname(__DIR__) . '/src/bootstrap.php'; require is_file($bootstrap) ? $bootstrap : dirname(__DIR__) . '/bootstrap.php';` | `require dirname(__DIR__) . '/bootstrap.php';` (Projekt: `src/bin/../bootstrap.php`, Installation: `/opt/honeypot/bin/../bootstrap.php`) |
| `src/bin/analyse.php` | `NETWORK_TABLE = '/var/lib/vhost-admin/networks.txt'` | `'/var/lib/honeypot/networks.txt'` |
| `src/bin/analyse.php` | `REPORT_DIR = __DIR__ . '/../research/honeypot'` | `'/var/lib/honeypot/research'` |
| `src/bin/update-networks.php` | `DEFAULT_OUT = '/var/lib/vhost-admin/networks.txt'`, Text „vhost-admin-networks.timer“ | `/var/lib/honeypot/networks.txt`, „honeypot-networks.timer“ |
| `src/bin/*.php` Aufruf-/Hinweistexte | `php honeypot/analyse.php`, `honeypot/update-networks.php`, `honeypot/make-decoys.php` | `php src/bin/analyse.php` usw. (im Text der Installation: `/opt/honeypot/bin/…`) |
| `src/lib/Honeypot/Decoys.php` (BEGIN, httpConfig-Kopf) | `honeypot/install-site.sh` | `honeypot: src/install-site.sh` – **Achtung:** `Decoys::BEGIN` ist der Marker im Snippet bestehender Hosts. `merge()` muss den alten Marker weiterhin erkennen und durch den neuen ersetzen (Test: Snippet mit altem Abschnitt → nach `merge` genau ein Abschnitt mit neuem Marker). |
| `src/public/dashboard/index.php` | `sudo php /opt/vhost-admin/honeypot/analyse.php` | `sudo php /opt/honeypot/bin/analyse.php` |
| `src/public/dashboard/bootstrap.php`, Kommentare | `/opt/vhost-admin` | `/opt/honeypot` |
| `debugging/meadow-check.sh` | `…/../honeypot/site` | `…/../src/site` |

Für `Decoys::merge` den Test **zuerst** in `tests/Honeypot/DecoysTest.php` ergänzen:

```php
	/**
	 * Hosts aus der Zeit vor dem Umzug tragen den alten Marker. Nach dem Zusammenführen
	 * darf es nur noch einen Köderabschnitt geben – zwei hätten doppelte map-Einträge.
	 */
	public function testMergeReplacesTheSectionWithTheOldMarker(): void
	{
		$old = "# eigene Zeile\n"
			. "# <honigtopf-koeder> verwaltet von honeypot/install-site.sh - nicht von Hand aendern\n"
			. "set \$alt 1;\n"
			. "# </honigtopf-koeder>\n";
		$merged = Decoys::merge($old, Decoys::serverSnippet('/var/www/x/logs'));
		self::assertSame(1, substr_count($merged, '<honigtopf-koeder>'));
		self::assertStringContainsString(Decoys::BEGIN, $merged);
		self::assertStringNotContainsString('set $alt 1;', $merged);
		self::assertStringContainsString('# eigene Zeile', $merged);
	}
```

(Prüfe vorher in `Decoys::merge`, wie der Abschnitt erkannt wird – erkennt es ihn schon am Präfix `# <honigtopf-koeder>` statt an der ganzen BEGIN-Zeile, ist der Test sofort grün und nur der Text ändert sich; sonst die Erkennung auf das Präfix umstellen.)

- [ ] **Step 6: Tests** – `phpunit` → alles grün, keine Warnungen. `grep -rn 'vhost-admin' src tests | grep -v 'CLI\|vhost conf\|vhost show\|vhost fix-permissions'` → nur Stellen, die bewusst auf das CLI `vhost` oder auf vhost-admin als Herkunft verweisen; im Bericht auflisten.

- [ ] **Step 7: Commit** – `git add -A && git commit -m "Eigenständiges Projekt: Struktur, Autoloader, Pfade"` (mit Co-Authored-By-Zeile). Nicht pushen.

---

### Task 2: Installer, Units, Übernahme, Smoke-Test, Doku (honeypot)

**Files (in `~/honeypot`):**
- Create: `src/install.sh`, `debugging/smoke-test.sh`, `tests/InstallScriptsTest.php`, Doku (`README.md`, `BUGS.md`, `FEATURES.md`, `OPTIMIZE.md`, `MEMORY.md`, `SECURITY_RISKS.md`, `SECURITY_FIXED.md`, `.claude/CLAUDE.md`, `dev-log/<heute>-dev.log`, `research/OPTIMIZED_WORKER.md`)
- Modify: `src/install-dashboard.sh`, `src/install-site.sh`, `src/etc/honeypot-analyse.{service,timer}`, `src/etc/honeypot-networks.{service,timer}`

**Interfaces:**
- Consumes: Struktur aus Task 1.
- Produces: `sudo ./src/install.sh` installiert nach `/opt/honeypot` (Kopie von `src/`), übernimmt den alten Betrieb und richtet Ansicht und Seite ein; `sudo ./debugging/smoke-test.sh` prüft die Ansicht Ende-zu-Ende.

- [ ] **Step 1: Test zuerst – `tests/InstallScriptsTest.php`** (prüft die Skripte als Text; ausführen geht nur als root)

```php
<?php
declare(strict_types=1);

/**
 * Prüft Installer und Units als Text: Sie laufen nur als root und lassen sich hier nicht
 * ausführen. Geprüft wird, was beim Umzug aus vhost-admin schiefgehen könnte.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: <JJJJ-MM-TT HH:MM>
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

final class InstallScriptsTest extends TestCase
{
	private static function read(string $path): string
	{
		return (string)file_get_contents(dirname(__DIR__) . '/' . $path);
	}

	public function testInstallerTurnsTheOldTimersOff(): void
	{
		$install = self::read('src/install.sh');
		self::assertStringContainsString('disable --now vhost-admin-honeypot.timer', $install, 'sonst schreiben zwei Auswertungen in dieselbe Datenbank');
		self::assertStringContainsString('disable --now vhost-admin-networks.timer', $install);
	}

	public function testInstallerTakesOverSettingsAndTableOnlyOnce(): void
	{
		$install = self::read('src/install.sh');
		self::assertMatchesRegularExpression('#\[ -f /etc/default/honeypot \] \|\|#', $install);
		self::assertStringContainsString('/var/lib/vhost-admin/networks.txt', $install);
		self::assertStringContainsString('/var/lib/honeypot', $install);
	}

	public function testInstallerNeverDeletesData(): void
	{
		self::assertDoesNotMatchRegularExpression('#rm\s+-[a-z]*r[a-z]*\s+[^\n]*/var/www#', self::read('src/install.sh'));
	}

	public function testUnitsPointToTheNewInstallation(): void
	{
		foreach (['honeypot-analyse.service', 'honeypot-networks.service'] as $unit) {
			$text = self::read('src/etc/' . $unit);
			self::assertStringContainsString('/opt/honeypot/bin/', $text, $unit);
			self::assertStringNotContainsString('/opt/vhost-admin', $text, $unit);
		}
		self::assertStringContainsString('EnvironmentFile=-/etc/default/honeypot', self::read('src/etc/honeypot-analyse.service'));
		self::assertStringContainsString('OnCalendar=*-*-* *:20:00', self::read('src/etc/honeypot-analyse.timer'));
	}

	public function testSiteInstallerRemovesTheOldDecoyConfig(): void
	{
		$site = self::read('src/install-site.sh');
		self::assertStringContainsString('rm -f /etc/nginx/conf.d/vhost-admin-decoy.conf', $site, 'doppelte map/log_format ließen nginx scheitern');
		self::assertStringContainsString('/etc/nginx/conf.d/honeypot-decoy.conf', $site);
	}

	public function testDashboardInstallerFindsClassesInProjectAndInstallation(): void
	{
		$dash = self::read('src/install-dashboard.sh');
		self::assertStringContainsString('/lib/Honeypot', $dash);
		self::assertStringContainsString('/public/dashboard', $dash);
		self::assertStringContainsString('/etc/default/honeypot', $dash);
		self::assertStringNotContainsString('/etc/default/vhost-admin-honeypot', $dash);
	}
}
```

Run: `phpunit tests/InstallScriptsTest.php` → FAIL.

- [ ] **Step 2: Units**

`src/etc/honeypot-analyse.service`:
```ini
[Unit]
Description=honeypot: Honigtopf-Logs stündlich auswerten
Documentation=file:///opt/honeypot/docs/2026-09-22-honeypot-dashboard.md

[Service]
Type=oneshot
# Die Hosts stehen in der Umgebungsdatei, damit der Dienst nicht angefasst werden muss,
# wenn ein Honigtopf dazukommt oder wegfällt.
EnvironmentFile=-/etc/default/honeypot
ExecStart=/bin/sh -c '/usr/bin/php /opt/honeypot/bin/analyse.php ${HONEYPOT_DASHBOARD:+--dashboard=$HONEYPOT_DASHBOARD} ${HONEYPOT_HOSTS}'
ProtectSystem=full
# Die Ansicht bekommt ihre Tagesberichte nach /var/www/<ansichtshost>/private/honeypot,
# die Tagesnotizen liegen unter /var/lib/honeypot/research.
ReadWritePaths=/var/lib/honeypot /var/www
```
(Die übrigen Zeilen der bisherigen Datei – z. B. Nice/IO-Klasse, falls vorhanden – unverändert übernehmen.)

`src/etc/honeypot-analyse.timer`: wie bisher (`OnCalendar=*-*-* *:20:00`, `AccuracySec=1min`, `Persistent=true`), Description „honeypot: …“.

`src/etc/honeypot-networks.service`:
```ini
[Unit]
Description=honeypot: Netz- und Ländertabelle der Registries auffrischen
Documentation=file:///opt/honeypot/docs/2026-09-22-honeypot-dashboard.md

[Service]
Type=oneshot
ExecStart=/usr/bin/php /opt/honeypot/bin/update-networks.php
ProtectSystem=full
PrivateTmp=true
ReadWritePaths=/var/lib/honeypot
```
`src/etc/honeypot-networks.timer`: wie bisher (`Sun 04:40`, `RandomizedDelaySec=1h`, `Persistent=true`).

- [ ] **Step 3: `src/install.sh`** (ausführbar)

```bash
#!/usr/bin/env bash
# Installiert den Honigtopf nach /opt/honeypot und übernimmt beim ersten Lauf den
# Betrieb aus vhost-admin (Einstellungen, Netztabelle, Tagesnotizen, Timer).
# Mehrfach ausführbar (Update). Daten unter /var/www bleiben unangetastet.
# Abhängigkeit: das CLI "vhost" aus vhost-admin (eigene Direktiven, Rechte, Schutz).
# Aufruf: sudo ./src/install.sh
# Autor: Kurt Ingwer
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Bitte mit sudo aufrufen." >&2; exit 1; }
command -v vhost >/dev/null || { echo "vhost-admin (CLI vhost) fehlt." >&2; exit 1; }

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP=/opt/honeypot

echo "== Programm nach $APP"
rm -rf "$APP.neu"
install -d -m 755 "$APP.neu"
cp -r "$SRC/bin" "$SRC/lib" "$SRC/public" "$SRC/site" "$SRC/etc" "$APP.neu/"
install -m 644 "$SRC/bootstrap.php" "$APP.neu/bootstrap.php"
install -m 755 "$SRC/install-dashboard.sh" "$SRC/install-site.sh" "$APP.neu/"
[ -d "$SRC/../docs" ] && cp -r "$SRC/../docs" "$APP.neu/docs"
chown -R root:root "$APP.neu"
rm -rf "$APP.alt"; [ -d "$APP" ] && mv "$APP" "$APP.alt"
mv "$APP.neu" "$APP"; rm -rf "$APP.alt"

echo "== Übernahme aus vhost-admin (nur beim ersten Mal)"
install -d -m 755 /var/lib/honeypot /var/lib/honeypot/research
if [ ! -f /etc/default/honeypot ]; then
	if [ -f /etc/default/vhost-admin-honeypot ]; then
		cp -p /etc/default/vhost-admin-honeypot /etc/default/honeypot
	else
		printf 'HONEYPOT_HOSTS=""\nHONEYPOT_DASHBOARD=""\n' > /etc/default/honeypot
	fi
	chmod 644 /etc/default/honeypot
fi
[ -f /var/lib/honeypot/networks.txt ] || { [ -f /var/lib/vhost-admin/networks.txt ] && cp -p /var/lib/vhost-admin/networks.txt /var/lib/honeypot/networks.txt; } || true
if [ -d /opt/vhost-admin/research/honeypot ] && [ -z "$(ls -A /var/lib/honeypot/research)" ]; then
	cp -rp /opt/vhost-admin/research/honeypot/. /var/lib/honeypot/research/
fi

echo "== Timer"
# Erst die alten aus, dann die neuen an: zwei Auswertungen schrieben sonst in dieselbe Datenbank.
for old in vhost-admin-honeypot.timer vhost-admin-networks.timer; do
	systemctl disable --now "$old" >/dev/null 2>&1 || true
done
for unit in honeypot-analyse.service honeypot-analyse.timer honeypot-networks.service honeypot-networks.timer; do
	install -m 644 "$SRC/etc/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now honeypot-analyse.timer honeypot-networks.timer >/dev/null
# Ein geänderter Zeitplan gilt erst nach einem Neustart des Timers.
systemctl restart honeypot-analyse.timer

echo "== Ansicht und Seite"
# shellcheck disable=SC1091
. /etc/default/honeypot
if [ -n "${HONEYPOT_DASHBOARD:-}" ] && [ -n "${HONEYPOT_HOSTS:-}" ]; then
	# shellcheck disable=SC2086
	"$APP/install-dashboard.sh" "$HONEYPOT_DASHBOARD" $HONEYPOT_HOSTS >/dev/null \
		&& echo "Ansicht aufgefrischt: $HONEYPOT_DASHBOARD" || echo "Hinweis: Ansicht konnte nicht aufgefrischt werden." >&2
	for host in $HONEYPOT_HOSTS; do
		"$APP/install-site.sh" "$host" >/dev/null \
			&& echo "Honigtopf-Seite aufgefrischt: $host" || echo "Hinweis: Seite $host konnte nicht aufgefrischt werden." >&2
	done
fi
echo "Fertig. Zeitpläne: systemctl list-timers 'honeypot-*'"
```

- [ ] **Step 4: `src/install-dashboard.sh` und `src/install-site.sh` anpassen**
  - Aufruftexte: `sudo ./src/install-dashboard.sh …`, `sudo ./src/install-site.sh …`.
  - `ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"` (das Skript liegt jetzt in `src/` bzw. `/opt/honeypot/`).
  - Klassen/Vorlagen: `LIB="$ROOT/lib/Honeypot"`, `PAGE="$ROOT/public/dashboard"` (gilt im Projekt `src/…` und in `/opt/honeypot/…` gleich – der bisherige Zweig „src/ oder flach“ entfällt).
  - `install-dashboard.sh`: schreibt `/etc/default/honeypot` statt `/etc/default/vhost-admin-honeypot` (gleicher Inhalt, Kommentar „Geschrieben von src/install-dashboard.sh“); Aufruf der Auswertung `php "$ROOT/bin/analyse.php" …`; Kommentare `/opt/vhost-admin` → `/opt/honeypot`.
  - `install-site.sh`: `SRC="$ROOT/site"`, `LIB="$ROOT/lib"` (Decoys wird als `$LIB/Honeypot/Decoys.php` geladen – Aufruf in `decoys()` unverändert); vor dem Schreiben der neuen Datei `rm -f /etc/nginx/conf.d/vhost-admin-decoy.conf`; neue Datei `/etc/nginx/conf.d/honeypot-decoy.conf` (auch im Fehlerzweig).
  - `bash -n` auf beide und auf `src/install.sh`.

- [ ] **Step 5: `debugging/smoke-test.sh`** – den Honigtopf-Teil aus `~/vhost-admin/debugging/smoke-test.sh` (Zeilen ab „# Die Honigtopf-Ansicht selbst“ bis „ok   Teilvorlagen nicht direkt abrufbar“) übernehmen, mit eigenem Test-vHost:

```bash
#!/usr/bin/env bash
# Ende-zu-Ende-Prüfung der Honigtopf-Ansicht über nginx und php-fpm. Legt einen eigenen
# Test-vHost an (über das CLI von vhost-admin) und räumt ihn wieder ab.
# Aufruf: sudo ./debugging/smoke-test.sh
# Autor: Kurt Ingwer
set -euo pipefail
PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST=smoke-honeypot.example
HP=/var/www/$HOST
fail() { echo "FEHLER: $*" >&2; exit 1; }
cleanup() { set +e; vhost remove "$HOST" --purge >/dev/null 2>&1; rm -rf "$HP"; rm -f /etc/php/*/fpm/pool.d/vhost-$HOST.conf; set -e; }
trap cleanup EXIT
cleanup
vhost add "$HOST" >/dev/null
vhost protect "$HOST" off >/dev/null
vhost php "$HOST" on >/dev/null
vhost fix-permissions "$HOST" >/dev/null
LIBSRC=/opt/honeypot/lib/Honeypot; [ -d "$PROJECT/src/lib/Honeypot" ] && LIBSRC="$PROJECT/src/lib/Honeypot"
PAGESRC=/opt/honeypot/public/dashboard; [ -d "$PROJECT/src/public/dashboard" ] && PAGESRC="$PROJECT/src/public/dashboard"
# … ab hier der übernommene Block (PUSER/PGROUP/WUSER/WGROUP, Klassen kopieren, JSON-Bericht,
# Import in SQLite, Seiten nach $HP/web/hp, alle curl-Prüfungen) – mit -H "Host: $HOST".
echo "Smoke-Test Honigtopf: alles ok"
```
  Den übernommenen Block wörtlich einsetzen (Host-Header auf `$HOST`), nichts weglassen. `bash -n debugging/smoke-test.sh`.

- [ ] **Step 6: Doku**
  - `README.md`: Abschnitte „Honigtopf“, „Honigtopf-Ansicht“, „Echte Client-Adressen durch den WireGuard-Tunnel“ und der Honigtopf-Absatz aus „Verzeichnisschutz: Bereich je Benutzer“ aus `~/vhost-admin/README.md` übernehmen und auf die neuen Pfade/Befehle umschreiben; dazu „Installation“ (`sudo ./src/install.sh`, Abhängigkeit vhost-admin), „Entwicklung“ (`phpunit`, `./build.sh`), „Umzug aus vhost-admin“ (was der Installer übernimmt).
  - `.claude/CLAUDE.md`: alle Honigtopf-Regeln aus `~/vhost-admin/.claude/CLAUDE.md` (Abschnitt „Honigtopf (Ansicht unter einem eigenen vHost)“ und die Honigtopf-Zeilen aus „Befehle“/„Struktur“/„Regeln“) mit neuen Pfaden; dazu: „Der Honigtopf benutzt vhost-admin nur über das CLI `vhost`; `Honeypot\` lädt nie `VhostAdmin\`“, „`ROTATIONS` in `src/bin/analyse.php` muss zu `rotate` in vhost-admins `src/etc/logrotate-vhost-admin` passen“, „Repo: Bitbucket von-null/honeypot“, „PHP-Projekt: gepusht werden nur vollständige Builds (`build.sh`)“, „Honigtopf soll wachsen (Nutzervorgabe 2026-09-27)“.
  - `FEATURES.md`: Honigtopf-Einträge aus vhost-admin (implementiert und offen); `BUGS.md`: Honigtopf-Bugs aus vhost-admin; `SECURITY_RISKS.md`: Honigtopf-Eintrag aus vhost-admin; `OPTIMIZE.md`, `MEMORY.md`, `SECURITY_FIXED.md`: Überschrift + Stand; `research/OPTIMIZED_WORKER.md`: Kurzfassung des Projektwissens; `dev-log/<heute>-dev.log`: Eintrag mit Datum und Uhrzeit.

- [ ] **Step 7: Tests** – `phpunit` grün; `bash -n` auf alle `.sh`; `systemd-analyze verify src/etc/honeypot-*.service` (Pfadwarnungen zu `/opt/honeypot`, das es noch nicht gibt, im Bericht nennen).

- [ ] **Step 8: Commit** – „Installer, Units, Übernahme aus vhost-admin, Smoke-Test, Doku“.

---

### Task 3: vhost-admin bereinigen

**Files (in `~/vhost-admin`, Branch `feature/honigtopf-auslagern`):**
- Delete: `src/lib/Honeypot/`, `src/public/honeypot/`, `honeypot/`, `tests/Honeypot/`, `debugging/meadow-check.sh`, `src/etc/vhost-admin-honeypot.{service,timer}`, `src/etc/vhost-admin-networks.{service,timer}`, `docs/specs/2026-09-22-honeypot-dashboard.md`, `research/honeypot/`, `docs/superpowers/specs/2026-10-15-honigtopf-annehmen-design.md`
- Modify: `src/bootstrap.php`, `src/install.sh`, `debugging/smoke-test.sh`, `build.sh`, `README.md`, `FEATURES.md`, `BUGS.md`, `SECURITY_RISKS.md`, `.claude/CLAUDE.md`, `dev-log/<heute>-dev.log`
- Test: `tests/InstallerTest.php` (neu, oder bestehenden Installer-Test erweitern, falls vorhanden)

**Interfaces:**
- Consumes: nichts aus Task 1–2 (unabhängig).
- Produces: vhost-admin ohne Honigtopf; Installer räumt alte Honigtopf-Reste unter `/opt/vhost-admin` und alte Units ab.

- [ ] **Step 1: Branch** – `git switch -c feature/honigtopf-auslagern` (von `main`).
- [ ] **Step 2: Test zuerst – `tests/InstallerTest.php`**

```php
<?php
declare(strict_types=1);

/**
 * Nach dem Umzug des Honigtopfs (2026-10-15) darf der Installer der Verwaltung ihn weder
 * wiederbeleben noch seine Daten anfassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: <JJJJ-MM-TT HH:MM>
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
	private static function installer(): string
	{
		return (string)file_get_contents(dirname(__DIR__) . '/src/install.sh');
	}

	public function testNoLongerInstallsTheHoneypot(): void
	{
		$text = self::installer();
		self::assertStringNotContainsString('install-dashboard.sh', $text);
		self::assertStringNotContainsString('analyse.php', $text);
		self::assertStringNotContainsString('enable --now vhost-admin-honeypot', $text);
	}

	public function testRemovesTheOldUnitsOnlyWhenTheHoneypotProjectTookOver(): void
	{
		$text = self::installer();
		self::assertStringContainsString('/etc/systemd/system/vhost-admin-honeypot.timer', $text);
		self::assertMatchesRegularExpression('#\[ -f /etc/default/honeypot \]#', $text, 'erst abräumen, wenn honeypot installiert ist');
	}

	public function testNeverTouchesHoneypotData(): void
	{
		self::assertDoesNotMatchRegularExpression('#rm\s+-[a-z]*r[a-z]*\s+[^\n]*/var/www#', self::installer());
	}

	public function testHoneypotClassesAreGone(): void
	{
		self::assertDirectoryDoesNotExist(dirname(__DIR__) . '/src/lib/Honeypot');
		self::assertFalse(class_exists('Honeypot\\Period'));
	}
}
```

Run: `phpunit tests/InstallerTest.php` → FAIL.

- [ ] **Step 3: Löschen** – `git rm -r` der Delete-Liste.
- [ ] **Step 4: `src/bootstrap.php`** – zweiten Autoloader (`Honeypot\`) entfernen; DocBlock: „Autoloader für `VhostAdmin\`. Der Honigtopf ist seit 2026-10-15 ein eigenes Projekt (Bitbucket von-null/honeypot) und benutzt die Verwaltung nur über das CLI `vhost`.“
- [ ] **Step 5: `src/install.sh`**
  - Entfernen: `cp -r "$SRC/public/honeypot" …` samt Kommentar; der ganze Block „Taegliche Auswertung der Honigtopf-Logs …“ (Units, `/etc/default/vhost-admin-honeypot`, `analyse.php`, `update-networks.php`, `install-*.sh`, `site`); `vhost-admin-networks.*`-Installation und `enable`; `systemctl enable --now vhost-admin-honeypot.timer`, `restart vhost-admin-honeypot.timer`, `enable --now vhost-admin-networks.timer`; der Block „Honigtopf-Ansicht auffrischen“.
  - Ergänzen (nach `systemctl daemon-reload` bzw. an dessen Stelle):

```bash
# Der Honigtopf ist seit 2026-10-15 ein eigenes Projekt (/opt/honeypot). Seine alten
# Reste hier werden erst abgeräumt, wenn er übernommen hat (/etc/default/honeypot gibt
# es dann) – sonst stünde die Auswertung still. Daten unter /var/www bleiben unberührt.
if [ -f /etc/default/honeypot ]; then
	for old in vhost-admin-honeypot vhost-admin-networks; do
		systemctl disable --now "$old.timer" >/dev/null 2>&1 || true
		rm -f "/etc/systemd/system/$old.timer" "/etc/systemd/system/$old.service"
	done
	rm -rf "$APP/honeypot" "$APP/public/honeypot" "$APP/research/honeypot"
	rm -f /etc/default/vhost-admin-honeypot
	systemctl daemon-reload
fi
```
- [ ] **Step 6: `debugging/smoke-test.sh`** – Honigtopf-Block (ab „# Die Honigtopf-Ansicht selbst“ bis „ok   Teilvorlagen nicht direkt abrufbar“) und `PROJECT`-Kommentar „für die Vorlagen der Honigtopf-Ansicht“ entfernen; die Prüfungen „private/ ist für den Pool lesbar / bleibt schreibgeschützt“ **bleiben** (Verwaltungsfunktion), Kommentar ohne Honigtopf-Bezug umformulieren („Anwendungen wie eine Auswertungsansicht lesen dort ihre Daten“).
- [ ] **Step 7: `build.sh`** – `tar … src honeypot docs install.sh README.md` → `tar … src docs install.sh README.md`.
- [ ] **Step 8: Doku**
  - `README.md`: Abschnitte „Honigtopf“, „Honigtopf-Ansicht“, „Echte Client-Adressen durch den WireGuard-Tunnel“ entfernen; an ihrer Stelle ein kurzer Abschnitt „Honigtopf“: „eigenes Projekt `git@bitbucket.org:von-null/honeypot.git` (seit 2026-10-15); benutzt vhost-admin über das CLI `vhost`.“ Honigtopf-Erwähnungen in anderen Abschnitten (z. B. Verzeichnisschutz) auf allgemeine Formulierung bringen oder mit Verweis versehen.
  - `.claude/CLAUDE.md`: Abschnitt „Honigtopf (Ansicht unter einem eigenen vHost)“, die Honigtopf-Befehle/-Struktur entfernen; Regel 1 lautet nur noch „Verwaltung: keine neuen Features ohne ausdrücklichen Wunsch“ + „Der Honigtopf ist ein eigenes Projekt (~/honeypot, Bitbucket); hier keine Sonderregeln für ihn“. Teststand-Zahl aktualisieren.
  - `FEATURES.md`/`BUGS.md`/`SECURITY_RISKS.md`: Honigtopf-Einträge entfernen (sie stehen jetzt im neuen Repo), Hinweiszeile auf das neue Repo.
  - `dev-log/<heute>-dev.log`: Eintrag.
- [ ] **Step 9: Tests** – `phpunit` grün (ohne Honigtopf-Tests); `bash -n src/install.sh debugging/smoke-test.sh build.sh`; `grep -rn -i 'honeypot\|honigtopf' src tests debugging build.sh` → nur der Abräumblock im Installer und Verweise auf das neue Projekt (im Bericht auflisten).
- [ ] **Step 10: Commit** – „Honigtopf ausgelagert (eigenes Projekt honeypot)“. Nicht pushen, nicht mergen.

---

### Task 4: Inbetriebnahme (nur Hauptsitzung, nicht als Subagent)

- [ ] **Step 1: Stand vorher festhalten** – Zahl der Tage: `sudo php -r '$p=new PDO("sqlite:/var/www/bienchen.mfsvr.de/private/honeypot/honeypot.sqlite");echo $p->query("select count(*) from days")->fetchColumn();'`; `systemctl list-timers 'vhost-admin-*'`.
- [ ] **Step 2: honeypot installieren** – `cd ~/honeypot && sudo ./src/install.sh`; dann `systemctl list-timers 'honeypot-*' 'vhost-admin-*'` (alte aus, neue an), `sudo systemctl start honeypot-analyse.service`, `journalctl -u honeypot-analyse -n 20`, Tage zählen (≥ vorher), Ansicht: `curl -s --resolve bienchen.mfsvr.de:443:127.0.0.1 https://bienchen.mfsvr.de/ -o /dev/null -w '%{http_code}'` (401 wegen Schutz ist korrekt; Inhalt per Bildschirmfoto im kopflosen Chrome mit Zugangsdaten nur, wenn der Nutzer sie nennt – sonst Prüfung über PHP-CLI im Pool-Kontext entfällt), Köder: `curl -s --resolve mfsvr.de:443:127.0.0.1 https://mfsvr.de/.env | head -3` (erfundener Inhalt mit Kennung), `ls /etc/nginx/conf.d/` (nur `honeypot-decoy.conf`), `sudo ./debugging/smoke-test.sh`.
- [ ] **Step 3: honeypot Build 1** – `./build.sh` (Tests, Build 1, Push nach Bitbucket; erster Push `main`).
- [ ] **Step 4: vhost-admin mergen, installieren, Build 30** – `git switch main && git merge --ff-only feature/honigtopf-auslagern` (sonst `--no-ff`), `sudo ./install.sh`, `sudo ./debugging/smoke-test.sh`, `systemctl list-timers 'vhost-admin-*'` (keine honeypot/networks mehr), `ls /opt/vhost-admin` (kein `honeypot/`), dann `./build.sh` (Build 30, Push GitHub).
- [ ] **Step 5: Memory/Projektwissen** – Memory `project_vhost_admin.md` und neu `project_honeypot.md` aktualisieren; im honeypot-Repo `MEMORY.md`.
