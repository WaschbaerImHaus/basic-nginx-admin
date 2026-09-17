<?php
declare(strict_types=1);

/**
 * Anwendungsfälle der vHost-Verwaltung.
 *
 * Verbindet Repository, Dateisystem, nginx-Renderer, Reloader und certbot.
 * Jede Änderung endet mit dem Neuschreiben der nginx-Dateien und genau einem
 * Reload. Besitzerwechsel geschehen nur als root (im CLI), Tests laufen ohne.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:10
 */

namespace VhostAdmin;

use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\ReloaderInterface;
use VhostAdmin\Ssl\CertbotInterface;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;

final class VhostService
{
	private const SETTING_EMAIL = 'le_email';

	/**
	 * Übernimmt Konfiguration, Repository, Renderer, Reloader und certbot-Client.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly ConfigRenderer $renderer,
		private readonly ReloaderInterface $reloader,
		private readonly CertbotInterface $certbot,
	) {
	}

	/**
	 * vHost nach Name laden.
	 *
	 * @throws \RuntimeException wenn unbekannt
	 */
	public function load(string $name): Vhost
	{
		return $this->repository->byName($name) ?? throw new \RuntimeException("Unbekannter vHost: $name");
	}

	/**
	 * Öffentliche Domain anlegen (Docroot /var/www/<domain>[/<subdir>]).
	 */
	public function createDomain(DomainName $domain, ?SubDirectory $subdir, bool $protect = true): Vhost
	{
		return $this->create($domain->value, VhostKind::Domain, null, $subdir, $protect);
	}

	/**
	 * Nur lokal erreichbaren Host anlegen (Docroot /var/www/localhost-<port>[/<subdir>]).
	 */
	public function createLocal(Port $port, ?SubDirectory $subdir, bool $protect = true): Vhost
	{
		return $this->create('localhost:' . $port->value, VhostKind::Localhost, $port->value, $subdir, $protect);
	}

	/**
	 * Gemeinsame Anlage von Domain und Localhost: Datenbankeintrag, Verzeichnisse,
	 * Startseite und nginx-Konfiguration mit Reload.
	 */
	private function create(string $name, VhostKind $kind, ?int $port, ?SubDirectory $subdir, bool $protect): Vhost
	{
		$vhost = $this->repository->insert($name, $kind, $port, $subdir?->value, $protect);
		$this->makeDirectories($vhost, $subdir);
		$this->writeIndex($vhost);
		$this->render($vhost);
		return $vhost;
	}

	/**
	 * vHost entfernen: nginx-Dateien und Datenbankeintrag; Dateien nur mit $purge.
	 */
	public function remove(Vhost $vhost, bool $purge = false): void
	{
		$files = [
			$this->config->sitesEnabled . '/' . $vhost->slug() . '.conf',
			$this->renderer->serverConfigPath($vhost),
			$this->renderer->authSnippetPath($vhost),
			$this->renderer->htpasswdPath($vhost),
		];
		foreach ($files as $file) {
			if (is_link($file) || file_exists($file)) {
				unlink($file);
			}
		}
		$this->repository->delete($vhost->id);
		if ($purge) {
			$base = $vhost->baseDir($this->config);
			if (str_starts_with($base, $this->config->wwwRoot . '/') && is_dir($base)) {
				exec('rm -rf ' . escapeshellarg($base));
			}
		}
		$this->reloader->reload();
	}

	/**
	 * Verzeichnisschutz ein- oder ausschalten.
	 */
	public function setProtection(Vhost $vhost, bool $on): void
	{
		$this->repository->setProtect($vhost->id, $on);
		$this->render($this->load($vhost->name));
	}

	/**
	 * Benutzer anlegen oder Passwort setzen (SHA-512-crypt, von nginx lesbar).
	 *
	 * @throws \RuntimeException bei leerem Passwort
	 */
	public function addUser(Vhost $vhost, Username $user, string $password): void
	{
		if ($password === '') {
			throw new \RuntimeException('Leeres Passwort');
		}
		$this->repository->upsertUser($vhost->id, $user->value, $this->hashPassword($password));
		$this->render($vhost);
	}

