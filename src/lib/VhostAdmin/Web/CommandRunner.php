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
 * @version Letzte Änderung: 2026-09-17 13:05
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
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $trimOutput ? trim($output) : $output];
	}
}
