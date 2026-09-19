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
 * @version Letzte Änderung: 2026-09-19 09:25
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
			$this->purgeBaseDir($vhost);
		}
		$this->reloader->reload();
	}

	/**
	 * Löscht den Basisordner eines vHosts rekursiv, aber nur, wenn er sich nach
	 * Auflösung aller Symlinks/".." wirklich unterhalb der Web-Wurzel befindet.
	 *
	 * Ist der Basisordner selbst ein Symlink (z.B. weil ein Admin ihn auf einen anderen
	 * vHost umgehängt hat), wird nichts gelöscht: "rm -rf" auf den aufgelösten Pfad
	 * würde sonst das Ziel des Symlinks treffen – etwa den Docroot eines fremden
	 * vHosts oder, zeigt der Symlink auf die Web-Wurzel selbst, die Web-Wurzel
	 * komplett – und den Symlink selbst stehen lassen. Deshalb auch die eigene
	 * Ausnahme dafür, unabhängig vom folgenden Präfixvergleich.
	 *
	 * Ein reiner Präfixvergleich auf dem unaufgelösten Pfad wäre für
	 * "/var/www/../../etc" ebenfalls wahr; deshalb wird hier mit realpath()
	 * aufgelöst. Lässt sich der Basisordner nicht auflösen (existiert nicht),
	 * wird nichts gelöscht. Ebenso wird nicht gelöscht, wenn der aufgelöste
	 * Basisordner mit der aufgelösten Web-Wurzel übereinstimmt statt echt darunter
	 * zu liegen ("$realBase === $realRoot" bestünde den Präfixvergleich sonst auch).
	 *
	 * @throws \RuntimeException wenn der Basisordner ein Symlink ist, oder wenn
	 *         "rm -rf" fehlschlägt
	 */
	private function purgeBaseDir(Vhost $vhost): void
	{
		// Vorläufig lokal instanziiert: Task 4 gibt das Layout in den Konstruktor.
		$base = (new VhostLayout($this->config))->baseDir($vhost);
		if (is_link($base)) {
			throw new \RuntimeException("Basisordner ist ein Symlink und wird nicht automatisch gelöscht: $base");
		}
		$realRoot = realpath($this->config->wwwRoot);
		$realBase = realpath($base);
		if (
			$realRoot === false
			|| $realBase === false
			|| $realBase === $realRoot
			|| !str_starts_with($realBase . '/', $realRoot . '/')
		) {
			return;
		}
		exec('rm -rf ' . escapeshellarg($realBase) . ' 2>&1', $output, $exitCode);
		if ($exitCode !== 0) {
			throw new \RuntimeException("Löschen von $realBase fehlgeschlagen:\n" . implode("\n", $output));
		}
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
			// Vorläufig lokal instanziiert statt injiziert: Task 4 räumt das auf.
			$output = $this->certbot->obtain($vhost->name, (new VhostLayout($this->config))->baseDir($vhost), $email);
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
	 *
	 * Scheitert der Reload (fehlerhafte Konfiguration), werden die drei
	 * Dateien und der Symlink auf ihren Stand vor diesem Aufruf zurückgesetzt,
	 * bevor die Ausnahme weitergeworfen wird. Ohne das bliebe eine kaputte
	 * Datei liegen, und weil "nginx -t" global prüft, würde jeder spätere
	 * Reload scheitern – auch der certbot-Deploy-Hook. Die Rücknahme entfernt
	 * dabei nur, was dieser Aufruf selbst neu angelegt hat: lag an der Stelle von
	 * $enabled vorher schon irgendetwas (Symlink oder reguläre Datei), bleibt es
	 * unangetastet, statt einer fremden Datei zum Opfer zu fallen.
	 *
	 * @throws \RuntimeException wenn der Reloader scheitert (nach Rücknahme)
	 */
	public function render(Vhost $vhost, bool $reload = true): void
	{
		if (!is_dir($this->config->authDir)) {
			mkdir($this->config->authDir, 0750, true);
			$this->group($this->config->authDir);
		}
		$htpasswd = $this->renderer->htpasswdPath($vhost);
		$authSnippet = $this->renderer->authSnippetPath($vhost);
		$available = $this->renderer->serverConfigPath($vhost);
		$enabled = $this->config->sitesEnabled . '/' . $vhost->slug() . '.conf';

		$previousHtpasswd = $this->readIfExists($htpasswd);
		$previousAuthSnippet = $this->readIfExists($authSnippet);
		$previousAvailable = $this->readIfExists($available);
		$symlinkExistedBefore = is_link($enabled);
		$enabledExistedBefore = $symlinkExistedBefore || file_exists($enabled);

		file_put_contents($htpasswd, $this->renderer->htpasswd($this->repository->users($vhost->id)));
		$this->group($htpasswd);
		chmod($htpasswd, 0640);

		file_put_contents($authSnippet, $this->renderer->authSnippet($vhost, $this->repository->ips($vhost->id)));

		file_put_contents($available, $this->renderer->serverConfig($vhost));
		if (!$symlinkExistedBefore) {
			symlink($available, $enabled);
		}

		if (!$reload) {
			return;
		}
		try {
			$this->reloader->reload();
		} catch (\Throwable $e) {
			$this->restoreFile($htpasswd, $previousHtpasswd);
			$this->restoreFile($authSnippet, $previousAuthSnippet);
			$this->restoreFile($available, $previousAvailable);
			if (!$enabledExistedBefore) {
				@unlink($enabled);
			}
			throw $e;
		}
	}

	/**
	 * Inhalt einer Datei, falls sie existiert, sonst null.
	 */
	private function readIfExists(string $path): ?string
	{
		return file_exists($path) ? (string)file_get_contents($path) : null;
	}

	/**
	 * Stellt den vorherigen Inhalt einer Datei wieder her, bzw. löscht sie,
	 * wenn es vorher keine Datei gab.
	 */
	private function restoreFile(string $path, ?string $previousContent): void
	{
		if ($previousContent === null) {
			@unlink($path);
			return;
		}
		file_put_contents($path, $previousContent);
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
	 * Datenbankverzeichnis und -dateien so setzen, dass die Oberfläche
	 * (www-data) nur noch lesen kann: Verzeichnis root:www-data 0750,
	 * Datenbankdatei (und *-journal/*-wal) root:www-data 0640.
	 * Wirkt nur als root; das CLI ruft sie nach jedem Befehl.
	 */
	public function fixDatabasePermissions(): void
	{
		if (!$this->isRoot()) {
			return;
		}
		$dir = dirname($this->config->dbPath);
		if (is_dir($dir)) {
			chown($dir, 'root');
			chgrp($dir, $this->config->wwwGroup);
			chmod($dir, 0750);
		}
		foreach (glob($this->config->dbPath . '*') ?: [] as $file) {
			chown($file, 'root');
			chgrp($file, $this->config->wwwGroup);
			chmod($file, 0640);
		}
	}

	/**
	 * Basisordner und Unterverzeichnisse eines vHosts anlegen und deren Besitzer setzen.
	 *
	 * Der Basisordner liegt direkt unterhalb von wwwRoot, das nur root gehört – dort mkdir()
	 * ruhig rekursiv, falls wwwRoot selbst noch fehlt. Innerhalb des Basisordners (02775,
	 * also für www-data beschreibbar) kann dagegen ein Segment ein von www-data platzierter
	 * Symlink sein; deshalb wird jedes Unterverzeichnis einzeln geprüft und angelegt statt
	 * per rekursivem mkdir() über den ganzen Docroot.
	 */
	private function makeDirectories(Vhost $vhost, ?SubDirectory $subdir): void
	{
		// Noch keine Konstruktor-Injektion: bis Task 4 wird das Layout hier lokal gebaut.
		$base = (new VhostLayout($this->config))->baseDir($vhost);
		if (is_link($base)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $base");
		}
		if (!is_dir($base) && !mkdir($base, 0775, true)) {
			throw new \RuntimeException("Kann $base nicht anlegen");
		}
		$this->own($base, 02775);
		$path = $base;
		foreach ($subdir?->segments() ?? [] as $segment) {
			$path .= '/' . $segment;
			$this->makeDirectory($path);
			$this->own($path, 02775);
		}
	}

	/**
	 * Legt ein einzelnes Unterverzeichnis innerhalb des Basisordners an, falls es fehlt.
	 *
	 * @throws \RuntimeException wenn der Pfad ein Symlink ist oder sich nicht anlegen lässt
	 */
	private function makeDirectory(string $path): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
		if (!is_dir($path) && !mkdir($path, 0775)) {
			throw new \RuntimeException("Kann $path nicht anlegen");
		}
	}

	/**
	 * Startseite aus der Vorlage schreiben, falls im Docroot noch keine existiert.
	 *
	 * Ein Symlink gilt dabei ebenfalls als "existiert schon" und wird nicht
	 * überschrieben – file_exists() folgt Symlinks, is_link() nicht.
	 */
	private function writeIndex(Vhost $vhost): void
	{
		// vorläufig ohne web/: stellt Task 3/4 um
		$docroot = (new VhostLayout($this->config))->baseDir($vhost) . ($vhost->subdir !== null ? '/' . $vhost->subdir : '');
		$file = $docroot . '/index.html';
		if (is_link($file) || file_exists($file)) {
			return;
		}
		$html = strtr((string)file_get_contents($this->config->templatePath), [
			'{{NAME}}' => htmlspecialchars($vhost->name, ENT_QUOTES, 'UTF-8'),
			'{{DOCROOT}}' => htmlspecialchars($docroot, ENT_QUOTES, 'UTF-8'),
		]);
		file_put_contents($file, $html);
		$this->own($file, 0664);
	}

	/**
	 * Besitzer/Gruppe/Rechte setzen; ohne root nur die Rechte.
	 *
	 * @throws \RuntimeException wenn $path ein Symlink ist (gehört dort nicht hin;
	 *         www-data könnte ihn auf z.B. /etc/cron.d gelegt haben)
	 */
	private function own(string $path, int $mode): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
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
