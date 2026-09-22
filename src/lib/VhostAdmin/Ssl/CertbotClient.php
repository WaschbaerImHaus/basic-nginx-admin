<?php
declare(strict_types=1);

/**
 * certbot im Webroot-Modus aufrufen.
 *
 * Nicht-interaktiv; bestehende, noch gültige Zertifikate bleiben erhalten
 * (--keep-until-expiring). Die Verlängerung übernimmt der certbot-Timer.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 16:10
 */

namespace VhostAdmin\Ssl;

final class CertbotClient implements CertbotInterface
{
	/**
	 * Ruft certbot im Webroot-Modus nicht-interaktiv auf und liefert dessen Ausgabe.
	 *
	 * @param string $domain  Domain, für die das Zertifikat gilt
	 * @param string $webroot Verzeichnis, unter dem certbot .well-known/acme-challenge/ ablegt
	 * @param string $email   Registrierungsadresse bei Let's Encrypt
	 * @param list<string> $alsoFor weitere Namen, die ins selbe Zertifikat gehören
	 * @return string Ausgabe des Werkzeugs (für die Anzeige in der Oberfläche)
	 * @throws \RuntimeException wenn kein Zertifikat ausgestellt wurde
	 */
	public function obtain(string $domain, string $webroot, string $email, array $alsoFor = []): string
	{
		exec($this->command($domain, $webroot, $email, $alsoFor), $output, $code);
		$text = implode("\n", $output);
		if ($code !== 0) {
			throw new \RuntimeException("certbot fehlgeschlagen:\n$text");
		}
		return $text;
	}

	/**
	 * Baut die Kommandozeile. Eigene Methode, damit sie ohne certbot prüfbar ist.
	 *
	 * @param list<string> $alsoFor weitere Namen, die ins selbe Zertifikat gehören
	 */
	public function command(string $domain, string $webroot, string $email, array $alsoFor): string
	{
		// Jeder Name braucht ein eigenes "-d". Wird auf www.<domain> umgeleitet, muss
		// auch dieser Name im Zertifikat stehen: Wer ihn über HTTPS aufruft, bekommt
		// sonst einen Zertifikatsfehler, bevor die Umleitung überhaupt greift.
		$names = '';
		foreach (array_merge([$domain], $alsoFor) as $name) {
			$names .= ' -d ' . escapeshellarg($name);
		}
		// --cert-name bindet die Zertifikatsreihe fest an die Hauptdomain. Ohne diese
		// Angabe legt certbot bei geänderter Namensliste eine zweite Reihe
		// "<domain>-0001" an; nginx zeigt aber fest auf live/<domain> und bekäme
		// weiterhin das alte Zertifikat.
		// --expand erlaubt, einen Namen zu einer bestehenden Reihe hinzuzufügen. Ohne
		// die Option bricht certbot nicht-interaktiv mit "Please specify --expand" ab.
		return sprintf(
			'certbot certonly --webroot -w %s%s --cert-name %s -n --agree-tos --no-eff-email'
			. ' -m %s --keep-until-expiring --expand 2>&1',
			escapeshellarg($webroot),
			$names,
			escapeshellarg($domain),
			escapeshellarg($email)
		);
	}
}
