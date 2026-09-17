<?php
declare(strict_types=1);

/**
 * SQLite-Verbindung und Schema.
 *
 * Die Verbindung wird erst beim ersten Zugriff geöffnet. Fremdschlüssel sind
 * aktiv, damit Benutzer und IPs beim Löschen eines vHosts mitgelöscht werden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:35
 */

namespace VhostAdmin;

final class Database
{
	private ?\PDO $pdo = null;

	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Liefert die PDO-Verbindung; legt das Datenbankverzeichnis bei Bedarf an.
	 */
	public function pdo(): \PDO
	{
		if ($this->pdo === null) {
			$dir = dirname($this->config->dbPath);
			if (!is_dir($dir)) {
				mkdir($dir, 0770, true);
			}
			$this->pdo = new \PDO('sqlite:' . $this->config->dbPath);
			$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
			$this->pdo->exec('PRAGMA foreign_keys = ON');
		}
		return $this->pdo;
	}

	/**
	 * Legt alle Tabellen an, falls sie fehlen (mehrfach aufrufbar).
	 */
	public function initSchema(): void
	{
		$this->pdo()->exec(<<<SQL
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
}
