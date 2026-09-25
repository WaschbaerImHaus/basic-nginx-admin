<?php
declare(strict_types=1);

/**
 * SQLite-Verbindung und Schema.
 *
 * Die Verbindung wird erst beim ersten Zugriff geöffnet. Fremdschlüssel sind
 * aktiv, damit Benutzer und IPs beim Löschen eines vHosts mitgelöscht werden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 20:20
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
		$this->migrateSchema();
		$this->pdo()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS vhosts (
	id         INTEGER PRIMARY KEY,
	name       TEXT NOT NULL UNIQUE,
	kind       TEXT NOT NULL CHECK (kind IN ('domain', 'localhost')),
	port       INTEGER,
	subdir     TEXT,
	protect    INTEGER NOT NULL DEFAULT 1,
	protect_path TEXT,
	ssl        INTEGER NOT NULL DEFAULT 0,
	php        INTEGER NOT NULL DEFAULT 0,
	health_token TEXT,
	www_mode   TEXT NOT NULL DEFAULT 'bare',
	deleted_at TEXT,
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
	/**
	 * Zieht Spalten nach, die in älteren Fassungen des Schemas noch fehlten.
	 *
	 * "CREATE TABLE IF NOT EXISTS" lässt eine bestehende Tabelle unverändert – eine
	 * Datenbank aus einer früheren Version bekäme die Spalte sonst nie. Läuft vor dem
	 * CREATE, weil eine noch gar nicht existierende Tabelle hier einfach übersprungen
	 * wird (PRAGMA liefert dann eine leere Liste).
	 */
	private function migrateSchema(): void
	{
		$tables = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'vhosts'")->fetchAll();
		if ($tables === []) {
			return;
		}
		$columns = array_column($this->pdo()->query('PRAGMA table_info(vhosts)')->fetchAll(), 'name');
		if (!in_array('php', $columns, true)) {
			$this->pdo()->exec('ALTER TABLE vhosts ADD COLUMN php INTEGER NOT NULL DEFAULT 0');
		}
		if (!in_array('www_mode', $columns, true)) {
			// bare = auf <domain> umleiten (Standard), www = auf www.<domain>.
			$this->pdo()->exec("ALTER TABLE vhosts ADD COLUMN www_mode TEXT NOT NULL DEFAULT 'bare'");
		} else {
			// Eine frühere Fassung kannte "none" (keine Umleitung). Das ist keine
			// Einstellung mehr: Einer der beiden Namen liefert aus, der andere leitet dorthin.
			$this->pdo()->exec("UPDATE vhosts SET www_mode = 'bare' WHERE www_mode NOT IN ('bare', 'www')");
		}
		if (!in_array('deleted_at', $columns, true)) {
			// Zeitpunkt, zu dem das Entfernen angestossen wurde; bis zum Ablauf der
			// Schonfrist bleibt der Eintrag bestehen und lässt sich zurückholen.
			$this->pdo()->exec('ALTER TABLE vhosts ADD COLUMN deleted_at TEXT');
		}
		if (!in_array('protect_path', $columns, true)) {
			// Ohne Standardwert: NULL heisst ganze Seite, wie bisher.
			$this->pdo()->exec('ALTER TABLE vhosts ADD COLUMN protect_path TEXT');
		}
		if (!in_array('health_token', $columns, true)) {
			// Ohne Standardwert: der Wert wird je vHost beim ersten Schreiben der
			// nginx-Dateien erzeugt (VhostService::ensureHealthMarker()).
			$this->pdo()->exec('ALTER TABLE vhosts ADD COLUMN health_token TEXT');
		}
	}
}
