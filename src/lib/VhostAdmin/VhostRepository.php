<?php
declare(strict_types=1);

/**
 * Persistenz für vHosts, Schutz-Benutzer, freigegebene IPs und Einstellungen.
 *
 * Einziger Ort mit SQL. Liefert immer Vhost-Objekte, nie rohe Zeilen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 20:20
 */

namespace VhostAdmin;

use VhostAdmin\Value\ProtectPath;

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
	 * Wie all(), aber eine einzelne ungültige Zeile hält den Rest nicht auf: Sie steht
	 * mit ihrem Fehler in "broken". Für die Oberfläche – eine manipulierte Zeile soll
	 * nicht die ganze Übersicht mit HTTP 500 lahmlegen. Das CLI bleibt bei all() und
	 * bricht ab, bevor es mit einem ungültigen Datensatz rendert.
	 *
	 * @return array{vhosts: list<Vhost>, broken: list<string>}
	 */
	public function allReadable(): array
	{
		$vhosts = $broken = [];
		foreach ($this->db->pdo()->query('SELECT * FROM vhosts ORDER BY kind, name')->fetchAll() as $row) {
			try {
				$vhosts[] = Vhost::fromRow($row);
			} catch (\RuntimeException $e) {
				$broken[] = (string)($row['name'] ?? '?') . ': ' . $e->getMessage();
			}
		}
		return ['vhosts' => $vhosts, 'broken' => $broken];
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
		// www_mode ausdrücklich mitgeben: Eine Datenbank aus einer früheren Fassung hat
		// als Spaltenstandard noch "none", das es nicht mehr gibt.
		$this->db->pdo()
			->prepare('INSERT INTO vhosts (name, kind, port, subdir, protect, www_mode) VALUES (?, ?, ?, ?, ?, ?)')
			->execute([$name, $kind->value, $port, $subdir, (int)$protect, Vhost::WWW_DEFAULT]);
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
	 * PHP-Kennzeichen setzen.
	 */
	public function setPhp(int $id, bool $on): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET php = ? WHERE id = ?')->execute([(int)$on, $id]);
	}

	/**
	 * www-Umgang setzen: "none", "www" oder "bare".
	 */
	public function setWwwMode(int $id, string $mode): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET www_mode = ? WHERE id = ?')->execute([$mode, $id]);
	}

	/**
	 * Docroot-Unterordner setzen (null = Docroot ist web/ selbst).
	 */
	public function setSubdir(int $id, ?string $subdir): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET subdir = ? WHERE id = ?')->execute([$subdir, $id]);
	}

	/**
	 * Zeitpunkt des angestossenen Entfernens setzen (null = zurückholen).
	 */
	public function setDeletedAt(int $id, ?string $when): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET deleted_at = ? WHERE id = ?')->execute([$when, $id]);
	}

	/**
	 * vHosts, deren Schonfrist abgelaufen ist.
	 *
	 * @param string $cutoff UTC-Zeitstempel "Y-m-d H:i:s"; alles davor ist fällig
	 * @return list<Vhost>
	 */
	public function dueForDeletion(string $cutoff): array
	{
		$st = $this->db->pdo()->prepare('SELECT * FROM vhosts WHERE deleted_at IS NOT NULL AND deleted_at <= ? ORDER BY name');
		$st->execute([$cutoff]);
		return array_map(static fn(array $row): Vhost => Vhost::fromRow($row), $st->fetchAll());
	}

	/**
	 * Kennung für den ACME-Marker setzen.
	 */
	public function setHealthToken(int $id, string $token): void
	{
		$this->db->pdo()->prepare('UPDATE vhosts SET health_token = ? WHERE id = ?')->execute([$token, $id]);
	}

	/**
	 * Führt $work in einer Transaktion aus: Wirft sie, wird alles zurückgenommen.
	 * Verschachtelt aufgerufen läuft $work in der äusseren Transaktion mit.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function transaction(callable $work): mixed
	{
		$pdo = $this->db->pdo();
		if ($pdo->inTransaction()) {
			return $work();
		}
		$pdo->beginTransaction();
		try {
			$result = $work();
			$pdo->commit();
			return $result;
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Schutz-Benutzer eines vHosts mit ihrem Pfad (null = ganze Seite), alphabetisch.
	 *
	 * Der Pfad landet als Ausdruck in der nginx-Konfiguration. Auch ein Wert aus der
	 * Datenbank muss deshalb dieselbe Prüfung bestehen wie eine Eingabe – wer die
	 * Datenbank verändern kann, soll darüber keine Direktiven einschleusen.
	 *
	 * @return list<array{username: string, hash: string, path: ?string}>
	 * @throws \RuntimeException bei einem ungültigen Pfad in der Datenbank
	 */
	public function users(int $vhostId): array
	{
		$st = $this->db->pdo()->prepare('SELECT username, hash, path FROM auth_users WHERE vhost_id = ? ORDER BY username');
		$st->execute([$vhostId]);
		$users = [];
		foreach ($st->fetchAll() as $row) {
			$path = $row['path'] === null || $row['path'] === '' ? null : (string)$row['path'];
			if ($path !== null) {
				try {
					$checked = ProtectPath::fromString($path);
				} catch (\InvalidArgumentException $e) {
					throw new \RuntimeException('Ungültiger Datensatz in der Datenbank: ' . $e->getMessage());
				}
				if ($checked === null || $checked->value !== $path) {
					throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: Schutzpfad \"$path\"");
				}
			}
			$users[] = ['username' => (string)$row['username'], 'hash' => (string)$row['hash'], 'path' => $path];
		}
		return $users;
	}

	/**
	 * Benutzer anlegen oder dessen Passwort-Hash ersetzen. Der Pfad gilt nur beim
	 * Anlegen; ein neues Passwort lässt den Pfad eines bestehenden Benutzers stehen.
	 */
	public function upsertUser(int $vhostId, string $username, string $hash, ?string $path = null): void
	{
		$this->db->pdo()->prepare(
			'INSERT INTO auth_users (vhost_id, username, hash, path) VALUES (?, ?, ?, ?)
			ON CONFLICT (vhost_id, username) DO UPDATE SET hash = excluded.hash'
		)->execute([$vhostId, $username, $hash, $path]);
	}

	/**
	 * Pfad eines Benutzers setzen (null = ganze Seite); false, wenn es ihn nicht gibt.
	 */
	public function setUserPath(int $vhostId, string $username, ?string $path): bool
	{
		$st = $this->db->pdo()->prepare('UPDATE auth_users SET path = ? WHERE vhost_id = ? AND username = ?');
		$st->execute([$path, $vhostId, $username]);
		return $st->rowCount() > 0;
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
