<?php
declare(strict_types=1);

// Root-only operations: filesystem, nginx config, certbot. Only the CLI uses this file.

function vh_fail(string $msg): never
{
    throw new RuntimeException($msg);
}

function vh_euid(): int
{
    return function_exists('posix_geteuid') ? posix_geteuid() : (int)trim((string)shell_exec('id -u'));
}

function vh_ipv6(): bool
{
    return file_exists('/proc/net/if_inet6') && trim((string)file_get_contents('/proc/net/if_inet6')) !== '';
}

// ---- validation ---------------------------------------------------------

function validate_domain(string $d): string
{
    $d = strtolower(trim($d));
    if ($d === 'localhost' || str_starts_with($d, 'localhost:') || str_ends_with($d, '.localhost')) {
        vh_fail('localhost-Hosts nur per "vhost add-local <port>"');
    }
    if (strlen($d) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) {
        vh_fail("ungültiger Domainname: $d");
    }
    return $d;
}

function validate_port(string $p): int
{
    if (!ctype_digit($p) || (int)$p < 1 || (int)$p > 65535) {
        vh_fail("ungültiger Port: $p");
    }
    if (in_array((int)$p, [80, 443, cfg()['admin_port']], true)) {
        vh_fail("Port $p ist reserviert");
    }
    return (int)$p;
}

function validate_subdir(mixed $s): ?string
{
    if (!is_string($s)) {
        return null;
    }
    $s = trim($s, "/ \t");
    if ($s === '') {
        return null;
    }
    if (!preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~', $s)) {
        vh_fail("ungültiges Unterverzeichnis: $s");
    }
    return $s;
}

function validate_username(string $u): string
{
    if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $u)) {
        vh_fail("ungültiger Benutzername: $u");
    }
    return $u;
}

function validate_cidr(string $c): string
{
    $c = trim($c);
    [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $max = 32;
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $max = 128;
    } else {
        vh_fail("ungültige IP/CIDR: $c");
    }
    if ($bits === null) {
        return $ip;
    }
    if (!ctype_digit($bits) || (int)$bits > $max) {
        vh_fail("ungültige Netzmaske: $c");
    }
    return $ip . '/' . (int)$bits;
}

// ---- core ---------------------------------------------------------------

function vh_load(string $name): array
{
    return vhost_by_name($name) ?? vh_fail("unbekannter vHost: $name");
}

