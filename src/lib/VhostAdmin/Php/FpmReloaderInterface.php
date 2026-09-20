<?php
declare(strict_types=1);

/**
 * Prüft die php-fpm-Konfiguration und lädt sie neu.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:05
 */

namespace VhostAdmin\Php;

interface FpmReloaderInterface
{
	/**
	 * @throws \RuntimeException wenn die Konfiguration fehlerhaft ist oder der Reload scheitert
	 */
	public function reload(): void;
}
