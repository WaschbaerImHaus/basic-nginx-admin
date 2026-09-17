<?php
declare(strict_types=1);

/**
 * Prüft die nginx-Konfiguration und lädt sie neu.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Nginx;

interface ReloaderInterface
{
	/**
	 * @throws \RuntimeException wenn die Konfiguration fehlerhaft ist oder der Reload scheitert
	 */
	public function reload(): void;
}
