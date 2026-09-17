<?php
declare(strict_types=1);

/**
 * Entität: ein virtueller Host mit seinen Pfaden.
 *
 * Unveränderlich; Zustandsänderungen laufen über das Repository und werden
 * danach neu geladen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:30
 */

namespace VhostAdmin;

final class Vhost
{
	/**
	 * @param ?int      $id        Datenbank-ID (null vor dem Speichern)
	 * @param string    $name      "example.com" oder "localhost:3000"
	 * @param VhostKind $kind      Domain oder Localhost
	 * @param ?int      $port      nur bei Localhost gesetzt
	 * @param ?string   $subdir    optionales Unterverzeichnis als Docroot
	 * @param bool      $protect   Verzeichnisschutz aktiv
	 * @param bool      $ssl       Let's-Encrypt-Zertifikat aktiv (nur Domain)
	 * @param ?string   $createdAt Zeitstempel aus der Datenbank (UTC)
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly VhostKind $kind,
		public readonly ?int $port,
		public readonly ?string $subdir,
		public readonly bool $protect,
		public readonly bool $ssl,
		public readonly ?string $createdAt = null,
	) {
	}

	/**
	 * Baut die Entität aus einer Datenbankzeile.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int)$row['id'],
			(string)$row['name'],
			VhostKind::from((string)$row['kind']),
			$row['port'] === null ? null : (int)$row['port'],
			$row['subdir'] === null || $row['subdir'] === '' ? null : (string)$row['subdir'],
			(bool)$row['protect'],
			(bool)$row['ssl'],
			$row['created_at'] === null ? null : (string)$row['created_at'],
		);
	}

	/**
	 * Nur lokal erreichbar (bindet an 127.0.0.1)?
	 */
	public function isLocal(): bool
	{
		return $this->kind === VhostKind::Localhost;
	}

	/**
	 * Dateisystem- und Konfigurationsname: Domain unverändert, sonst localhost-<port>.
	 */
	public function slug(): string
	{
		return $this->isLocal() ? 'localhost-' . $this->port : $this->name;
	}

	/**
	 * Basisordner unterhalb der Web-Wurzel (auch Webroot für ACME-Challenges).
	 */
	public function baseDir(Config $config): string
	{
		return $config->wwwRoot . '/' . $this->slug();
	}

	/**
	 * Tatsächlicher Docroot (Basisordner plus optionales Unterverzeichnis).
	 */
	public function docroot(Config $config): string
	{
		return $this->baseDir($config) . ($this->subdir !== null ? '/' . $this->subdir : '');
	}
}
