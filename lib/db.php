<?php
declare(strict_types=1);

function cfg(): array
{
    static $c;
    return $c ??= require __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo;
    if ($pdo) {
        return $pdo;
    }
    $path = cfg()['db'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function db_init(): void
{
    db()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS vhosts (
    id         INTEGER PRIMARY KEY,
    name       TEXT NOT NULL UNIQUE,
    kind       TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')),
    port       INTEGER,
    subdir     TEXT,
    protect    INTEGER NOT NULL DEFAULT 1,
    ssl        INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS auth_users (
    id       INTEGER PRIMARY KEY,
    vhost_id INTEGER NOT NULL REFERENCES vhosts(id) ON DELETE CASCADE,
    username TEXT NOT NULL,
    hash     TEXT NOT NULL,
    UNIQUE (vhost_id, username)
);
CREATE TABLE IF NOT EXISTS auth_ips (
    id       INTEGER PRIMARY KEY,
    vhost_id INTEGER NOT NULL REFERENCES vhosts(id) ON DELETE CASCADE,
    cidr     TEXT NOT NULL,
    UNIQUE (vhost_id, cidr)
);
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);
}

function setting(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function vhost_all(): array
{
    return db()->query('SELECT * FROM vhosts ORDER BY kind, name')->fetchAll();
}

function vhost_by_name(string $name): ?array
{
    $st = db()->prepare('SELECT * FROM vhosts WHERE name = ?');
    $st->execute([$name]);
    return $st->fetch() ?: null;
}

function vhost_users(int $id): array
{
    $st = db()->prepare('SELECT username, hash FROM auth_users WHERE vhost_id = ? ORDER BY username');
    $st->execute([$id]);
    return $st->fetchAll();
}

function vhost_ips(int $id): array
{
    $st = db()->prepare('SELECT cidr FROM auth_ips WHERE vhost_id = ? ORDER BY cidr');
    $st->execute([$id]);
    return $st->fetchAll();
}

function vh_slug(array $v): string
{
    return $v['kind'] === 'localhost' ? 'localhost-' . $v['port'] : $v['name'];
}

function vh_basedir(array $v): string
{
    return cfg()['www_root'] . '/' . vh_slug($v);
}

function vh_docroot(array $v): string
{
    return vh_basedir($v) . ($v['subdir'] ? '/' . $v['subdir'] : '');
}
