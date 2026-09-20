<?php
declare(strict_types=1);

/**
 * Anlegen und Prüfen der Systembenutzer, unter denen PHP je vHost läuft.
 *
 * Eigene Schnittstelle, damit die Tests ohne root laufen (Projektkonvention wie bei
 * Reload und certbot).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:35
 */

namespace VhostAdmin\Php;

interface SystemUsersInterface
{
	public function exists(string $name): bool;

	/**
	 * Legt einen Systembenutzer ohne Anmeldemöglichkeit an.
	 *
	 * @throws \RuntimeException wenn das Anlegen scheitert
	 */
	public function create(string $name, string $homeDir): void;
}
