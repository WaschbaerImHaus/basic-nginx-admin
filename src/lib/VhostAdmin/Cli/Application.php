<?php
declare(strict_types=1);

/**
 * Kommandozeile "vhost": Argumente auswerten und an den Service weiterreichen.
 *
 * Läuft als root (sudo); die Oberfläche ruft dieselben Befehle über sudoers auf.
 * Passwörter kommen über stdin, nie als Argument (wären in "ps" sichtbar).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:20
 */

namespace VhostAdmin\Cli;

use VhostAdmin\Config;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
use VhostAdmin\Vhost;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class Application
{
	public const USAGE = <<<TXT
vhost – nginx-vHosts verwalten

  vhost list
  vhost add <domain> [--subdir DIR] [--no-protect]
  vhost add-local <port> [--subdir DIR] [--no-protect]
  vhost remove <name> [--purge]
  vhost protect <name> on|off
  vhost user-add <name> <user>        (Passwort per stdin)
  vhost user-del <name> <user>
  vhost ip-add <name> <ip|cidr>
  vhost ip-del <name> <ip|cidr>
  vhost ssl <name> on|off
  vhost set le_email <adresse>
  vhost render [name]
  vhost init

TXT;

	private const VALUE_OPTIONS = ['subdir'];

	/**
	 * Übernimmt Service, Repository, Konfiguration und die Ein-/Ausgabe-Streams.
	 *
	 * @param resource $stdin
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function __construct(
		private readonly VhostService $service,
		private readonly VhostRepository $repository,
		private readonly Config $config,
		private $stdin,
		private $stdout,
		private $stderr,
	) {
	}

	/**
	 * Zerlegt argv in Befehl, Positionsargumente und Optionen.
	 *
	 * "--subdir X" und "--subdir=X" tragen einen Wert, alle anderen "--flag" sind Schalter.
	 *
	 * @param list<string> $argv inklusive Programmname an Position 0
	 * @return array{command: string, positional: list<string>, options: array<string, string|true>}
	 */
	public static function parse(array $argv): array
	{
		array_shift($argv);
		$command = array_shift($argv) ?? 'help';
		$positional = [];
		$options = [];
		$count = count($argv);
		for ($i = 0; $i < $count; $i++) {
			$arg = $argv[$i];
			if (!str_starts_with($arg, '--')) {
				$positional[] = $arg;
				continue;
			}
			$body = substr($arg, 2);
			if (str_contains($body, '=')) {
				[$key, $value] = explode('=', $body, 2);
				$options[$key] = $value;
			} elseif (in_array($body, self::VALUE_OPTIONS, true)) {
				$options[$body] = $argv[++$i] ?? '';
			} else {
				$options[$body] = true;
			}
		}
		return ['command' => $command, 'positional' => $positional, 'options' => $options];
	}

	/**
	 * Führt den Befehl aus und liefert den Exit-Code (0 ok, 1 Fehler, 2 Usage).
	 *
	 * @param list<string> $argv
	 */
	public function run(array $argv): int
	{
		['command' => $command, 'positional' => $positional, 'options' => $options] = self::parse($argv);
		if (in_array($command, ['help', '--help', '-h'], true)) {
			$this->out(self::USAGE);
			return 0;
		}
		try {
			$code = $this->dispatch($command, $positional, $options);
		} catch (\Throwable $e) {
			$this->err('Fehler: ' . $e->getMessage() . "\n");
			$code = 1;
		}
		$this->service->fixDatabasePermissions();
		return $code;
	}

	/**
	 * Wertet den Befehl aus und ruft den passenden Anwendungsfall im Service auf.
	 *
	 * @param list<string> $positional
	 * @param array<string, string|true> $options
	 */
	private function dispatch(string $command, array $positional, array $options): int
	{
		$arg = static fn(int $index, string $what): string => $positional[$index] ?? throw new \RuntimeException("$what fehlt");
		$onOff = static fn(string $value): bool => match ($value) {
			'on' => true,
			'off' => false,
			default => throw new \RuntimeException('Erwartet on|off'),
		};
		$subdir = SubDirectory::fromString(is_string($options['subdir'] ?? null) ? $options['subdir'] : null);
		$protect = !isset($options['no-protect']);

		switch ($command) {
			case 'init':
				$this->out("Datenbank bereit.\n");
				return 0;

			case 'list':
				foreach ($this->repository->all() as $vhost) {
					$this->out(sprintf(
						"%-32s %-9s %-44s schutz:%-3s ssl:%s\n",
						$vhost->name,
						$vhost->kind->value,
						$vhost->docroot($this->config),
						$vhost->protect ? 'an' : 'aus',
						$vhost->ssl ? 'an' : 'aus'
					));
				}
				return 0;

			case 'add':
				$vhost = $this->service->createDomain(DomainName::fromString($arg(0, 'Domain')), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $vhost->docroot($this->config) . "\n");
				return 0;

			case 'add-local':
				$vhost = $this->service->createLocal(Port::fromString($arg(0, 'Port'), $this->config->adminPort), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $vhost->docroot($this->config) . "\n");
				return 0;

			case 'remove':
				$this->service->remove($this->service->load($arg(0, 'Name')), isset($options['purge']));
				$this->out("Entfernt.\n");
				return 0;

			case 'protect':
				$on = $onOff($arg(1, 'on|off'));
				$this->service->setProtection($this->service->load($arg(0, 'Name')), $on);
				$this->out('Verzeichnisschutz ' . ($on ? 'aktiviert' : 'deaktiviert') . ".\n");
				return 0;

			case 'user-add':
				$vhost = $this->service->load($arg(0, 'Name'));
				$user = Username::fromString($arg(1, 'Benutzer'));
				$password = rtrim((string)stream_get_contents($this->stdin), "\r\n");
				$this->service->addUser($vhost, $user, $password);
				$this->out("Benutzer {$user->value} gespeichert.\n");
				return 0;

			case 'user-del':
				$this->service->removeUser($this->service->load($arg(0, 'Name')), Username::fromString($arg(1, 'Benutzer')));
				$this->out("Benutzer entfernt.\n");
				return 0;

			case 'ip-add':
				$cidr = Cidr::fromString($arg(1, 'IP'));
				$this->service->addIp($this->service->load($arg(0, 'Name')), $cidr);
				$this->out("IP {$cidr->value} freigegeben.\n");
				return 0;

			case 'ip-del':
				$this->service->removeIp($this->service->load($arg(0, 'Name')), Cidr::fromString($arg(1, 'IP')));
				$this->out("IP entfernt.\n");
				return 0;

			case 'ssl':
				$on = $onOff($arg(1, 'on|off'));
				$vhost = $this->service->load($arg(0, 'Name'));
				if ($on) {
					$output = $this->service->enableSsl($vhost);
					if ($output !== '') {
						$this->out($output . "\n");
					}
				} else {
					$this->service->disableSsl($vhost);
				}
				$this->out("Let's Encrypt " . ($on ? 'aktiviert' : 'deaktiviert') . ".\n");
				return 0;

			case 'set':
				$key = $arg(0, 'Schlüssel');
				if ($key !== 'le_email') {
					throw new \RuntimeException("Unbekannte Einstellung: $key");
				}
				$this->service->setLetsEncryptEmail($positional[1] ?? '');
				$this->out("Gespeichert.\n");
				return 0;

			case 'render':
				if (isset($positional[0])) {
					$this->service->render($this->service->load($positional[0]));
				} else {
					$this->service->renderAll();
				}
				$this->out("nginx-Konfiguration neu geschrieben.\n");
				return 0;

			default:
				$this->err(self::USAGE);
				return 2;
		}
	}

	/**
	 * Schreibt Text auf den Standardausgabe-Stream.
	 */
	private function out(string $text): void
	{
		fwrite($this->stdout, $text);
	}

	/**
	 * Schreibt Text auf den Standardfehler-Stream.
	 */
	private function err(string $text): void
	{
		fwrite($this->stderr, $text);
	}
}
