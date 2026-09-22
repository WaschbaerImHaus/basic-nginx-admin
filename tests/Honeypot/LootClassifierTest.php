<?php
declare(strict_types=1);

/**
 * Tests der Beutegruppen. Die Pfade stammen aus dem echten Log.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\LootClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LootClassifierTest extends TestCase
{
	public static function paths(): array
	{
		return [
			['/twilio.env', 'Zugangsdaten'],
			['/.env', 'Zugangsdaten'],
			['/wp-config.php.backup', 'Zugangsdaten'],
			['/.git/config', 'Zugangsdaten'],
			['/web.config', 'Konfiguration'],
			['/config.json', 'Konfiguration'],
			['/yarn.lock', 'Paketdateien'],
			['/test/phpinfo/', 'Entwicklungsreste'],
			['/tmp/', 'Entwicklungsreste'],
			['/phpmyadmin', 'Verwaltung'],
			['/admin/', 'Verwaltung'],
			['/dump.sql', 'Sicherungen'],
			['/impressum.html', null],
		];
	}

	#[DataProvider('paths')]
	public function testAssignsThePathToItsGroup(string $path, ?string $expected): void
	{
		self::assertSame($expected, (new LootClassifier())->classify($path));
	}

	/**
	 * /wp-config.php.backup passt auf „Zugangsdaten" und auf „Sicherungen". Die erste
	 * Gruppe gewinnt, sonst hinge die Aussage an der Reihenfolge im Pfad.
	 */
	public function testTheFirstMatchingGroupWinsSoTheResultIsStable(): void
	{
		$classifier = new LootClassifier();
		self::assertSame('Zugangsdaten', $classifier->classify('/wp-config.php.backup'));
		self::assertSame(array_key_first(LootClassifier::GROUPS), 'Zugangsdaten');
	}
}
