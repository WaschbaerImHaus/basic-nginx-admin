<?php
declare(strict_types=1);

/**
 * Entität: ein virtueller Host mit seinen Pfaden.
 *
 * Unveränderlich; Zustandsänderungen laufen über das Repository und werden
 * danach neu geladen.
 *
 * Der Konstruktor revalidiert Name, Port und Unterverzeichnis mit denselben
 * Wertobjekten wie bei der Eingabe. Die Oberfläche (www-data) kann die
 * Datenbank nicht mehr beschreiben (siehe VhostService::fixDatabasePermissions()),
 * aber diese Prüfung bleibt als zweite Verteidigungslinie bestehen: eine
 * manipulierte Zeile kann so nie zu unkontrollierten Pfaden oder nginx-Text
 * führen, egal wie sie entstanden ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 22:59
 */

namespace VhostAdmin;

use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\SubDirectory;

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
		$this->assertConsistent();
	}

	/**
	 * Prüft Name, Port und Unterverzeichnis erneut, unabhängig davon, ob sie
	 * gerade eingegeben oder aus der Datenbank gelesen wurden.
	 *
	 * Der Admin-Port der Oberfläche (8080) ist beim erneuten Prüfen eines
	 * bestehenden Datensatzes kein Ausschlussgrund mehr – anders als bei der
	 * Eingabe über Port::fromString() – deshalb prüft diese Methode den
	 * Bereich 1–65535 direkt.
	 *
	 * @throws \RuntimeException wenn ein Wert nicht (mehr) gültig ist
	 */
	private function assertConsistent(): void
	{
		if ($this->kind === VhostKind::Domain) {
			if ($this->port !== null) {
				throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: Domain mit Port ({$this->port})");
			}
			if (!$this->isValidDomainName($this->name)) {
				throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: ungültiger Domainname \"{$this->name}\"");
			}
		} else {
			if ($this->port === null || $this->port < 1 || $this->port > 65535) {
				$port = $this->port === null ? 'null' : (string)$this->port;
				throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: ungültiger Port ($port)");
			}
			if ($this->name !== 'localhost:' . $this->port) {
				throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: Name \"{$this->name}\" passt nicht zum Port {$this->port}");
			}
		}
		if ($this->subdir !== null && !$this->isValidSubdir($this->subdir)) {
			throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: ungültiges Unterverzeichnis \"{$this->subdir}\"");
		}
	}

	/**
	 * Prüft, ob $name unverändert aus DomainName::fromString() hervorgeht.
	 */
	private function isValidDomainName(string $name): bool
	{
		try {
			return DomainName::fromString($name)->value === $name;
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Prüft, ob $subdir unverändert aus SubDirectory::fromString() hervorgeht.
	 */
	private function isValidSubdir(string $subdir): bool
	{
		try {
			return SubDirectory::fromString($subdir)?->value === $subdir;
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Baut die Entität aus einer Datenbankzeile.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		$kind = (string)$row['kind'];
		return new self(
			(int)$row['id'],
			(string)$row['name'],
			VhostKind::tryFrom($kind) ?? throw new \RuntimeException("Ungültiger Datensatz in der Datenbank: unbekannte Art \"$kind\""),
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
