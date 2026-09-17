#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/vhost.php';

const USAGE = <<<TXT
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

array_shift($argv);
$cmd = array_shift($argv) ?? 'help';
$opts = [];
$pos = [];
$valueOpts = ['subdir'];
for ($i = 0; $i < count($argv); $i++) {
    $a = $argv[$i];
    if (!str_starts_with($a, '--')) {
        $pos[] = $a;
        continue;
    }
    $body = substr($a, 2);
    if (str_contains($body, '=')) {
        [$k, $val] = explode('=', $body, 2);
        $opts[$k] = $val;
    } elseif (in_array($body, $valueOpts, true)) {
        $opts[$body] = $argv[++$i] ?? '';
    } else {
        $opts[$body] = true;
    }
}

if (in_array($cmd, ['help', '--help', '-h'], true)) {
    echo USAGE;
    exit(0);
}
if (vh_euid() !== 0) {
    fwrite(STDERR, "vhost muss als root laufen (sudo vhost ...)\n");
    exit(1);
}

$arg = fn(int $i, string $what): string => $pos[$i] ?? vh_fail("$what fehlt");
$onoff = fn(string $s): bool => match ($s) {
    'on' => true,
    'off' => false,
    default => vh_fail('erwartet on|off'),
};

try {
    db_init();
    switch ($cmd) {
        case 'init':
            echo "Datenbank bereit.\n";
            break;

        case 'list':
            foreach (vhost_all() as $v) {
                printf(
                    "%-32s %-9s %-44s schutz:%-3s ssl:%s\n",
                    $v['name'], $v['kind'], vh_docroot($v),
                    $v['protect'] ? 'an' : 'aus', $v['ssl'] ? 'an' : 'aus'
                );
            }
            break;

        case 'add':
            $v = vh_create('domain', validate_domain($arg(0, 'Domain')), null,
                validate_subdir($opts['subdir'] ?? null), empty($opts['no-protect']));
            echo "angelegt: {$v['name']} -> " . vh_docroot($v) . "\n";
            break;

        case 'add-local':
            $port = validate_port($arg(0, 'Port'));
            $v = vh_create('localhost', "localhost:$port", $port,
                validate_subdir($opts['subdir'] ?? null), empty($opts['no-protect']));
            echo "angelegt: {$v['name']} -> " . vh_docroot($v) . "\n";
            break;

        case 'remove':
            vh_remove(vh_load($arg(0, 'Name')), !empty($opts['purge']));
            echo "entfernt.\n";
            break;

        case 'protect':
            $on = $onoff($arg(1, 'on|off'));
            vh_set_protect(vh_load($arg(0, 'Name')), $on);
            echo 'Verzeichnisschutz ' . ($on ? 'aktiviert' : 'deaktiviert') . ".\n";
            break;

        case 'user-add':
            $v = vh_load($arg(0, 'Name'));
            $user = validate_username($arg(1, 'Benutzer'));
            $pw = rtrim((string)stream_get_contents(STDIN), "\r\n");
            vh_user_add($v, $user, $pw);
            echo "Benutzer $user gespeichert.\n";
            break;

        case 'user-del':
            $v = vh_load($arg(0, 'Name'));
            vh_user_del($v, validate_username($arg(1, 'Benutzer')));
            echo "Benutzer entfernt.\n";
            break;

        case 'ip-add':
            $v = vh_load($arg(0, 'Name'));
            $cidr = validate_cidr($arg(1, 'IP'));
            vh_ip_add($v, $cidr);
            echo "IP $cidr freigegeben.\n";
            break;

        case 'ip-del':
            $v = vh_load($arg(0, 'Name'));
            vh_ip_del($v, validate_cidr($arg(1, 'IP')));
            echo "IP entfernt.\n";
            break;

        case 'ssl':
            $on = $onoff($arg(1, 'on|off'));
            vh_ssl(vh_load($arg(0, 'Name')), $on);
            echo "Let's Encrypt " . ($on ? 'aktiviert' : 'deaktiviert') . ".\n";
            break;

        case 'set':
            vh_set_setting($arg(0, 'Schlüssel'), $pos[1] ?? '');
            echo "gespeichert.\n";
            break;

        case 'render':
            if (isset($pos[0])) {
                vh_render(vh_load($pos[0]));
            } else {
                foreach (vhost_all() as $v) {
                    vh_render($v, false);
                }
                vh_nginx_reload();
            }
            echo "nginx-Konfiguration neu geschrieben.\n";
            break;

        default:
            fwrite(STDERR, USAGE);
            exit(2);
    }
    vh_fix_db_perms();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    vh_fix_db_perms();
    exit(1);
}