	/**
	 * Benutzer entfernen.
	 *
	 * @throws \RuntimeException wenn der Benutzer nicht existiert
	 */
	public function removeUser(Vhost $vhost, Username $user): void
	{
		if (!$this->repository->deleteUser($vhost->id, $user->value)) {
			throw new \RuntimeException("Benutzer nicht vorhanden: {$user->value}");
		}
		$this->render($vhost);
	}

	/**
	 * IP oder Netz ohne Login freigeben.
	 */
	public function addIp(Vhost $vhost, Cidr $cidr): void
	{
		$this->repository->addIp($vhost->id, $cidr->value);
		$this->render($vhost);
	}

	/**
	 * Freigabe entfernen.
	 *
	 * @throws \RuntimeException wenn die Freigabe nicht existiert
	 */
	public function removeIp(Vhost $vhost, Cidr $cidr): void
	{
		if (!$this->repository->deleteIp($vhost->id, $cidr->value)) {
			throw new \RuntimeException("IP nicht vorhanden: {$cidr->value}");
		}
		$this->render($vhost);
	}

	/**
	 * Zertifikat beschaffen (falls noch keins liegt) und HTTPS einschalten.
	 *
	 * @return string Ausgabe von certbot, leer wenn das Zertifikat schon vorhanden war
	 * @throws \RuntimeException bei localhost, fehlender E-Mail oder certbot-Fehler
	 */
	public function enableSsl(Vhost $vhost): string
	{
		if ($vhost->isLocal()) {
			throw new \RuntimeException("Let's Encrypt nur für echte Domains");
		}
		$email = $this->letsEncryptEmail()
			?? throw new \RuntimeException("Keine Let's-Encrypt-E-Mail hinterlegt (Einstellungen / \"vhost set le_email ...\")");
		$output = '';
		if (!file_exists($this->config->letsEncryptLive . '/' . $vhost->name . '/fullchain.pem')) {
			$output = $this->certbot->obtain($vhost->name, $vhost->baseDir($this->config), $email);
		}
		$this->repository->setSsl($vhost->id, true);
		$this->render($this->load($vhost->name));
		return $output;
	}

	/**
	 * HTTPS abschalten; das Zertifikat bleibt für ein späteres Wiedereinschalten liegen.
	 */
	public function disableSsl(Vhost $vhost): void
	{
		$this->repository->setSsl($vhost->id, false);
		$this->render($this->load($vhost->name));
	}

