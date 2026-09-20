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
 * @version Letzte Änderung: 2026-09-19 14:52
 */

require __DIR__ . '/../bootstrap.php';

use VhostAdmin\Cli\Application;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Migration\LayoutMigrator;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\SystemdReloader;
use VhostAdmin\Php\PoolRenderer;
use VhostAdmin\Php\SystemdFpmReloader;
use VhostAdmin\Php\SystemUsers;
use VhostAdmin\Ssl\CertbotClient;
use VhostAdmin\Ssl\CurlAcmeReachability;
use VhostAdmin\Ssl\ReachabilityChecker;
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
$reachability = new CurlAcmeReachability();
$database = new Database($config);
$database->initSchema();
$repository = new VhostRepository($database);
$layout = new VhostLayout($config);
$service = new VhostService(
	$config, $repository, new ConfigRenderer($config, $layout),
	new SystemdReloader(), new CertbotClient(), $layout,
	new PoolRenderer($layout), new SystemdFpmReloader($config), new SystemUsers(),
	new ReachabilityChecker($reachability)
);
$migrator = new LayoutMigrator($config, $repository, $layout);

exit((new Application($service, $repository, $config, $layout, $migrator, STDIN, STDOUT, STDERR))->run($argv));
