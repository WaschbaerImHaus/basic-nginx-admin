<?php
declare(strict_types=1);

/**
 * Systembenutzer über useradd anlegen.
 *
 * Die Benutzer sind reine Dienstkonten: kein Passwort, keine Shell, kein Heimatordner
 * (als Heimatordner dient der Basisordner des vHosts, angelegt wird er hier nicht).
 * Damit kann sich niemand mit diesem Konto anmelden, PHP läuft aber unter einer
 * eigenen Kennung und kommt nicht an die Dateien anderer Hosts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:35
 */

namespace VhostAdmin\Php;

final class SystemUsers implements SystemUsersInterface
{
	public function exists(string $name): bool
	{
		return function_exists('posix_getpwnam') ? posix_getpwnam($name) !== false : false;
	}

	/**
	 * @throws \RuntimeException wenn useradd fehlschlägt
	 */
	public function create(string $name, string $homeDir): void
	{
		$command = 'useradd --system --no-create-home'
			. ' --home-dir ' . escapeshellarg($homeDir)
			. ' --shell /usr/sbin/nologin'
			// Eigene Gruppe je Benutzer: so ist "Gruppe des Hosts" eindeutig und
			// niemand landet versehentlich in einer gemeinsamen Sammelgruppe.
			. ' --user-group '
			. escapeshellarg($name) . ' 2>&1';
		exec($command, $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException("Systembenutzer \"$name\" konnte nicht angelegt werden:\n" . implode("\n", $output));
		}
	}
}
