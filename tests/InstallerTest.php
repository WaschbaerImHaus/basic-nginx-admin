<?php
declare(strict_types=1);

/**
 * Nach dem Umzug des Honigtopfs (2026-10-15) darf der Installer der Verwaltung ihn weder
 * wiederbeleben noch seine Daten anfassen. Er räumt die alten Reste erst ab, wenn das Projekt
 * honeypot vollständig übernommen hat, und bricht ab, solange die Übernahme fehlt oder hängt.
 *
 * Der Installer läuft nur als root. Geprüft wird deshalb der Text; die Bedingungen und die
 * Warteschleife werden zusätzlich herausgeschnitten und mit einem nachgebildeten systemctl
 * in bash ausgeführt – so wird echtes Verhalten geprüft, ohne etwas am System zu ändern.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 09:21
 */

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class InstallerTest extends TestCase
{
	/** Anfang des Abräumblocks: nur wenn das Projekt honeypot vollständig übernommen hat. */
	private const CLEANUP_START = 'if [ -f /etc/default/honeypot ] && systemctl is-enabled --quiet honeypot-analyse.timer';
	/** Anfang der Abbruchprüfung: alter Timer aktiv oder Übernahme durch honeypot unvollständig. */
	private const GUARD_START = 'if [ "${VHOST_ADMIN_SKIP_HONEYPOT_CHECK:-}" != 1 ] && {';
	/** Anfang der Warteschleife für die alten oneshot-Dienste. */
	private const WAIT_START = 'wait_until_finished() {';

	/** Temporäres Verzeichnis für die nachgebildete Datei /etc/default/honeypot. */
	private string $dir = '';

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	private static function installer(): string
	{
		return (string)file_get_contents(dirname(__DIR__) . '/src/install.sh');
	}

	/**
	 * Schneidet einen Block heraus: von $start bis zum zugehörigen Ende ($end) am Zeilenanfang.
	 * Die Blöcke im Installer enthalten kein weiteres if, das erste "fi" ist also ihres.
	 */
	private static function block(string $text, string $start, string $end = "\nfi\n"): string
	{
		$from = strpos($text, $start);
		self::assertNotFalse($from, "Block fehlt: $start");
		$to = strpos($text, $end, $from);
		self::assertNotFalse($to, "Block ohne Ende: $start");
		return substr($text, $from, $to + strlen($end) - $from);
	}

	/** Die Bedingung eines if-Blocks: alles zwischen "if " und dem ersten "; then". */
	private static function condition(string $block): string
	{
		$then = strpos($block, "; then\n");
		self::assertNotFalse($then, 'if ohne "; then"');
		return substr($block, 3, $then - 3);
	}

	/**
	 * Führt ein bash-Skript aus und gibt stdout und stderr zurück. Die Umgebung wird
	 * vollständig vorgegeben, damit nichts aus der Testumgebung hineinwirkt.
	 *
	 * @param array<string,string> $env
	 * @return array{0:string,1:string}
	 */
	private static function bash(string $script, array $env = []): array
	{
		$process = proc_open(
			['bash', '-c', $script],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			['PATH' => (string)getenv('PATH')] + $env
		);
		self::assertIsResource($process);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), "bash scheiterte: $err");
		return [$out, $err];
	}

	/**
	 * Wertet eine Bedingung des Installers mit nachgebildetem systemctl aus.
	 * /etc/default/honeypot wird durch eine Datei im Temp-Verzeichnis ersetzt.
	 *
	 * @return bool true, wenn die Bedingung zutrifft (der Block also liefe)
	 */
	private function evaluate(string $condition, bool $oldTimer, bool $newTimer, bool $defaults, bool $skip): bool
	{
		$file = $this->dir . '/honeypot';
		if ($defaults) {
			touch($file);
		}
		$condition = str_replace('/etc/default/honeypot', $file, $condition);
		// is-enabled liefert 0 für "aktiv", 1 für "nicht aktiv" – je nach Unit-Name.
		$script = 'set -euo pipefail' . "\n"
			. 'systemctl() { [ "$1" = is-enabled ] || return 1; local u; for u in "$@"; do case "$u" in '
			. 'vhost-admin-honeypot.timer) return ' . ($oldTimer ? 0 : 1) . ';; '
			. 'honeypot-analyse.timer) return ' . ($newTimer ? 0 : 1) . ';; esac; done; return 1; }' . "\n"
			. 'if ' . $condition . '; then echo ja; else echo nein; fi' . "\n";
		[$out] = self::bash($script, $skip ? ['VHOST_ADMIN_SKIP_HONEYPOT_CHECK' => '1'] : []);
		return trim($out) === 'ja';
	}

	public function testNoLongerInstallsTheHoneypot(): void
	{
		$text = self::installer();
		self::assertStringNotContainsString('install-dashboard.sh', $text);
		self::assertStringNotContainsString('analyse.php', $text);
		self::assertStringNotContainsString('enable --now vhost-admin-honeypot', $text);
	}

	public function testRemovesTheOldUnitsOnlyInsideTheTakeoverBlock(): void
	{
		$text = self::installer();
		$cleanup = self::block($text, self::CLEANUP_START);
		// Im Block: Timer und Dienste anhalten, Unit-Dateien, Reste und alte Einstellungen löschen.
		self::assertStringContainsString('for old in vhost-admin-honeypot vhost-admin-networks; do', $cleanup);
		self::assertStringContainsString('systemctl disable --now "$old.timer"', $cleanup);
		self::assertStringContainsString('systemctl stop "$old.service"', $cleanup);
		self::assertStringContainsString('rm -f "/etc/systemd/system/$old.timer" "/etc/systemd/system/$old.service"', $cleanup);
		self::assertStringContainsString('rm -rf "$APP/honeypot" "$APP/public/honeypot" "$APP/research/honeypot"', $cleanup);
		self::assertStringContainsString('rm -f /etc/default/vhost-admin-honeypot', $cleanup);
		// Ausserhalb des Blocks (und der Abbruchprüfung) darf nichts den alten Honigtopf anfassen.
		$rest = str_replace([$cleanup, self::block($text, self::GUARD_START)], '', $text);
		foreach (['vhost-admin-honeypot', 'vhost-admin-networks', '$APP/honeypot'] as $needle) {
			self::assertStringNotContainsString($needle, $rest, "$needle steht ausserhalb des Abräumblocks");
		}
	}

	public function testAbortsBeforeReplacingTheLibrary(): void
	{
		$text = self::installer();
		$guard = self::block($text, self::GUARD_START);
		self::assertStringContainsString('exit 1', $guard);
		// Der Ausweg in der Meldung: sudo setzt die Umgebung zurück, die Variable muss HINTER sudo stehen.
		self::assertStringContainsString('sudo VHOST_ADMIN_SKIP_HONEYPOT_CHECK=1 ./install.sh', $guard);
		// Die Prüfung muss greifen, bevor die alten Klassen unter $APP/lib verschwinden.
		$libRemoval = strpos($text, 'rm -rf "$APP/lib"');
		self::assertNotFalse($libRemoval);
		self::assertLessThan($libRemoval, strpos($text, self::GUARD_START));
	}

	/**
	 * Abbruch, wenn der alte Timer aktiv ist ODER /etc/default/honeypot existiert, der neue
	 * Timer aber nicht aktiv ist (honeypot-Installer nach dem Anlegen der Datei abgebrochen).
	 * VHOST_ADMIN_SKIP_HONEYPOT_CHECK=1 übergeht beides.
	 *
	 * @return array<string,array{bool,bool,bool,bool,bool}> alt, neu, Datei, Übergehen → Abbruch
	 */
	public static function guardCases(): array
	{
		return [
			'alter Timer läuft, honeypot fehlt'                  => [true,  false, false, false, true],
			'alter Timer läuft trotz neuem Timer und Datei'       => [true,  true,  true,  false, true],
			'Übernahme abgebrochen: Datei da, neuer Timer aus'    => [false, false, true,  false, true],
			'Übernahme vollständig'                               => [false, true,  true,  false, false],
			'Neuinstallation ohne Honigtopf'                      => [false, false, false, false, false],
			'alter Timer läuft, bewusst übergangen'               => [true,  false, false, true,  false],
			'Übernahme abgebrochen, bewusst übergangen'           => [false, false, true,  true,  false],
		];
	}

	#[DataProvider('guardCases')]
	public function testGuardAbortsWhileTheTakeoverIsIncomplete(bool $old, bool $new, bool $defaults, bool $skip, bool $aborts): void
	{
		$condition = self::condition(self::block(self::installer(), self::GUARD_START));
		self::assertSame($aborts, $this->evaluate($condition, $old, $new, $defaults, $skip));
	}

	/**
	 * Abgeräumt wird nur, wenn /etc/default/honeypot existiert UND honeypot-analyse.timer
	 * aktiv ist – sonst gingen Units und Tagesnotizen nach einem abgebrochenen honeypot-Lauf
	 * verloren. Das Übergehen der Prüfung ändert daran nichts.
	 *
	 * @return array<string,array{bool,bool,bool,bool}> neu, Datei, Übergehen → Abräumen
	 */
	public static function cleanupCases(): array
	{
		return [
			'Übernahme vollständig'                  => [true,  true,  false, true],
			'Übernahme abgebrochen'                  => [false, true,  false, false],
			'Übernahme abgebrochen, übergangen'      => [false, true,  true,  false],
			'Datei fehlt trotz neuem Timer'          => [true,  false, false, false],
			'Neuinstallation ohne Honigtopf'         => [false, false, false, false],
		];
	}

	#[DataProvider('cleanupCases')]
	public function testCleansUpOnlyAfterACompleteTakeover(bool $new, bool $defaults, bool $skip, bool $cleans): void
	{
		$condition = self::condition(self::block(self::installer(), self::CLEANUP_START));
		self::assertSame($cleans, $this->evaluate($condition, false, $new, $defaults, $skip));
	}

	/**
	 * Ein laufender oneshot-Dienst darf nicht per stop (SIGTERM) abgebrochen werden: Erst den
	 * Timer aus, dann warten, bis der Dienst fertig ist, dann stop (nur noch Notbremse) und rm.
	 */
	public function testWaitsForTheOldServicesBeforeStoppingAndRemoving(): void
	{
		$cleanup = self::block(self::installer(), self::CLEANUP_START);
		$disable = strpos($cleanup, 'systemctl disable --now "$old.timer"');
		$wait = strpos($cleanup, 'wait_until_finished "$old.service"');
		$stop = strpos($cleanup, 'systemctl stop "$old.service"');
		$remove = strpos($cleanup, 'rm -f "/etc/systemd/system/$old.timer"');
		self::assertNotFalse($wait, 'Warteschleife fehlt im Abräumblock');
		self::assertLessThan($wait, $disable, 'erst den Timer aus, sonst startet er den Dienst erneut');
		self::assertLessThan($stop, $wait);
		self::assertLessThan($remove, $wait);
		// Die Funktion muss vor ihrem Aufruf definiert sein.
		self::assertLessThan(strpos(self::installer(), self::CLEANUP_START), strpos(self::installer(), self::WAIT_START));
	}

	/**
	 * Verhalten der Warteschleife mit nachgebildetem systemctl und sleep. Ein oneshot-Dienst
	 * steht während seines Laufs auf "activating" – systemctl is-active meldet dann NICHT
	 * aktiv; deshalb fragt die Schleife ActiveState ab.
	 *
	 * @return array<string,array{string,int,int}> Zustand, so viele Sekunden lang, erwartete Wartezeit
	 */
	public static function waitCases(): array
	{
		return [
			'oneshot läuft 3 s (activating)'   => ['activating', 3, 3],
			'Dienst fährt herunter'            => ['deactivating', 2, 2],
			'Dienst läuft (active)'            => ['active', 4, 4],
			'läuft nicht'                      => ['inactive', 5, 0],
			'gescheitert, läuft nicht'         => ['failed', 5, 0],
			'hängt: Obergrenze 120 s'          => ['activating', 100000, 120],
		];
	}

	#[DataProvider('waitCases')]
	public function testWaitLoopWaitsWhileTheServiceRunsAndGivesUpAfter120Seconds(string $state, int $running, int $expected): void
	{
		$function = self::block(self::installer(), self::WAIT_START, "\n}\n");
		// sleep zählt nur mit; systemctl meldet $STATE, solange weniger als $RUNNING Sekunden "vergangen" sind.
		$script = 'set -euo pipefail' . "\n"
			. 'slept=0' . "\n"
			. 'sleep() { slept=$((slept + 1)); }' . "\n"
			. 'systemctl() { [ "$*" = "show -p ActiveState --value x.service" ] || { echo "falscher Aufruf: $*" >&2; return 1; }; '
			. 'if [ "$slept" -lt "$RUNNING" ]; then echo "$STATE"; else echo inactive; fi; }' . "\n"
			. $function
			. 'wait_until_finished x.service' . "\n"
			. 'echo "$slept"' . "\n";
		[$out, $err] = self::bash($script, ['STATE' => $state, 'RUNNING' => (string)$running]);
		self::assertSame((string)$expected, trim($out));
		self::assertStringNotContainsString('falscher Aufruf', $err);
		if ($expected === 120) {
			self::assertStringContainsString('läuft nach 120 s noch', $err);
		} else {
			self::assertSame('', $err);
		}
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
