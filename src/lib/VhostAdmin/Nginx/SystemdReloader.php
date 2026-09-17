<?php
declare(strict_types=1);

/**
 * nginx über systemd neu laden.
 *
 * "nginx -s reload" kehrt sofort zurück, während der Master die alten Worker
 * erst nach und nach ersetzt. Damit Aufrufer direkt danach die neue
 * Konfiguration sehen, wartet diese Klasse, bis die alten Worker-PIDs
 * verschwunden sind (höchstens fünf Sekunden).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace VhostAdmin\Nginx;

final class SystemdReloader implements ReloaderInterface
{
	/**
	 * Prüft die Konfiguration mit "nginx -t", startet den Reload über systemd
	 * und wartet, bis die alten Worker-Prozesse beendet sind.
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
