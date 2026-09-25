<?php
declare(strict_types=1);

/**
 * Namensauflösung für die Auswertung.
 *
 * Als eigene Schnittstelle, weil das der einzige Teil der Auswertung ist, der ins Netz
 * greift: Die Tests laufen damit ohne DNS, und es bleibt an einer Stelle sichtbar, wo
 * Fremdauskünfte ins Ergebnis kommen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 10:00
 */

namespace Honeypot;

interface DnsResolverInterface
{
	/**
	 * Adresse eines Namens (A, sonst AAAA), oder null.
	 */
	public function addressFor(string $host): ?string;

	/**
	 * Rückwärtsname einer Adresse (PTR), oder null.
	 */
	public function reverseFor(string $address): ?string;
}