function vh_create(string $kind, string $name, ?int $port, ?string $subdir, bool $protect): array
{
    if (vhost_by_name($name)) {
        vh_fail("existiert bereits: $name");
    }
    db()->prepare('INSERT INTO vhosts (name, kind, port, subdir, protect) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, $kind, $port, $subdir, (int)$protect]);
    $v = vh_load($name);
    vh_make_dirs($v);
    vh_write_index($v);
    vh_render($v);
    return $v;
}

function vh_own(string $path, int $mode): void
{
    $c = cfg();
    if (!@chown($path, $c['www_owner'])) {
        chown($path, 'www-data');
    }
    chgrp($path, $c['www_group']);
    chmod($path, $mode);
}

function vh_make_dirs(array $v): void
{
    $root = vh_docroot($v);
    if (!is_dir($root) && !mkdir($root, 0775, true)) {
        vh_fail("kann $root nicht anlegen");
    }
    $p = vh_basedir($v);
    vh_own($p, 02775);
    foreach ($v['subdir'] ? explode('/', $v['subdir']) : [] as $seg) {
        $p .= '/' . $seg;
        vh_own($p, 02775);
    }
}

function vh_write_index(array $v): void
{
    $f = vh_docroot($v) . '/index.html';
    if (file_exists($f)) {
        return;
    }
    $html = strtr((string)file_get_contents(cfg()['template']), [
        '{{NAME}}'    => htmlspecialchars($v['name']),
        '{{DOCROOT}}' => htmlspecialchars(vh_docroot($v)),
    ]);
    file_put_contents($f, $html);
    vh_own($f, 0664);
}

function vh_render(array $v, bool $reload = true): void
{
    $c = cfg();
    $slug = vh_slug($v);
    if (!is_dir($c['auth_dir'])) {
        mkdir($c['auth_dir'], 0750, true);
        chgrp($c['auth_dir'], 'www-data');
    }

    $htpasswd = "{$c['auth_dir']}/$slug.htpasswd";
    $lines = '';
    foreach (vhost_users((int)$v['id']) as $u) {
        $lines .= "{$u['username']}:{$u['hash']}\n";
    }
    file_put_contents($htpasswd, $lines);
    chgrp($htpasswd, 'www-data');
    chmod($htpasswd, 0640);

    $auth = "# generiert von vhost – nicht manuell bearbeiten\n";
    if ($v['protect']) {
        $auth .= "satisfy any;\n";
        foreach (vhost_ips((int)$v['id']) as $ip) {
            $auth .= "allow {$ip['cidr']};\n";
        }
        $auth .= "deny all;\nauth_basic \"Geschützter Bereich\";\nauth_basic_user_file $htpasswd;\n";
    } else {
        $auth .= "# Verzeichnisschutz deaktiviert\n";
    }
    file_put_contents("{$c['auth_dir']}/$slug.conf", $auth);

    $avail = "{$c['sites_avail']}/$slug.conf";
    file_put_contents($avail, vh_nginx_conf($v));
    $link = "{$c['sites_enabled']}/$slug.conf";
    if (!is_link($link)) {
        symlink($avail, $link);
    }
    if ($reload) {
        vh_nginx_reload();
    }
}

function vh_nginx_conf(array $v): string
{
    $c = cfg();
    $slug = vh_slug($v);
    $root = vh_docroot($v);
    $base = vh_basedir($v);
    $auth = "{$c['auth_dir']}/$slug.conf";
    $v6 = vh_ipv6();
    $head = "# generiert von vhost – nicht manuell bearbeiten\n";

    $common = <<<NG
    root $root;
    index index.html index.htm;
    access_log /var/log/nginx/$slug.access.log;
    error_log  /var/log/nginx/$slug.error.log;
    include $auth;
    location / {
        try_files \$uri \$uri/ =404;
    }
NG;

    if ($v['kind'] === 'localhost') {
        $p = $v['port'];
        $listen = "    listen 127.0.0.1:$p;\n" . ($v6 ? "    listen [::1]:$p;\n" : '');
        return "{$head}server {\n$listen    server_name localhost;\n$common\n}\n";
    }

    $name = $v['name'];
    $acme = <<<NG
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root $base;
    }
NG;
    $l80 = "    listen 80;\n" . ($v6 ? "    listen [::]:80;\n" : '');
    if (!$v['ssl']) {
        return "{$head}server {\n$l80    server_name $name;\n$acme\n$common\n}\n";
    }

    $l443 = "    listen 443 ssl;\n" . ($v6 ? "    listen [::]:443 ssl;\n" : '') . "    http2 on;\n";
    $live = "{$c['le_live']}/$name";
    $ssl = <<<NG
    ssl_certificate     $live/fullchain.pem;
    ssl_certificate_key $live/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
NG;
    return "{$head}server {\n$l80    server_name $name;\n$acme\n    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n"
        . "server {\n$l443    server_name $name;\n$ssl\n$common\n}\n";
}

function vh_nginx_reload(): void
{
    exec('nginx -t 2>&1', $out, $rc);
    if ($rc !== 0) {
        vh_fail("nginx -t fehlgeschlagen:\n" . implode("\n", $out));
    }
    $master = (int)trim((string)shell_exec('systemctl show -p MainPID --value nginx'));
    $oldWorkers = $master > 0
        ? array_filter(array_map('intval', explode("\n", trim((string)shell_exec("pgrep -P $master")))))
        : [];
    exec('systemctl reload-or-restart nginx 2>&1', $out2, $rc2);
    if ($rc2 !== 0) {
        vh_fail("nginx reload fehlgeschlagen:\n" . implode("\n", $out2));
    }
    // "nginx -s reload" kehrt sofort zurück; erst wenn die alten Worker weg sind, ist die neue Config live
    for ($i = 0; $i < 100 && $oldWorkers; $i++) {
        usleep(50000);
        $oldWorkers = array_filter($oldWorkers, fn(int $pid) => file_exists("/proc/$pid"));
    }
}

