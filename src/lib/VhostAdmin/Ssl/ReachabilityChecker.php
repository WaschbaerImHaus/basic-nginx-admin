<?php
declare(strict_types=1);

/**
 * Entscheidet, welche vHosts geprüft werden, und deutet das Ergebnis.
 *
 * Eigene Klasse, weil zwei Aufrufer dieselbe Logik brauchen: VhostService vor der
 * Zertifikatsausstellung (als root über das CLI) und die Oberfläche beim Aufbau der
 * Seite (als www-data). Die Oberfläche darf das selbst tun, denn der Marker ist eine
 * öffentliche Datei – dafür braucht es keine root-Rechte und keinen Umweg über das CLI.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:40
 */

namespace VhostAdmin\Ssl;

use VhostAdmin\Vhost;

final class ReachabilityChecker
{
	public function __construct(private readonly AcmeReachabilityInterface $reachability)
	{
	}

	/**
	 * Prüft einen einzelnen Namen gegen die Kennung eines vHosts.
	 *
	 * Für den Nebennamen der www-Umleitung: Der liefert denselben Marker aus wie der
	 * Hauptname, weil beide auf denselben Docroot zeigen.
	 */
	public function checkName(string $name, ?string $token): ReachabilityResult
	{
		if ($token === null) {
			return new ReachabilityResult(
				ReachabilityStatus::Unreachable,
				'Noch nicht geprüft: die Kennung für den ACME-Pfad fehlt.'
			);
		}
		return $this->reachability->checkMany([$name => $token])[$name]
			?? new ReachabilityResult(ReachabilityStatus::Unreachable, "Keine Antwort für \"$name\".");
	}

	/**
	 * Prüft die übergebenen vHosts und liefert je Name ein Ergebnis.
	 *
	 * localhost-Hosts werden nicht angefragt: sie sind absichtlich nie von aussen
	 * erreichbar, die Anfrage wäre sinnlos und kostete nur Wartezeit.
	 *
	 * @param list<Vhost> $vhosts
	 * @return array<string, ReachabilityResult>
	 */
	public function check(array $vhosts): array
	{
		$results = [];
		$targets = [];
		foreach ($vhosts as $vhost) {
			if ($vhost->isLocal()) {
				$results[$vhost->name] = new ReachabilityResult(
					ReachabilityStatus::NotApplicable,
					'localhost-Hosts sind absichtlich nur lokal erreichbar.'
				);
				continue;
			}
			if ($vhost->healthToken === null) {
				$results[$vhost->name] = new ReachabilityResult(
					ReachabilityStatus::Unreachable,
					'Noch nicht geprüft: die Kennung für den ACME-Pfad fehlt. '
					. 'Einmal "sudo vhost render" laufen lassen.'
				);
				continue;
			}
			$targets[$vhost->name] = $vhost->healthToken;
		}
		return $results + ($targets === [] ? [] : $this->reachability->checkMany($targets));
	}
}
