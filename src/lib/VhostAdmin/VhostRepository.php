<?php
declare(strict_types=1);

/**
 * Persistenz für vHosts, Schutz-Benutzer, freigegebene IPs und Einstellungen.
 *
 * Einziger Ort mit SQL. Liefert immer Vhost-Objekte, nie rohe Zeilen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:40
 */

namespace VhostAdmin;

final class VhostRepository
{
	/**
	 * Repository über der geöffneten Datenbankverbindung.
	 */
	public function __construct(private readonly Database $db)
	{
	}

	/**
	 * Alle vHosts, Domains zuerst, dann localhost, jeweils alphabetisch.
	 *
	 * @return list<Vhost>
	 */
	public function all(): array
	{
		$rows = $this->db->pdo()->query('SELECT * FROM vhosts ORDER BY kind, name')->fetchAll();
		return array_map(Vhost::fromRow(...), $rows);
	}

	/**
	 * vHost nach Name ("example.com" oder "localhost:3000").
	 */
	public function byName(string $name): ?Vhost
	{
		$st = $this->db->pdo()->prepare('SELECT * FROM vhosts WHERE name = ?');
		$st->execute([$name]);
		$row = $st->fetch();
		return $row === false ? null : Vhost::fromRow($row);
	}

	/**
	 * vHost nach Datenbank-ID.
	 */
	public function byId(int $id): ?Vhost
	{
		$st = $this->db->pdo()->prepare('SELECT * FROM vhosts WHERE id = ?');
		$st->execute([$id]);
		$row = $st->fetch();
		return $row === false ? null : Vhost::fromRow($row);
	}

	/**
	 * Legt einen vHost an und liefert ihn mit ID und Zeitstempel zurück.
	 *
	 * @throws \RuntimeException wenn der Name schon vergeben ist
	 */
	public function insert(string $name, VhostKind $kind, ?int $port, ?string $subdir, bool $protect): Vhost
	{
		if ($this->byName($name) !== null) {
			throw new \RuntimeException("Existiert bereits: $name");
		}
		$this->db->pdo()->prepare('INSERT INTO vhosts (name, kind, port, subdir, protect) VALUES (?, ?, ?, ?, ?)')
			->execute([$name, $kind->value, $port, $subdir, (int)$protect]);
		return $this->byId((int)$this->db->pdo()->lastInsertId());
	}

	/**
	 * Löscht den vHost; Benutzer und IPs werden per Fremdschlüssel mitgelöscht.
	 */
	public function delete(int $id): void
	{
		$this->db->pdo()->prepare('DELETE FROM vhosts WHERE id = ?')->execute([$id]);
	}

	/**
	 * Verzeichnisschutz ein- oder ausschalten.
	 */
	public function setProtect(int $id, bool $on): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET protect = ? WHERE id = ?')->execute([(int)$on, $id]);
	}

	/**
	 * HTTPS-Kennzeichen setzen.
	 */
	public function setSsl(int $id, bool $on): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET ssl = ? WHERE id = ?')->execute([(int)$on, $id]);
	}

	/**
	 * Schutz-Benutzer eines vHosts, alphabetisch.
	 *
	 * @return list<array{username: string, hash: string}>
	 */
	public function users(int $vhostId): array
	{
		$st = $this->db->pdo()->prepare('SELECT username, hash FROM auth_users WHERE vhost_id = ? ORDER BY username');
		$st->execute([$vhostId]);
		return $st->fetchAll();
	}

	/**
	 * Benutzer anlegen oder dessen Passwort-Hash ersetzen.
	 */
	public function upsertUser(int $vhostId, string $username, string $hash): void
	{
		$this->db->pdo()->prepare(
			'INSERT INTO auth_users (vhost_id, username, hash) VALUES (?, ?, ?)
			ON CONFLICT (vhost_id, username) DO UPDATE SET hash = excluded.hash'
		)->execute([$vhostId, $username, $hash]);
	}

	/**
	 * Benutzer entfernen; false, wenn es ihn nicht gab.
	 */
	public function deleteUser(int $vhostId, string $username): bool
	{
		$st = $this->db->pdo()->prepare('DELETE FROM auth_users WHERE vhost_id = ? AND username = ?');
		$st->execute([$vhostId, $username]);
		return $st->rowCount() > 0;
	}

	/**
	 * Freigegebene IPs/Netze eines vHosts, sortiert.
	 *
	 * @return list<string>
	 */
	public function ips(int $vhostId): array
	{
		$st = $this->db->pdo()->prepare('SELECT cidr FROM auth_ips WHERE vhost_id = ? ORDER BY cidr');
		$st->execute([$vhostId]);
		return $st->fetchAll(\PDO::FETCH_COLUMN);
	}

	/**
	 * IP/Netz freigeben; Duplikate werden stillschweigend ignoriert.
	 */
	public function addIp(int $vhostId, string $cidr): void
	{
		$this->db->pdo()->prepare('INSERT OR IGNORE INTO auth_ips (vhost_id, cidr) VALUES (?, ?)')->execute([$vhostId, $cidr]);
	}

	/**
	 * Freigabe entfernen; false, wenn es sie nicht gab.
	 */
	public function deleteIp(int $vhostId, string $cidr): bool
	{
		$st = $this->db->pdo()->prepare('DELETE FROM auth_ips WHERE vhost_id = ? AND cidr = ?');
		$st->execute([$vhostId, $cidr]);
		return $st->rowCount() > 0;
	}

	/**
	 * Einstellung lesen (z. B. le_email).
	 */
	public function setting(string $key): ?string
	{
		$st = $this->db->pdo()->prepare('SELECT value FROM settings WHERE key = ?');
		$st->execute([$key]);
		$value = $st->fetchColumn();
		return $value === false ? null : (string)$value;
	}

	/**
	 * Einstellung schreiben; null oder leer löscht sie.
	 */
	public function setSetting(string $key, ?string $value): void
	{
		if ($value === null || $value === '') {
			$this->db->pdo()->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
			return;
		}
		$this->db->pdo()->prepare(
			'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value'
		)->execute([$key, $value]);
	}
}
