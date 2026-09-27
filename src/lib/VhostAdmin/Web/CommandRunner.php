<?php
declare(strict_types=1);

/**
 * Führt das CLI "vhost" aus der Oberfläche heraus aus (standardmäßig per sudo).
 *
 * Die Oberfläche läuft als www-data und hat selbst keine Rechte; sudoers
 * erlaubt ihr genau dieses eine Programm. Argumente gehen als Array an
 * proc_open, es gibt keine Shell dazwischen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:55
 */

namespace VhostAdmin\Web;

use VhostAdmin\Config;

final class CommandRunner
{
	/**
	 * @param list<string> $prefix Programme vor dem CLI, z. B. ["sudo", "-n"]; Tests übergeben ["php"]
	 */
	public function __construct(private readonly Config $config, private readonly array $prefix = ['sudo', '-n'])
	{
	}

	/**
	 * Startet das CLI mit den Argumenten; optional wird stdin (Passwort) übergeben.
	 *
	 * @param list<string> $args
	 * @return array{int, string} Exit-Code und gesamte Ausgabe (stdout + stderr, getrimmt)
	 */
	public function run(array $args, ?string $stdin = null, bool $trimOutput = true): array
	{
		$command = array_merge($this->prefix, [$this->config->vhostBinary], $args);
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			return [1, 'Konnte vhost nicht starten.'];
		}
		// stdin schreiben und stdout/stderr lesen – gleichzeitig. Nacheinander blockieren
		// sich beide Seiten, sobald ein Kanal mehr als einen Pipe-Puffer (64 KiB) trägt:
		// Das CLI wartet, bis jemand stderr leert, und wir warten auf das Ende von stdout.
		$pending = $stdin ?? '';
		$buffers = [1 => '', 2 => ''];
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}
		if ($pending === '') {
			fclose($pipes[0]);
			unset($pipes[0]);
		}
		while (isset($pipes[1]) || isset($pipes[2])) {
			$read = array_values(array_filter([$pipes[1] ?? null, $pipes[2] ?? null]));
			$write = isset($pipes[0]) ? [$pipes[0]] : [];
			$except = null;
			if (stream_select($read, $write, $except, 30) === false) {
				break;
			}
			if ($write !== []) {
				$written = @fwrite($pipes[0], $pending);
				// false/0 bei geschlossener Gegenseite: Das CLI liest stdin nicht mehr.
				$pending = $written === false || $written === 0 ? '' : substr($pending, $written);
				if ($pending === '') {
					fclose($pipes[0]);
					unset($pipes[0]);
				}
			}
			foreach ([1, 2] as $channel) {
				if (isset($pipes[$channel]) && in_array($pipes[$channel], $read, true)) {
					$buffers[$channel] .= (string)fread($pipes[$channel], 65536);
					if (feof($pipes[$channel])) {
						fclose($pipes[$channel]);
						unset($pipes[$channel]);
					}
				}
			}
		}
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		$output = $buffers[1] . $buffers[2];
		return [proc_close($process), $trimOutput ? trim($output) : $output];
	}
}
