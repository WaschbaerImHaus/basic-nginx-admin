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
