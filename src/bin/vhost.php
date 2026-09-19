#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einstieg der Kommandozeile "vhost": baut die Objekte zusammen und startet die Anwendung.
 *
 * Alle Befehle außer "help" verlangen root, weil sie /var/www, /etc/nginx und
 * die Datenbank schreiben und nginx neu laden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:14
 */

require __DIR__ . '/../bootstrap.php';

use VhostAdmin\Cli\Application;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\SystemdReloader;
use VhostAdmin\Ssl\CertbotClient;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

$command = $argv[1] ?? 'help';

// Hilfe zuerst: sie darf weder root noch Datenbank verlangen.
if (in_array($command, ['help', '--help', '-h'], true)) {
	fwrite(STDOUT, Application::USAGE);
	exit(0);
}

$euid = function_exists('posix_geteuid') ? posix_geteuid() : (int)trim((string)shell_exec('id -u'));
if ($euid !== 0) {
	fwrite(STDERR, "vhost muss als root laufen (sudo vhost ...)\n");
	exit(1);
}

$config = Config::defaults();
$database = new Database($config);
$database->initSchema();
$repository = new VhostRepository($database);
$service = new VhostService($config, $repository, new ConfigRenderer($config, new VhostLayout($config)), new SystemdReloader(), new CertbotClient());
$layout = new VhostLayout($config);

exit((new Application($service, $repository, $config, $layout, STDIN, STDOUT, STDERR))->run($argv));