	/**
	 * Registrierungsadresse für Let's Encrypt setzen; leer löscht sie.
	 *
	 * @throws \InvalidArgumentException bei ungültiger Adresse
	 */
	public function setLetsEncryptEmail(string $email): void
	{
		$email = trim($email);
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new \InvalidArgumentException("Ungültige E-Mail-Adresse: $email");
		}
		$this->repository->setSetting(self::SETTING_EMAIL, $email);
	}

	/**
	 * Hinterlegte Let's-Encrypt-Registrierungsadresse, falls gesetzt.
	 */
	public function letsEncryptEmail(): ?string
	{
		return $this->repository->setting(self::SETTING_EMAIL);
	}

	/**
	 * htpasswd, Auth-Snippet und Server-Konfiguration schreiben, Symlink setzen, optional neu laden.
	 */
	public function render(Vhost $vhost, bool $reload = true): void
	{
		if (!is_dir($this->config->authDir)) {
			mkdir($this->config->authDir, 0750, true);
			$this->group($this->config->authDir);
		}
		$htpasswd = $this->renderer->htpasswdPath($vhost);
		file_put_contents($htpasswd, $this->renderer->htpasswd($this->repository->users($vhost->id)));
		$this->group($htpasswd);
		chmod($htpasswd, 0640);

		file_put_contents($this->renderer->authSnippetPath($vhost), $this->renderer->authSnippet($vhost, $this->repository->ips($vhost->id)));

		$available = $this->renderer->serverConfigPath($vhost);
		file_put_contents($available, $this->renderer->serverConfig($vhost));
		$enabled = $this->config->sitesEnabled . '/' . $vhost->slug() . '.conf';
		if (!is_link($enabled)) {
			symlink($available, $enabled);
		}
		if ($reload) {
			$this->reloader->reload();
		}
	}

	/**
	 * Alle vHosts neu schreiben und einmal neu laden (Installation, Umzug).
	 */
	public function renderAll(): void
	{
		foreach ($this->repository->all() as $vhost) {
			$this->render($vhost, false);
		}
		$this->reloader->reload();
	}

	/**
	 * Datenbankdatei und -verzeichnis für die Oberfläche (www-data) lesbar machen.
	 * Wirkt nur als root; das CLI ruft sie nach jedem Befehl.
	 */
	public function fixDatabasePermissions(): void
	{
		if (!$this->isRoot()) {
			return;
		}
		$dir = dirname($this->config->dbPath);
		if (is_dir($dir)) {
			chown($dir, $this->config->wwwGroup);
			chgrp($dir, $this->config->wwwGroup);
			chmod($dir, 0770);
		}
		foreach (glob($this->config->dbPath . '*') ?: [] as $file) {
			chown($file, $this->config->wwwGroup);
			chgrp($file, $this->config->wwwGroup);
			chmod($file, 0660);
		}
	}

	/**
	 * Basisordner und Unterverzeichnisse eines vHosts anlegen und deren Besitzer setzen.
	 */
	private function makeDirectories(Vhost $vhost, ?SubDirectory $subdir): void
	{
		$docroot = $vhost->docroot($this->config);
		if (!is_dir($docroot) && !mkdir($docroot, 0775, true)) {
			throw new \RuntimeException("Kann $docroot nicht anlegen");
		}
		$path = $vhost->baseDir($this->config);
		$this->own($path, 02775);
		foreach ($subdir?->segments() ?? [] as $segment) {
			$path .= '/' . $segment;
			$this->own($path, 02775);
		}
	}

	/**
	 * Startseite aus der Vorlage schreiben, falls im Docroot noch keine existiert.
	 */
	private function writeIndex(Vhost $vhost): void
	{
		$file = $vhost->docroot($this->config) . '/index.html';
		if (file_exists($file)) {
			return;
		}
		$html = strtr((string)file_get_contents($this->config->templatePath), [
			'{{NAME}}' => htmlspecialchars($vhost->name, ENT_QUOTES, 'UTF-8'),
			'{{DOCROOT}}' => htmlspecialchars($vhost->docroot($this->config), ENT_QUOTES, 'UTF-8'),
		]);
		file_put_contents($file, $html);
		$this->own($file, 0664);
	}

	/**
	 * Besitzer/Gruppe/Rechte setzen; ohne root nur die Rechte.
	 */
	private function own(string $path, int $mode): void
	{
		if ($this->isRoot()) {
			if (!@chown($path, $this->config->wwwOwner)) {
				chown($path, $this->config->wwwGroup);
			}
			chgrp($path, $this->config->wwwGroup);
		}
		chmod($path, $mode);
	}

	/**
	 * Gruppe setzen; ohne root ein No-op.
	 */
	private function group(string $path): void
	{
		if ($this->isRoot()) {
			chgrp($path, $this->config->wwwGroup);
		}
	}

	/**
	 * Prüft, ob der laufende Prozess mit root-Rechten läuft.
	 */
	private function isRoot(): bool
	{
		return function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
	}

	/**
	 * SHA-512-crypt-Hash, den nginx (libxcrypt) auswerten kann.
	 */
	private function hashPassword(string $password): string
	{
		return crypt($password, '$6$' . substr(bin2hex(random_bytes(12)), 0, 16) . '$');
	}
}
