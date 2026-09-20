<?php
declare(strict_types=1);

/**
 * Prüft, ob der ACME-Pfad einer Domain aus dem Internet erreichbar ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:10
 */

namespace VhostAdmin\Ssl;

interface AcmeReachabilityInterface
{
	/**
	 * Prüft mehrere Domains; die Abfragen laufen parallel, damit die Oberfläche nicht
	 * bei jeder Domain nacheinander auf einen Zeitablauf wartet.
	 *
	 * @param array<string, string> $targets Domainname => erwarteter Markerinhalt
	 * @return array<string, ReachabilityResult> Domainname => Ergebnis
	 */
	public function checkMany(array $targets): array;
}
