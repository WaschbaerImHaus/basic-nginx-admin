<?php
declare(strict_types=1);

/**
 * Ordnet einen versuchten Benutzernamen ein: geraten, gezielt oder aus unserem Köder.
 *
 * „admin" und „root" probiert jedes Werkzeug überall – das ist Rauschen. Ein Name aus
 * der Domain heisst, dass jemand sich diese Seite angesehen hat. Und „deploy-<kennung>"
 * stammt aus einem ausgelieferten Köder: Der Fund wurde ausgewertet und benutzt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 13:10
 */

namespace Honeypot;

final class LoginName
{
	public const GUESSED = 'geraten';
	public const TARGETED = 'gezielt';
	public const DECOY = 'aus dem Köder';
	public const OTHER = 'unklar';

	/** Namen, die Werkzeuge überall probieren (klein geschrieben). */
	private const GENERIC = [
		'', 'x', 'a', 'admin', 'administrator', 'root', 'test', 'tester', 'user', 'guest', 'demo', 'info',
		'support', 'web', 'www', 'www-data', 'ubuntu', 'debian', 'pi', 'oracle', 'postgres', 'mysql', 'ftp',
		'wordpress', 'wp', 'webmaster', 'manager', 'operator', 'sysadmin', 'system', 'default', 'backup',
	];

	public static function classify(string $name, string $host): string
	{
		if (preg_match('/^deploy-[0-9a-f]{10}$/', $name) === 1) {
			return self::DECOY;
		}
		$lower = strtolower($name);
		// Teile des Hostnamens ohne Endung, ab drei Zeichen: „bienchen", „mfsvr".
		$labels = array_filter(
			array_slice(explode('.', strtolower($host)), 0, -1),
			static fn(string $label): bool => strlen($label) >= 3
		);
		foreach ($labels as $label) {
			if (str_contains($lower, $label)) {
				return self::TARGETED;
			}
		}
		return in_array($lower, self::GENERIC, true) ? self::GUESSED : self::OTHER;
	}
}
