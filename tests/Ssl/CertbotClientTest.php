<?php
declare(strict_types=1);

/**
 * Tests des certbot-Aufrufs – geprüft wird die Kommandozeile, nicht der Aufruf selbst.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 16:10
 */

namespace Tests\Ssl;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Ssl\CertbotClient;

final class CertbotClientTest extends TestCase
{
	private CertbotClient $client;

	protected function setUp(): void
	{
		$this->client = new CertbotClient();
	}

	public function testEveryNameGetsItsOwnDdOption(): void
	{
		$command = $this->client->command('um.example', '/var/www/um.example/web', 'a@b.de', ['www.um.example']);
		self::assertStringContainsString("-d 'um.example' -d 'www.um.example'", $command);
	}

	/**
	 * Ohne --cert-name legt certbot bei geänderter Namensliste eine zweite Reihe
	 * "um.example-0001" an. Der Renderer zeigt aber fest auf live/um.example – nginx
	 * bekäme das alte Zertifikat, und die Erweiterung wäre wirkungslos.
	 */
	public function testCommandPinsTheCertificateNameToTheMainDomain(): void
	{
		$command = $this->client->command('um.example', '/var/www/um.example/web', 'a@b.de', ['www.um.example']);
		self::assertStringContainsString("--cert-name 'um.example'", $command);
	}

	/**
	 * Nicht-interaktiv verweigert certbot das Hinzufügen eines Namens zu einer
	 * bestehenden Reihe ("Please specify --expand"). Ohne diese Option liefe der
	 * Nachtrag des Nebennamens ins Leere.
	 */
	public function testCommandAllowsExpandingAnExistingCertificate(): void
	{
		$command = $this->client->command('um.example', '/var/www/um.example/web', 'a@b.de', ['www.um.example']);
		self::assertStringContainsString('--expand', $command);
	}

	public function testCommandStaysNonInteractiveAndKeepsValidCertificates(): void
	{
		$command = $this->client->command('um.example', '/var/www/um.example/web', 'a@b.de', []);
		self::assertStringContainsString(' -n ', $command);
		self::assertStringContainsString('--keep-until-expiring', $command);
	}

	/**
	 * Alle Werte kommen aus der Oberfläche bzw. der Datenbank; ein Name mit
	 * Sonderzeichen darf keine zweite Anweisung öffnen.
	 */
	public function testAllValuesAreQuoted(): void
	{
		$command = $this->client->command("a'b.example", '/var/www/x y', 'a@b.de', []);
		self::assertStringNotContainsString("-d a'b.example ", $command);
		self::assertStringContainsString("'/var/www/x y'", $command);
	}
}
