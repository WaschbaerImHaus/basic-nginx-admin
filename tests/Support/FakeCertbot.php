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
	/** @var list<list<string>> weitere Namen je Aufruf */
	public array $alsoFor = [];

	/**
	 * @param string $liveDir Verzeichnis, unter dem die simulierten Zertifikate abgelegt werden
	 */
	public function __construct(private readonly string $liveDir)
	{
	}

	/**
	 * Merkt sich den Aufruf und legt bei Erfolg fullchain.pem/privkey.pem im Live-Verzeichnis an.
	 *
	 * @param string $domain  Domain, für die das Zertifikat gilt
	 * @param string $webroot Verzeichnis, unter dem certbot .well-known/acme-challenge/ ablegt
	 * @param string $email   Registrierungsadresse bei Let's Encrypt
	 * @return string Ausgabe des simulierten Werkzeugs
	 * @throws \RuntimeException wenn $succeed auf false gesetzt ist
	 */
	public function obtain(string $domain, string $webroot, string $email, array $alsoFor = []): string
	{
		$this->calls[] = [$domain, $webroot, $email];
		$this->alsoFor[] = $alsoFor;
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
