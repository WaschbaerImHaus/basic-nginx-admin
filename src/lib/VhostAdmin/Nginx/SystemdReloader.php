<?php
declare(strict_types=1);

/**
 * nginx über systemd neu laden.
 *
 * "nginx -s reload" kehrt sofort zurück, während der Master die alten Worker
 * erst nach und nach ersetzt. Damit Aufrufer direkt danach die neue
 * Konfiguration sehen, wartet diese Klasse, bis die alten Worker-PIDs
 * verschwunden sind (höchstens fünf Sekunden) – das funktioniert zuverlässig
 * bei CLI-Aufrufen (siehe BUGS.md, "Behoben").
 *
 * Kommt der Aufruf aus der Oberfläche (erkennbar an SUDO_USER=www-data, siehe
 * Cli\Application::run() bei "remove --purge"), wird dieses Warten
 * übersprungen: Die HTTP-Anfrage, die diesen Reload ausgelöst hat, wird noch
 * vom *alten* Worker bedient; der kann sich erst beenden, wenn die Antwort auf
 * genau diese Anfrage geschrieben ist. Ein Warten auf sein Verschwinden würde
 * also praktisch immer bis zum Timeout laufen, ohne etwas zu gewinnen – die
 * Antwort an den Browser ist ohnehin eine Weiterleitung, und die nächste
 * Anfrage trifft bereits den neuen Worker.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 20:10
 */

namespace VhostAdmin\Nginx;

final class SystemdReloader implements ReloaderInterface
{
	/**
	 * Prüft die Konfiguration mit "nginx -t", startet den Reload über systemd
	 * und wartet – außer bei Aufrufen aus der Oberfläche – bis die alten
	 * Worker-Prozesse beendet sind.
	 *
	 * @throws \RuntimeException wenn die Konfiguration fehlerhaft ist oder der Reload scheitert
	 */
	public function reload(): void
	{
		exec('nginx -t 2>&1', $testOutput, $testCode);
		if ($testCode !== 0) {
			throw new \RuntimeException("nginx -t fehlgeschlagen:\n" . implode("\n", $testOutput));
		}
		$oldWorkers = $this->workerPids();
		exec('systemctl reload-or-restart nginx 2>&1', $reloadOutput, $reloadCode);
		if ($reloadCode !== 0) {
			throw new \RuntimeException("nginx-Reload fehlgeschlagen:\n" . implode("\n", $reloadOutput));
		}
		if (getenv('SUDO_USER') === 'www-data') {
			// Selbstblockade vermeiden: Dieser Aufruf steckt in einer Anfrage, die der
			// alte Worker gerade noch bedient (siehe Klassenkommentar). Der wartet hier
			// nicht mit – das Ergebnis wäre ohnehin nur der Timeout.
			return;
		}
		for ($i = 0; $i < 100 && $oldWorkers !== []; $i++) {
			usleep(50000);
			$oldWorkers = array_filter($oldWorkers, static fn(int $pid): bool => file_exists("/proc/$pid"));
		}
	}

	/**
	 * PIDs der aktuellen nginx-Worker (Kinder des Master-Prozesses).
	 *
	 * @return list<int>
	 */
	private function workerPids(): array
	{
		$master = (int)trim((string)shell_exec('systemctl show -p MainPID --value nginx'));
		if ($master <= 0) {
			return [];
		}
		$pids = array_map('intval', explode("\n", trim((string)shell_exec('pgrep -P ' . $master))));
		return array_values(array_filter($pids));
	}
}
