<?php
declare(strict_types=1);

/**
 * php-fpm über systemd neu laden.
 *
 * Ein Reload liest die Pool-Dateien neu ein; ein neuer oder entfernter Pool wird also
 * ohne Neustart wirksam. Anders als bei nginx wird hier nicht auf das Verschwinden
 * alter Prozesse gewartet: Die Verbindung läuft über den Unix-Socket, und php-fpm
 * übernimmt den Socket beim Reload selbst – ein wartender Aufrufer gewinnt nichts.
 *
 * Wichtig ist die Reihenfolge beim Einschalten von PHP: Erst muss der Systembenutzer
 * existieren, dann die Pool-Datei geschrieben werden. php-fpm verweigert sonst den
 * Start des Pools mit "Unable to find user", und dann steht auch für alle anderen
 * Hosts kein PHP mehr bereit. Deshalb prüft diese Klasse vor dem Reload mit
 * "php-fpm -t"; schlägt das fehl, bleibt der laufende Dienst unangetastet.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:05
 */

namespace VhostAdmin\Php;

use VhostAdmin\Config;

final class SystemdFpmReloader implements FpmReloaderInterface
{
	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Prüft die Konfiguration und lädt php-fpm neu.
	 *
	 * @throws \RuntimeException wenn die Konfiguration fehlerhaft ist oder der Reload scheitert
	 */
	public function reload(): void
	{
		$binary = $this->binary();
		exec(escapeshellarg($binary) . ' -t 2>&1', $testOutput, $testCode);
		if ($testCode !== 0) {
			throw new \RuntimeException("php-fpm -t fehlgeschlagen:\n" . implode("\n", $testOutput));
		}
		$service = escapeshellarg($this->config->fpmService);
		exec("systemctl reload-or-restart $service 2>&1", $reloadOutput, $reloadCode);
		if ($reloadCode !== 0) {
			throw new \RuntimeException("php-fpm-Reload fehlgeschlagen:\n" . implode("\n", $reloadOutput));
		}
	}

	/**
	 * Pfad des php-fpm-Programms; fehlt es, bleibt "php-fpm" aus dem Suchpfad.
	 *
	 * Das Programm heißt "php-fpm<version>" (z.B. php-fpm8.5), der systemd-Dienst
	 * dagegen "php<version>-fpm" (php8.5-fpm) – die Namen sind nicht gleich aufgebaut,
	 * deshalb kommen beide getrennt aus der Konfiguration.
	 */
	private function binary(): string
	{
		return is_executable($this->config->fpmBinary) ? $this->config->fpmBinary : 'php-fpm';
	}
}
