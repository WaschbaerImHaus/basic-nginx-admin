<?php
declare(strict_types=1);

/**
 * Kommandozeile "vhost": Argumente auswerten und an den Service weiterreichen.
 *
 * Läuft als root (sudo); die Oberfläche ruft dieselben Befehle über sudoers auf.
 * Passwörter kommen über stdin, nie als Argument (wären in "ps" sichtbar).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 15:21
 */

namespace VhostAdmin\Cli;

use VhostAdmin\Config;
use VhostAdmin\Migration\LayoutMigrator;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class Application
{
	public const USAGE = <<<TXT
vhost – nginx-vHosts verwalten

  vhost list
  vhost add <domain> [--subdir DIR] [--no-protect]
  vhost add-local <port> [--subdir DIR] [--no-protect]
  vhost restore <name>
  vhost purge-due
  vhost remove <name> [--purge|--now]   (ohne Option: sperren, Frist, dann entfernen)
  vhost restore <name>                  (ein angestossenes Entfernen zurücknehmen)
  vhost purge-due                       (abgelaufene Vormerkungen endgültig entfernen)
  vhost protect <name> on|off
  vhost user-add <name> <user>        (Passwort per stdin)
  vhost user-del <name> <user>
  vhost ip-add <name> <ip|cidr>
  vhost ip-del <name> <ip|cidr>
  vhost php <name> on|off
  vhost ssl <name> on|off
  vhost cert-extend <name>              (www.<domain> in ein bestehendes Zertifikat nachtragen)
  vhost set le_email <adresse>
  vhost set hsts on|off                 (HSTS wirkt im Browser monatelang nach)
  vhost check-acme [name]
  vhost www <name> bare|www             (wohin umgeleitet wird; nur Hauptdomains)
  vhost subdir <name> [unterordner]     (leer = Docroot ist web/; Inhalt zieht mit)
  vhost show <name>                     (fertige Konfiguration als ein Text)
  vhost conf-show <name>
  vhost conf <name>                   (nginx-Snippet per stdin; leer = entfernen)
  vhost fix-permissions [name]
  vhost migrate-layout
  vhost render [name]
  vhost init

TXT;

	private const VALUE_OPTIONS = ['subdir'];

	/**
	 * Übernimmt Service, Repository, Konfiguration, Layout, Migrator und die Ein-/Ausgabe-Streams.
	 *
	 * @param resource $stdin
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	/** Hat der Leser der Standardausgabe die Verbindung abgebrochen? */
	private bool $stdoutBroken = false;

	public function __construct(
		private readonly VhostService $service,
		private readonly VhostRepository $repository,
		private readonly Config $config,
		private readonly VhostLayout $layout,
		private readonly LayoutMigrator $migrator,
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
						"%-32s %-9s %-44s schutz:%-3s ssl:%-3s %s\n",
						$vhost->name,
						$vhost->kind->value,
						$this->layout->docroot($vhost),
						$vhost->protect ? 'an' : 'aus',
						$vhost->ssl ? 'an' : 'aus',
						$vhost->isPendingDeletion()
							? 'GESPERRT, wird entfernt am '
								. $vhost->deletionDueAt($this->config->removalGraceMinutes)?->format('d.m.Y H:i') . ' UTC'
							: ''
					));
				}
				return 0;

			case 'add':
				$vhost = $this->service->createDomain(DomainName::fromString($arg(0, 'Domain')), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $this->layout->docroot($vhost) . "\n");
				return 0;

			case 'add-local':
				$vhost = $this->service->createLocal(Port::fromString($arg(0, 'Port'), $this->config->adminPort), $subdir, $protect);
				$this->out("Angelegt: {$vhost->name} -> " . $this->layout->docroot($vhost) . "\n");
				return 0;

			case 'remove':
				// Die sudoers-Regel erlaubt www-data beliebige Argumente für dieses CLI (siehe
				// src/etc/sudoers-vhost-admin); die Oberfläche selbst ruft "--purge" nie auf
				// (AdminPage::commandFor() kennt die Option nicht). Trotzdem sperren wir sie hier
				// zusätzlich, falls www-data den Aufruf direkt absetzt (z.B. über eine spätere
				// Lücke in der Oberfläche): sudo setzt SUDO_USER auf den ursprünglichen Aufrufer.
				if (isset($options['purge']) && getenv('SUDO_USER') === 'www-data') {
					throw new \RuntimeException('--purge ist aus der Oberfläche nicht erlaubt');
				}
				$vhost = $this->service->load($arg(0, 'Name'));
				// Ohne --purge/--now wird das Entfernen nur angestossen: nginx liefert den
				// vHost sofort nicht mehr aus, der Eintrag bleibt aber für die Dauer der
				// Schonfrist bestehen und lässt sich mit "vhost restore" zurückholen.
				// --purge (Dateien mit löschen) und --now sind die ausdrücklichen Wege,
				// sofort und endgültig zu entfernen.
				if (isset($options['purge']) || isset($options['now'])) {
					$this->service->remove($vhost, isset($options['purge']));
					$this->out("Entfernt.\n");
					return 0;
				}
				$this->service->scheduleRemoval($vhost);
				$due = $this->service->load($vhost->name)->deletionDueAt($this->config->removalGraceMinutes);
				$this->out(
					"Gesperrt: {$vhost->name} wird nicht mehr ausgeliefert.\n"
					. 'Endgültig entfernt am ' . $due?->format('d.m.Y H:i') . " UTC.\n"
					. "Zurückholen mit: vhost restore {$vhost->name}\n"
				);
				return 0;

			case 'restore':
				$vhost = $this->service->load($arg(0, 'Name'));
				if (!$vhost->isPendingDeletion()) {
					throw new \RuntimeException("{$vhost->name} ist nicht zum Entfernen vorgemerkt.");
				}
				$this->service->restore($vhost);
				$this->out("Zurückgeholt: {$vhost->name} wird wieder ausgeliefert.\n");
				return 0;

			case 'purge-due':
				$removed = $this->service->purgeDue();
				$this->out($removed === []
					? "Nichts fällig.\n"
					: count($removed) . ' endgültig entfernt: ' . implode(', ', $removed) . "\n");
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

			case 'php':
				$on = $onOff($arg(1, 'on|off'));
				$vhost = $this->service->load($arg(0, 'Name'));
				if ($on) {
					$this->service->enablePhp($vhost);
					$this->out('PHP aktiviert (eigener Pool ' . $this->layout->phpUser($vhost)
						. ', Socket ' . $this->layout->phpSocket($vhost) . ").\n");
				} else {
					$this->service->disablePhp($vhost);
					$this->out("PHP deaktiviert.\n");
				}
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
				if ($key === 'hsts') {
					$this->service->setHsts($onOff($arg(1, 'on|off')));
					$this->out('Strict-Transport-Security ' . ($this->service->hstsEnabled() ? 'aktiviert' : 'deaktiviert')
						. " (alle Hosts neu geschrieben).\n");
					return 0;
				}
				if ($key !== 'le_email') {
					throw new \RuntimeException("Unbekannte Einstellung: $key (bekannt: le_email, hsts)");
				}
				$this->service->setLetsEncryptEmail($positional[1] ?? '');
				$this->out("Gespeichert.\n");
				return 0;

			case 'conf':
				$vhost = $this->service->load($arg(0, 'Name'));
				// Der Bezugsrahmen kommt aus dem Layout: nur damit lassen sich Pfade im
				// Snippet gegen die Ordner genau dieses vHosts prüfen.
				$snippet = NginxSnippet::fromString(
					(string)stream_get_contents($this->stdin),
					$this->layout->snippetScope($vhost)
				);
				$this->service->setSnippet($vhost, $snippet);
				$this->out($snippet->isEmpty() ? "Konfiguration entfernt.\n" : "Konfiguration übernommen.\n");
				return 0;

			case 'check-acme':
				// Zum Nachsehen auf der Kommandozeile; die Oberfläche prüft selbst.
				$targets = isset($positional[0]) ? [$this->service->load($positional[0])] : $this->repository->all();
				$failed = false;
				foreach ($this->service->checkReachability($targets) as $domain => $result) {
					$this->out(str_pad($result->status->value, 14) . str_pad($domain, 34) . $result->message . "\n");
					$failed = $failed || $result->status === \VhostAdmin\Ssl\ReachabilityStatus::DnsFailed
						|| $result->status === \VhostAdmin\Ssl\ReachabilityStatus::Unreachable
						|| $result->status === \VhostAdmin\Ssl\ReachabilityStatus::WrongServer;
				}
				return $failed ? 1 : 0;

			case 'www':
				$vhost = $this->service->load($arg(0, 'Name'));
				$this->service->setWwwMode($vhost, $arg(1, 'bare|www'));
				$updated = $this->service->load($vhost->name);
				$alias = (string)$updated->aliasName();
				$this->out("$alias wird auf {$updated->canonicalName()} umgeleitet.\n"
						. ($updated->ssl && !$this->service->certificateCoversAlias($updated)
							? "Achtung: Das Zertifikat deckt \"$alias\" noch nicht ab. "
								. "Einmal \"vhost ssl {$updated->name} on\" holt eines für beide Namen.\n"
							: ''));
				return 0;

			case 'subdir':
				// Ohne zweites Argument wird der Unterordner geleert (Docroot = web/).
				$vhost = $this->service->load($arg(0, 'Name'));
				$target = SubDirectory::fromString($positional[1] ?? null);
				$this->service->setSubdirectory($vhost, $target);
				$this->out('Docroot: ' . $this->layout->docroot($this->service->load($vhost->name)) . "\n");
				return 0;

			case 'cert-extend':
				// Trägt den Nebennamen in ein bestehendes Zertifikat nach. Eigener Befehl,
				// weil "ssl on" certbot bei vorhandenem Zertifikat bewusst überspringt.
				$output = $this->service->extendCertificate($this->service->load($arg(0, 'Name')));
				if ($output !== '') {
					$this->out($output . "\n");
				}
				$this->out("Zertifikat erweitert.\n");
				return 0;

			case 'cert-covers':
				// Für die Oberfläche: deckt das Zertifikat den Nebennamen ab? Rein lesend.
				$this->out($this->service->certificateCoversAlias($this->service->load($arg(0, 'Name'))) ? "ja\n" : "nein\n");
				return 0;

			case 'show':
				// Die fertige Konfiguration als ein Text – für die Oberfläche und zum
				// Nachsehen auf der Kommandozeile. Rein lesend.
				$this->out($this->service->effectiveConfig($this->service->load($arg(0, 'Name'))) . "\n");
				return 0;

			case 'conf-show':
				// Die Oberfläche kommt an conf/ nicht mehr heran (root:<besitzer> 0750) und
				// holt den Text deshalb hier. Rein lesend.
				$this->out($this->service->snippet($this->service->load($arg(0, 'Name'))));
				return 0;

			case 'fix-permissions':
				$targets = isset($positional[0]) ? [$this->service->load($positional[0])] : $this->repository->all();
				foreach ($targets as $vhost) {
					$this->service->applyPermissions($vhost);
					$this->out("Rechte gesetzt: {$vhost->name}\n");
				}
				$this->out(count($targets) . " vHost(s) bearbeitet.\n");
				return 0;

			case 'migrate-layout':
				$pending = $this->migrator->pending();
				if ($pending !== []) {
					$archive = $this->migrator->backup($pending, $this->config->backupDir);
					$this->out("Sicherung: $archive\n");
					foreach ($pending as $vhost) {
						$this->migrator->migrate($vhost);
						$this->out("Migriert: {$vhost->name} -> " . $this->layout->docroot($vhost) . "\n");
					}
					$this->service->renderAll();
					$this->out(count($pending) . " vHost(s) migriert.\n");
				}
				// Befund 2 (Re-Review 2026-09-20): Renewal-Nachzug für ALLE vHosts, nicht
				// nur für pending() – sonst bekommen bereits migrierte Hosts (deren
				// Zertifikat noch den alten Webroot trägt) ihn nie. migrate() hat den
				// Nachzug für die gerade oben migrierten Hosts zwar schon mitgemacht;
				// dieser Aufruf hier ist idempotent, ein erneutes Anfassen bleibt also
				// unschädlich (siehe LayoutMigrator::migrateRenewalConfigs()).
				$renewalCount = $this->migrator->migrateRenewalConfigs();
				if ($renewalCount > 0) {
					$this->out("$renewalCount Renewal-Konfiguration(en) angepasst.\n");
				}
				if ($pending === [] && $renewalCount === 0) {
					$this->out("Nichts zu migrieren.\n");
				}
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
	 *
	 * Bricht der Leser die Verbindung ab ("vhost list | head", "| grep -q"), schlägt
	 * fwrite() mit "Broken pipe" fehl und PHP gibt eine Notice aus – mitten in der
	 * Ausgabe des Werkzeugs. Das ist kein Fehler des Aufrufs: der Leser wollte einfach
	 * nicht mehr lesen. Nach dem ersten Fehlschlag wird deshalb stillschweigend nichts
	 * mehr geschrieben, statt für jede weitere Zeile eine Notice zu erzeugen.
	 */
	private function out(string $text): void
	{
		if ($this->stdoutBroken) {
			return;
		}
		if (@fwrite($this->stdout, $text) === false) {
			$this->stdoutBroken = true;
		}
	}

	/**
	 * Schreibt Text auf den Standardfehler-Stream.
	 */
	private function err(string $text): void
	{
		fwrite($this->stderr, $text);
	}
}
