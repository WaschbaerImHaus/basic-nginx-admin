<?php
declare(strict_types=1);

/**
 * Nach dem Umzug des Honigtopfs (2026-10-15) darf der Installer der Verwaltung ihn weder
 * wiederbeleben noch seine Daten anfassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 09:03
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