function vh_remove(array $v, bool $purge): void
{
    $c = cfg();
    $slug = vh_slug($v);
    foreach ([
        "{$c['sites_enabled']}/$slug.conf",
        "{$c['sites_avail']}/$slug.conf",
        "{$c['auth_dir']}/$slug.conf",
        "{$c['auth_dir']}/$slug.htpasswd",
    ] as $f) {
        if (is_link($f) || file_exists($f)) {
            unlink($f);
        }
    }
    db()->prepare('DELETE FROM vhosts WHERE id = ?')->execute([$v['id']]);
    if ($purge) {
        $base = vh_basedir($v);
        if (str_starts_with($base, $c['www_root'] . '/') && is_dir($base)) {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }
    vh_nginx_reload();
}

function vh_set_protect(array $v, bool $on): void
{
    db()->prepare('UPDATE vhosts SET protect = ? WHERE id = ?')->execute([(int)$on, $v['id']]);
    vh_render(vh_load($v['name']));
}

function vh_user_add(array $v, string $user, string $pw): void
{
    if ($pw === '') {
        vh_fail('leeres Passwort');
    }
    $hash = crypt($pw, '$6$' . substr(bin2hex(random_bytes(12)), 0, 16) . '$');
    db()->prepare('INSERT INTO auth_users (vhost_id, username, hash) VALUES (?, ?, ?)
                   ON CONFLICT (vhost_id, username) DO UPDATE SET hash = excluded.hash')
        ->execute([$v['id'], $user, $hash]);
    vh_render($v);
}

function vh_user_del(array $v, string $user): void
{
    $st = db()->prepare('DELETE FROM auth_users WHERE vhost_id = ? AND username = ?');
    $st->execute([$v['id'], $user]);
    if ($st->rowCount() === 0) {
        vh_fail("Benutzer nicht vorhanden: $user");
    }
    vh_render($v);
}

function vh_ip_add(array $v, string $cidr): void
{
    db()->prepare('INSERT OR IGNORE INTO auth_ips (vhost_id, cidr) VALUES (?, ?)')->execute([$v['id'], $cidr]);
    vh_render($v);
}

function vh_ip_del(array $v, string $cidr): void
{
    $st = db()->prepare('DELETE FROM auth_ips WHERE vhost_id = ? AND cidr = ?');
    $st->execute([$v['id'], $cidr]);
    if ($st->rowCount() === 0) {
        vh_fail("IP nicht vorhanden: $cidr");
    }
    vh_render($v);
}

function vh_ssl(array $v, bool $on): void
{
    if ($v['kind'] !== 'domain') {
        vh_fail('Let\'s Encrypt nur für echte Domains');
    }
    if ($on) {
        $email = setting('le_email');
        if (!$email) {
            vh_fail('Keine Let\'s-Encrypt-E-Mail hinterlegt (Einstellungen / "vhost set le_email ...")');
        }
        $live = cfg()['le_live'] . '/' . $v['name'];
        if (!file_exists("$live/fullchain.pem")) {
            $cmd = sprintf(
                'certbot certonly --webroot -w %s -d %s -n --agree-tos --no-eff-email -m %s --keep-until-expiring 2>&1',
                escapeshellarg(vh_basedir($v)),
                escapeshellarg($v['name']),
                escapeshellarg($email)
            );
            exec($cmd, $out, $rc);
            echo implode("\n", $out), "\n";
            if ($rc !== 0) {
                vh_fail('certbot fehlgeschlagen');
            }
        }
    }
    db()->prepare('UPDATE vhosts SET ssl = ? WHERE id = ?')->execute([(int)$on, $v['id']]);
    vh_render(vh_load($v['name']));
}

function vh_set_setting(string $key, string $value): void
{
    if ($key !== 'le_email') {
        vh_fail("unbekannte Einstellung: $key");
    }
    $value = trim($value);
    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        vh_fail("ungültige E-Mail-Adresse: $value");
    }
    if ($value === '') {
        db()->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
    } else {
        db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                       ON CONFLICT (key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
    }
}

function vh_fix_db_perms(): void
{
    $db = cfg()['db'];
    $dir = dirname($db);
    if (is_dir($dir)) {
        chown($dir, 'www-data');
        chgrp($dir, 'www-data');
        chmod($dir, 0770);
    }
    foreach (glob($db . '*') ?: [] as $f) {
        chown($f, 'www-data');
        chgrp($f, 'www-data');
        chmod($f, 0660);
    }
}
