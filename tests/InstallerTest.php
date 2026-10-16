<?php
declare(strict_types=1);

/**
 * Nach dem Umzug des Honigtopfs (2026-10-15) darf der Installer der Verwaltung ihn weder
 * wiederbeleben noch seine Daten anfassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 09:09
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

	/** Anfang des Abräumblocks: nur wenn das Projekt honeypot übernommen hat. */
	private const CLEANUP_START = 'if [ -f /etc/default/honeypot ]; then';
	/** Anfang der Abbruchprüfung: alter Timer aktiv, honeypot noch nicht installiert. */
	private const GUARD_START = 'if systemctl is-enabled --quiet vhost-admin-honeypot.timer';

	/**
	 * Schneidet einen if-Block heraus: von $start bis zum zugehörigen "fi" am Zeilenanfang.
	 * Die Blöcke im Installer enthalten kein weiteres if, das erste "fi" ist also ihres.
	 */
	private static function block(string $text, string $start): string
	{
		$from = strpos($text, $start);
		self::assertNotFalse($from, "Block fehlt: $start");
		$to = strpos($text, "\nfi\n", $from);
		self::assertNotFalse($to, "Block ohne fi: $start");
		return substr($text, $from, $to + 4 - $from);
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

	public function testAbortsBeforeReplacingTheLibraryWhileTheOldHoneypotStillRuns(): void
	{
		$text = self::installer();
		$guard = self::block($text, self::GUARD_START);
		self::assertStringContainsString('[ ! -f /etc/default/honeypot ]', $guard);
		self::assertStringContainsString('VHOST_ADMIN_SKIP_HONEYPOT_CHECK', $guard);
		self::assertStringContainsString('exit 1', $guard);
		// Die Prüfung muss greifen, bevor die alten Klassen unter $APP/lib verschwinden.
		$libRemoval = strpos($text, 'rm -rf "$APP/lib"');
		self::assertNotFalse($libRemoval);
		self::assertLessThan($libRemoval, strpos($text, self::GUARD_START));
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
