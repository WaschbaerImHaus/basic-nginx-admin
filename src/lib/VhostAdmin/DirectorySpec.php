<?php
declare(strict_types=1);

/**
 * Wertobjekt: ein Verzeichnis mit seinen Soll-Rechten.
 *
 * Wird von VhostLayout::directories() geliefert und von Service, Installer und
 * Migration gleichermaßen angewandt, damit die Rechte nicht auseinanderlaufen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:14
 */

namespace VhostAdmin;

final class DirectorySpec
{
	/**
	 * @param string $path        absoluter Pfad des Verzeichnisses
	 * @param string $owner       Soll-Besitzer (Benutzername)
	 * @param string $group       Soll-Gruppe
	 * @param int    $mode        Soll-Rechte als Oktalzahl, z.B. 02775
	 * @param string $description kurze deutsche Beschreibung für Meldungen
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $owner,
		public readonly string $group,
		public readonly int $mode,
		public readonly string $description,
	) {
	}
}
