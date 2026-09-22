<?php
declare(strict_types=1);

/**
 * Ordnet einen gesuchten Pfad einer Beutegruppe zu.
 *
 * Die Gruppe ist die eigentliche Aussage, nicht der einzelne Pfad: Auf einer statischen
 * Seite wird nicht nach Lücken einer Anwendung gesucht, sondern nach Zugangsdaten.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:10
 */

namespace Honeypot;

final class LootClassifier
{
	/**
	 * Die Reihenfolge ist Teil der Aussage: /wp-config.php.backup passt auf
	 * „Zugangsdaten" und auf „Sicherungen". Die erste Gruppe gewinnt, sonst hinge das
	 * Ergebnis an der Reihenfolge der Zeichen im Pfad.
	 *
	 * @var array<string, string> Gruppenname => regulärer Ausdruck
	 */
	public const GROUPS = [
		'Zugangsdaten' => '/(\.env|wp-config|credential|secret|\.git\/config|id_rsa|\.aws|passwd)/i',
		'Konfiguration' => '/(web\.config|config\.(json|php|yml|yaml)|settings\.py|\.htaccess)/i',
		'Paketdateien' => '/(yarn\.lock|package(-lock)?\.json|composer\.(json|lock)|Gemfile)/i',
		'Entwicklungsreste' => '/(phpinfo|\/tests?\/|\/tmp\/|\.bak|\.old|\.swp|\/debug)/i',
		'Verwaltung' => '/(phpmyadmin|\/admin|\/manager|\/wp-admin|\/cpanel|\/solr)/i',
		'Sicherungen' => '/(\.sql|\.tar|\.gz|\.zip|backup|dump)/i',
	];

	/**
	 * Gruppenname oder null, wenn der Pfad zu keiner bekannten Beute passt.
	 */
	public function classify(string $path): ?string
	{
		foreach (self::GROUPS as $group => $pattern) {
			if (preg_match($pattern, $path) === 1) {
				return $group;
			}
		}
		return null;
	}
}
