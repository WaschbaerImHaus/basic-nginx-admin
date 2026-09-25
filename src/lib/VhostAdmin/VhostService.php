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
 * @version Letzte Änderung: 2026-09-20 15:25
 */

namespace VhostAdmin;

use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Nginx\ReloaderInterface;
use VhostAdmin\Ssl\CertbotInterface;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\Ssl\ReachabilityResult;
use VhostAdmin\Ssl\ReachabilityStatus;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Php\FpmReloaderInterface;
use VhostAdmin\Php\PoolRenderer;
use VhostAdmin\Php\SystemUsersInterface;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;

final class VhostService
{
	/** Mindestlänge für Passwörter, die über das CLI gesetzt werden. */
	public const MIN_PASSWORD_LENGTH = 12;

	private const SETTING_EMAIL = 'le_email';
	private const SETTING_HSTS = 'hsts';

	/** Hinweis aus dem letzten enableSsl(), wenn der Nebenname nicht mitbeantragt wurde. */
	private string $lastAliasNote = '';

	/**
	 * Übernimmt Konfiguration, Repository, Renderer, Reloader, certbot-Client und Layout.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly ConfigRenderer $renderer,
		private readonly ReloaderInterface $reloader,
		private readonly CertbotInterface $certbot,
		private readonly VhostLayout $layout,
		private readonly PoolRenderer $poolRenderer,
		private readonly FpmReloaderInterface $fpmReloader,
		private readonly SystemUsersInterface $systemUsers,
		private readonly ReachabilityChecker $reachabilityChecker,
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
		$this->makeDirectories($vhost);
		$this->writeIndex($vhost);
		$this->render($vhost);
		return $vhost;
	}

	/**
	 * vHost entfernen: nginx-Dateien und Datenbankeintrag; Dateien nur mit $purge.
	 */
	public function remove(Vhost $vhost, bool $purge = false): void
	{
		// Zuerst PHP abschalten: sonst bliebe die Pool-Datei liegen und php-fpm hielte
		// einen Pool samt Socket für einen vHost vor, den es nicht mehr gibt.
		if ($vhost->php) {
			$this->disablePhp($vhost);
			$vhost = $this->repository->byId((int)$vhost->id);
		}
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
		$base = $this->layout->baseDir($vhost);
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
		// Mindestlänge statt mehr Hash-Runden: nginx prüft Basic-Auth bei JEDER Anfrage
		// neu, ohne Zwischenspeicher. 100 000 Runden kosteten je Anfrage spürbar
		// Rechenzeit im Worker – und böten einen billigen Weg, die CPU auszulasten.
		// Die Oberfläche erzeugt ohnehin 20 Zeichen Zufall (Value\Password).
		if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
			throw new \RuntimeException('Passwort zu kurz: mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen.');
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
		// Vor der Ausstellung prüfen, ob Let's Encrypt den ACME-Pfad überhaupt erreichen
		// kann. Ohne diese Sperre liefe certbot ins Leere, und Let's Encrypt zählt jeden
		// Fehlversuch gegen das Kontingent der Domain (fünf pro Stunde) – nach einer
		// Handvoll Versuchen wäre die Domain für eine Stunde gesperrt.
		$vhost = $this->ensureHealthMarker($vhost);
		$result = $this->checkReachability([$vhost])[$vhost->name];
		if (!$result->isOk()) {
			throw new \RuntimeException(
				"Kein Zertifikat für \"{$vhost->name}\": " . $result->message
				. ' Let\'s Encrypt prüft genau diesen Pfad und würde scheitern.'
			);
		}
		if ($vhost->isLocal()) {
			throw new \RuntimeException("Let's Encrypt nur für echte Domains");
		}
		$email = $this->letsEncryptEmail()
			?? throw new \RuntimeException("Keine Let's-Encrypt-E-Mail hinterlegt (Einstellungen / \"vhost set le_email ...\")");
		$output = '';
		if (!file_exists($this->config->letsEncryptLive . '/' . $vhost->name . '/fullchain.pem')) {
			// Webroot muss web/ sein, nicht der Basisordner: der Renderer bedient die
			// ACME-Location mit "root <basis>/web" (C3, Abschlussreview 2026-09-20).
			// Der Nebenname gehört ins Zertifikat – aber nur, wenn er auch erreichbar
			// ist. certbot prüft jeden angegebenen Namen einzeln; ein Name ohne
			// DNS-Eintrag lässt den GESAMTEN Antrag scheitern, auch für den Hauptnamen.
			// Da die Umleitung voreingestellt ist, beträfe das sonst jede Domain, für
			// die es kein www gibt.
			$alias = $vhost->aliasName();
			$alsoFor = [];
			if ($alias !== null) {
				$aliasResult = $this->reachabilityChecker->checkName($alias, $vhost->healthToken);
				if ($aliasResult->isOk()) {
					$alsoFor[] = $alias;
				} else {
					$this->lastAliasNote = "Hinweis: \"$alias\" ist nicht erreichbar ("
						. $aliasResult->status->label() . '), das Zertifikat gilt deshalb nur für '
						. $vhost->name . '.';
				}
			}
			$output = $this->certbot->obtain($vhost->name, $this->layout->webDir($vhost), $email, $alsoFor);
			if ($this->lastAliasNote !== '') {
				$output = trim($output . "\n" . $this->lastAliasNote);
			}
		}
		$this->repository->setSsl($vhost->id, true);
		$this->linkCertificates($vhost);
		$this->render($this->load($vhost->name));
		return $output;
	}

	/**
	 * Legt in cert/ Symlinks auf die Zertifikatsdateien der Domain an.
	 *
	 * Reine Sichtbarkeit: die ssl_certificate-Direktiven zeigen weiterhin direkt nach
	 * /etc/letsencrypt/live, damit eine defekte Symlink-Kette den Start von nginx nicht
	 * verhindern kann.
	 */
	private function linkCertificates(Vhost $vhost): void
	{
		$live = $this->config->letsEncryptLive . '/' . $vhost->name;
		$certDir = $this->layout->certDir($vhost);
		if (!is_dir($certDir)) {
			return;
		}
		foreach (['fullchain.pem', 'privkey.pem'] as $file) {
			$link = $certDir . '/' . $file;
			if (is_link($link)) {
				unlink($link);
			}
			if (!file_exists($link)) {
				symlink($live . '/' . $file, $link);
			}
		}
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
	 * Konfigurations-Snippet des vHosts setzen.
	 *
	 * Der Text ist durch NginxSnippet bereits gegen die Positivliste geprüft. Schlägt
	 * danach "nginx -t" fehl (z.B. wegen einer syntaktisch falschen, aber erlaubten
	 * Direktive), wird die Datei auf den vorherigen Stand zurückgesetzt und die
	 * Ausnahme weitergeworfen – die Oberfläche zeigt dann die nginx-Meldung an.
	 *
	 * Ein leeres Snippet entfernt die Datei. Gab es vorher schon keine (leerer
	 * Aufruf auf einem vHost ohne Snippet), passiert nichts – insbesondere kein
	 * Reload, denn der würde einen fremden nginx-Fehler fälschlich diesem Aufruf
	 * zurechnen, obwohl er nichts verändert hat.
	 *
	 * @throws \RuntimeException wenn an der Zielstelle ein Symlink liegt, oder wenn
	 *         der Reloader scheitert (nach Rücknahme der Datei)
	 */
	public function setSnippet(Vhost $vhost, NginxSnippet $snippet): void
	{
		$file = $this->layout->confFile($vhost);
		if (is_link($file)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $file");
		}
		$previous = is_file($file) ? (string)file_get_contents($file) : null;
		if ($snippet->isEmpty() && $previous === null) {
			// Nichts zu tun: leeres Snippet auf einem vHost, der keines hat. Ein Reload
			// würde hier nur einen fremden nginx-Fehler als Fehlschlag dieses Aufrufs melden.
			return;
		}
		if ($snippet->isEmpty()) {
			unlink($file);
		} else {
			// Rückgabewert prüfen (I5, Abschlussreview 2026-09-20): schlägt das Schreiben
			// fehl (z.B. weil conf/ fehlt), meldete setSnippet() bisher trotzdem Erfolg.
			// Die @-Notation unterdrückt die PHP-Warnung bewusst – der Fehler wird als
			// Ausnahme gemeldet, die Warnung wäre doppelt und würde die Testausgabe/das
			// CLI-Protokoll unnötig verunreinigen.
			if (@file_put_contents($file, $snippet->value) === false) {
				throw new \RuntimeException("Kann Snippet nicht schreiben: $file");
			}
			// root als Besitzer, Gruppe = Besitzer der Website: der Mensch darf lesen,
			// schreiben darf nur root. Gehörte ihm die Datei, könnte er sich per chmod
			// selbst Schreibrecht geben und am Admin vorbei Direktiven setzen.
			if ($this->isRoot()) {
				chown($file, 'root');
				chgrp($file, $this->config->wwwOwner);
			}
			chmod($file, 0640);
		}
		try {
			$this->render($this->load($vhost->name));
		} catch (\Throwable $e) {
			$this->restoreFile($file, $previous);
			if ($previous !== null) {
				chmod($file, 0640);
			}
			throw $e;
		}
	}

	/**
	 * Aktueller Inhalt des Konfigurations-Snippets; leer, wenn keines gesetzt ist.
	 */
	public function snippet(Vhost $vhost): string
	{
		$file = $this->layout->confFile($vhost);
		return is_file($file) ? (string)file_get_contents($file) : '';
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
	/**
	 * Darf Strict-Transport-Security gesendet werden? Standard: ja.
	 *
	 * Abschaltbar, weil die Kopfzeile im Browser monatelang nachwirkt: Wer HTTPS für
	 * eine Domain wieder abschaltet, macht sie für wiederkehrende Besucher bis zum
	 * Ablauf unerreichbar. Wer das nicht will, setzt die Einstellung auf "off", bevor
	 * er Zertifikate verteilt.
	 */
	public function hstsEnabled(): bool
	{
		return $this->repository->setting(self::SETTING_HSTS) !== 'off';
	}

	/**
	 * HSTS für alle Hosts ein- oder ausschalten und die Konfiguration neu schreiben.
	 */
	public function setHsts(bool $on): void
	{
		$this->repository->setSetting(self::SETTING_HSTS, $on ? 'on' : 'off');
		$this->renderAll();
	}

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
		// Kennung und Marker für den Erreichbarkeitstest sicherstellen (idempotent).
		$vhost = $this->ensureHealthMarker($vhost);
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

		$this->writeProtected($htpasswd, $this->renderer->htpasswd($this->repository->users($vhost->id)));

		file_put_contents($authSnippet, $this->renderer->authSnippet($vhost, $this->repository->ips($vhost->id)));

		file_put_contents($available, $this->renderer->serverConfig($vhost, $this->hstsEnabled()));
		// Ein zum Entfernen vorgemerkter vHost darf durch ein Neuschreiben (z.B. aus
		// install.sh oder renderAll()) nicht wieder aktiv werden.
		if ($vhost->isPendingDeletion()) {
			$this->disableSite($vhost);
		} elseif (!$symlinkExistedBefore) {
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
	 * Entfernen anstossen: sofort sperren, endgültig erst nach der Schonfrist.
	 *
	 * nginx hört unmittelbar auf, den vHost auszuliefern – der Symlink in sites-enabled
	 * verschwindet und nginx wird neu geladen. Alles andere bleibt: der Eintrag in der
	 * Datenbank, die Konfiguration in sites-available, die Benutzer und sämtliche
	 * Dateien unter /var/www. Damit lässt sich das Entfernen innerhalb der Frist
	 * vollständig zurücknehmen (restore()), und erst purgeDue() räumt endgültig auf.
	 *
	 * Mehrfach aufrufbar: ist das Entfernen schon angestossen, bleibt der ursprüngliche
	 * Zeitpunkt stehen – sonst liesse sich die Frist durch wiederholtes Klicken verlängern.
	 */
	public function scheduleRemoval(Vhost $vhost): void
	{
		if (!$vhost->isPendingDeletion()) {
			$this->repository->setDeletedAt(
				(int)$vhost->id,
				new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
			);
		}
		$this->disableSite($vhost);
		$this->reloader->reload();
	}

	/**
	 * Ein angestossenes Entfernen zurücknehmen; der vHost wird wieder ausgeliefert.
	 */
	public function restore(Vhost $vhost): void
	{
		if (!$vhost->isPendingDeletion()) {
			return;
		}
		$this->repository->setDeletedAt((int)$vhost->id, null);
		$this->render($this->repository->byId((int)$vhost->id));
	}

	/**
	 * Alle vHosts endgültig entfernen, deren Schonfrist abgelaufen ist.
	 *
	 * Die Dateien unter /var/www bleiben dabei liegen – genau wie beim Entfernen über
	 * die Oberfläche, das noch nie Dateien gelöscht hat. Wer auch die Dateien los werden
	 * will, nimmt "vhost remove <name> --purge".
	 *
	 * @return list<string> Namen der entfernten vHosts
	 */
	public function purgeDue(): array
	{
		$cutoff = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
			->modify('-' . $this->config->removalGraceMinutes . ' minutes')
			->format('Y-m-d H:i:s');
		$removed = [];
		foreach ($this->repository->dueForDeletion($cutoff) as $vhost) {
			$this->remove($vhost);
			$removed[] = $vhost->name;
		}
		return $removed;
	}

	/**
	 * Nimmt den vHost aus sites-enabled, ohne sonst etwas anzufassen.
	 *
	 * Danach kennt nginx den Namen nicht mehr; Anfragen landen beim default_server und
	 * werden mit 444 abgewiesen. Der Reload bleibt dem Aufrufer überlassen.
	 */
	private function disableSite(Vhost $vhost): void
	{
		$enabled = $this->config->sitesEnabled . '/' . $vhost->slug() . '.conf';
		if (is_link($enabled) || file_exists($enabled)) {
			unlink($enabled);
		}
	}

	/**
	 * www-Umgang setzen: "bare" (auf <domain>, Voreinstellung) oder "www".
	 *
	 * "aus" gibt es nicht: Einer der beiden Namen liefert aus, der andere leitet
	 * dorthin um. Nur für Hauptdomains – bei einer Unterdomain ist "www.shop.example.com"
	 * nicht üblich und existiert in aller Regel gar nicht.
	 *
	 * Wichtig bei eingeschaltetem HTTPS: Das vorhandene Zertifikat deckt den Nebennamen
	 * noch nicht ab. Solange es fehlt, bekommt ein Aufruf von https://<nebenname> einen
	 * Zertifikatsfehler, bevor die Umleitung greift. Der Aufrufer wird darauf
	 * hingewiesen; ein erneutes "ssl on" holt ein Zertifikat für beide Namen.
	 *
	 * @throws \InvalidArgumentException bei unbekanntem Modus
	 */
	public function setWwwMode(Vhost $vhost, string $mode): void
	{
		if (!in_array($mode, Vhost::WWW_MODES, true)) {
			throw new \InvalidArgumentException(
				"Unbekannter www-Umgang: \"$mode\" (erlaubt: " . implode(', ', Vhost::WWW_MODES) . ')'
			);
		}
		if (!$vhost->supportsWwwRedirect()) {
			throw new \RuntimeException(
				"Für \"{$vhost->name}\" gibt es keine www-Entsprechung – das gilt nur für Hauptdomains."
			);
		}
		if ($mode === $vhost->wwwMode) {
			return;
		}
		$this->repository->setWwwMode((int)$vhost->id, $mode);
		$this->render($this->repository->byId((int)$vhost->id));
	}

	/**
	 * Deckt das vorhandene Zertifikat den Nebennamen ab?
	 *
	 * Prüft die Renewal-Konfiguration von certbot, nicht das Zertifikat selbst: dort
	 * steht, für welche Namen es ausgestellt wurde.
	 */
	/**
	 * Trägt den Nebennamen (www.<domain>) in ein bereits bestehendes Zertifikat nach.
	 *
	 * enableSsl() überspringt certbot, sobald fullchain.pem existiert – richtig, damit
	 * ein Wiedereinschalten nicht jedes Mal ein neues Zertifikat beantragt. Genau deshalb
	 * braucht der Nachtrag einen eigenen Weg: Wer die www-Umleitung erst nach der
	 * Ausstellung einschaltet, hätte sonst dauerhaft ein Zertifikat, das den umgeleiteten
	 * Namen nicht abdeckt (gemeldet am 2026-09-22).
	 *
	 * @return string Ausgabe von certbot
	 * @throws \RuntimeException wenn es nichts zu erweitern gibt oder der Nebenname fehlt
	 */
	public function extendCertificate(Vhost $vhost): string
	{
		$alias = $vhost->aliasName();
		if ($alias === null) {
			throw new \RuntimeException("\"{$vhost->name}\" hat keinen Nebennamen – nichts zu erweitern.");
		}
		if (!$vhost->ssl) {
			throw new \RuntimeException(
				"Für \"{$vhost->name}\" ist HTTPS aus. Der normale Weg (\"Zertifikat holen\") deckt beide Namen ab."
			);
		}
		$email = $this->letsEncryptEmail()
			?? throw new \RuntimeException("Keine Let's-Encrypt-E-Mail hinterlegt (Einstellungen / \"vhost set le_email ...\")");

		// Beide Namen einzeln prüfen, bevor certbot läuft. certbot prüft jeden Namen
		// selbst, und ein einziger nicht erreichbarer Name lässt den GESAMTEN Antrag
		// scheitern – samt Kontingentverbrauch für den Hauptnamen (fünf Fehlversuche
		// pro Stunde). Ein Abbruch hier kostet dagegen nichts.
		$vhost = $this->ensureHealthMarker($vhost);
		foreach ([$vhost->name, $alias] as $name) {
			$result = $this->reachabilityChecker->checkName($name, $vhost->healthToken);
			if (!$result->isOk()) {
				throw new \RuntimeException(
					"Kein erweitertes Zertifikat: \"$name\" ist nicht erreichbar (" . $result->status->label() . '). '
					. 'Let\'s Encrypt prüft jeden Namen einzeln – ein fehlender lässt den ganzen Antrag scheitern.'
				);
			}
		}

		$output = $this->certbot->obtain($vhost->name, $this->layout->webDir($vhost), $email, [$alias]);
		$this->linkCertificates($vhost);
		$this->render($this->load($vhost->name));
		return $output;
	}

	public function certificateCoversAlias(Vhost $vhost): bool
	{
		$alias = $vhost->aliasName();
		if ($alias === null || !$vhost->ssl) {
			return true;
		}
		$live = $this->config->letsEncryptLive . '/' . $vhost->name . '/fullchain.pem';
		if (!is_file($live)) {
			return false;
		}
		exec('openssl x509 -noout -text -in ' . escapeshellarg($live) . ' 2>&1', $output, $code);
		return $code === 0 && str_contains(implode("\n", $output), 'DNS:' . $alias);
	}

	/**
	 * Docroot-Unterordner ändern und den Inhalt mitnehmen.
	 *
	 * Der Inhalt zieht mit, weil sonst nichts auf einen Fehler hindeutet: nginx zeigte
	 * ab dem Umschalten auf ein leeres Verzeichnis, die Seite wäre verschwunden, und in
	 * keinem Log stünde warum.
	 *
	 * Vorher wird der Basisordner gesichert – hier werden echte Inhalte verschoben, und
	 * ein Fehlgriff beim Unterordner soll wiederherstellbar bleiben.
	 *
	 * @throws \RuntimeException wenn im Ziel schon Dateien liegen oder das Verschieben scheitert
	 */
	public function setSubdirectory(Vhost $vhost, ?SubDirectory $subdir): void
	{
		$new = $subdir?->value;
		if ($new === $vhost->subdir) {
			return;
		}
		$oldDocroot = $this->layout->docroot($vhost);

		$candidate = new Vhost(
			$vhost->id, $vhost->name, $vhost->kind, $vhost->port, $new,
			$vhost->protect, $vhost->ssl, $vhost->php, $vhost->wwwMode,
			$vhost->healthToken, $vhost->deletedAt, $vhost->createdAt
		);
		$newDocroot = $this->layout->docroot($candidate);

		if (is_link($oldDocroot) || is_link($newDocroot)) {
			throw new \RuntimeException('Symlink gehört hier nicht hin, wird nicht angefasst.');
		}
		$movable = $this->movableEntries($oldDocroot, $newDocroot);
		$conflicts = [];
		foreach ($movable as $entry) {
			if (file_exists($newDocroot . '/' . $entry) || is_link($newDocroot . '/' . $entry)) {
				$conflicts[] = $entry;
			}
		}
		if ($conflicts !== []) {
			throw new \RuntimeException(
				"In $newDocroot liegen bereits: " . implode(', ', $conflicts)
				. '. Es wird nichts überschrieben – bitte von Hand entscheiden, was gelten soll.'
			);
		}

		$this->backupBaseDir($vhost);
		$this->repository->setSubdir((int)$vhost->id, $new);
		$updated = $this->repository->byId((int)$vhost->id);
		$this->makeDirectories($updated);
		$this->moveDirectoryContents($oldDocroot, $this->layout->docroot($updated), $movable);
		$this->applyPermissions($updated);
		$this->render($updated);
	}

	/**
	 * Was aus dem alten Docroot mitwandern darf.
	 *
	 * Zwei Dinge bleiben immer liegen:
	 *  - ".well-known": Der ACME-Pfad hängt an web/, nicht am Docroot (der ACME-Block
	 *    setzt "root web/"). Würde er mitwandern, käme Let's Encrypt nicht mehr durch
	 *    und die Zertifikatserneuerung scheiterte.
	 *  - die erste Wegmarke zum neuen Docroot, falls der im alten liegt – sonst würde
	 *    ein Ordner in sich selbst verschoben.
	 *
	 * @return list<string>
	 */
	private function movableEntries(string $oldDocroot, string $newDocroot): array
	{
		if (!is_dir($oldDocroot)) {
			return [];
		}
		$skip = ['.', '..', '.well-known'];
		if (str_starts_with($newDocroot . '/', $oldDocroot . '/')) {
			$rest = trim(substr($newDocroot, strlen($oldDocroot)), '/');
			if ($rest !== '') {
				$skip[] = explode('/', $rest)[0];
			}
		}
		return array_values(array_diff(scandir($oldDocroot) ?: [], $skip));
	}

	/**
	 * Verschiebt alle Einträge aus $from nach $to; $from bleibt danach leer zurück.
	 *
	 * Bewusst Eintrag für Eintrag statt "mv $from $to": Das Ziel existiert bereits (es
	 * wurde gerade mit den richtigen Rechten angelegt), und ein Verschieben des ganzen
	 * Ordners würde daraus einen Unterordner im Ziel machen.
	 *
	 * @param list<string> $entries Einträge, die mitwandern (siehe movableEntries())
	 * @throws \RuntimeException wenn ein Eintrag nicht verschoben werden kann
	 */
	private function moveDirectoryContents(string $from, string $to, array $entries): void
	{
		if ($from === $to || !is_dir($from)) {
			return;
		}
		foreach ($entries as $entry) {
			$target = $to . '/' . $entry;
			if (file_exists($target) || is_link($target)) {
				throw new \RuntimeException("Im Ziel existiert bereits: $target");
			}
			if (!@rename($from . '/' . $entry, $target)) {
				throw new \RuntimeException("Kann $from/$entry nicht nach $target verschieben.");
			}
		}
	}

	/**
	 * Sichert den Basisordner eines vHosts vor einem Eingriff, der Dateien bewegt.
	 */
	private function backupBaseDir(Vhost $vhost): string
	{
		$base = $this->layout->baseDir($vhost);
		if (!is_dir($base)) {
			return '';
		}
		$dir = $this->config->backupDir;
		if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
			throw new \RuntimeException("Kann Sicherungsverzeichnis nicht anlegen: $dir");
		}
		$archive = $dir . '/vhost-admin-' . $vhost->slug() . '-' . date('Ymd-His') . '.tar.gz';
		exec(sprintf(
			'tar czf %s -C %s %s 2>&1',
			escapeshellarg($archive),
			escapeshellarg($this->config->wwwRoot),
			escapeshellarg($vhost->slug())
		), $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException("Sicherung fehlgeschlagen:\n" . implode("\n", $output));
		}
		// Enthält auch private/; nur root darf lesen (wie bei der Layout-Migration).
		if (!chmod($archive, 0600)) {
			throw new \RuntimeException("Kann Rechte der Sicherung nicht auf 0600 setzen: $archive");
		}
		return $archive;
	}

	/**
	 * Die fertige Konfiguration dieses vHosts als ein zusammenhängender Text.
	 *
	 * nginx setzt den Block aus mehreren Dateien zusammen: dem erzeugten server-Block,
	 * dem Auth-Snippet und den eigenen Direktiven aus conf/. Wer wissen will, was am
	 * Ende gilt, müsste sie auf dem Server einzeln nachschlagen. Diese Methode setzt
	 * die Einbindungen, die zu diesem vHost gehören, an Ort und Stelle ein und nennt
	 * dabei die Herkunft.
	 *
	 * Fremde Einbindungen – etwa fastcgi_params – bleiben als Verweis stehen: Das sind
	 * unveränderliche Systemdateien, deren Inhalt hier nur Rauschen wäre.
	 *
	 * @throws \RuntimeException wenn für den vHost keine Konfiguration geschrieben wurde
	 */
	public function effectiveConfig(Vhost $vhost): string
	{
		$file = $this->renderer->serverConfigPath($vhost);
		if (!is_file($file)) {
			throw new \RuntimeException("Für {$vhost->name} wurde noch keine nginx-Konfiguration geschrieben ($file).");
		}
		$own = [
			$this->renderer->authSnippetPath($vhost) => 'Verzeichnisschutz',
			$this->layout->confDir($vhost) . '/*.conf' => 'eigene Direktiven',
		];
		$out = [];
		foreach (explode("\n", (string)file_get_contents($file)) as $line) {
			if (preg_match('/^(\s*)include\s+(\S+);\s*$/', $line, $match) && isset($own[$match[2]])) {
				foreach ($this->inlineInclude($match[1], $match[2], $own[$match[2]]) as $inlined) {
					$out[] = $inlined;
				}
				continue;
			}
			$out[] = $line;
		}
		return implode("\n", $out);
	}

	/**
	 * Inhalt einer eingebundenen Datei, eingerückt und mit Herkunftsangabe.
	 *
	 * @param string $indent  Einrückung der ersetzten include-Zeile
	 * @param string $pattern Pfad oder Glob-Muster aus der include-Direktive
	 * @param string $label   Was dort steht, für die Herkunftszeile
	 * @return list<string>
	 */
	private function inlineInclude(string $indent, string $pattern, string $label): array
	{
		$files = str_contains($pattern, '*') ? (glob($pattern) ?: []) : (is_file($pattern) ? [$pattern] : []);
		if ($files === []) {
			return [$indent . "# $label: keine Datei vorhanden"
				. ($label === 'eigene Direktiven' ? ' – keine eigenen Direktiven gesetzt' : '')];
		}
		$out = [];
		foreach ($files as $path) {
			$out[] = $indent . "# ── $label aus $path";
			foreach (explode("\n", rtrim((string)file_get_contents($path), "\n")) as $line) {
				// Leerzeilen nicht künstlich einrücken – sonst stehen dort Leerzeichen.
				$out[] = $line === '' ? '' : $indent . $line;
			}
			$out[] = $indent . "# ── Ende $label";
		}
		return $out;
	}

	/**
	 * Erreichbarkeit einer oder mehrerer vHosts über den ACME-Pfad prüfen.
	 *
	 * Die Entscheidung, was geprüft wird, steckt in Ssl\ReachabilityChecker – die
	 * Oberfläche nutzt dieselbe Logik, ohne über das CLI gehen zu müssen.
	 *
	 * @param list<Vhost> $vhosts
	 * @return array<string, ReachabilityResult>
	 */
	public function checkReachability(array $vhosts): array
	{
		return $this->reachabilityChecker->check($vhosts);
	}

	/**
	 * Legt die Kennung und die Markerdatei im ACME-Pfad an, falls sie fehlen.
	 *
	 * Die Kennung bleibt über Neuschreibungen hinweg dieselbe, damit ein gerade
	 * laufender Test der Oberfläche nicht gegen einen veralteten Wert prüft. Die Datei
	 * wird wieder angelegt, wenn sie fehlt – certbot räumt den Ordner nach einer
	 * Ausstellung auf und könnte sie mitnehmen.
	 *
	 * @return Vhost der vHost mit gesetzter Kennung
	 */
	private function ensureHealthMarker(Vhost $vhost): Vhost
	{
		if ($vhost->isLocal()) {
			return $vhost;
		}
		$token = $vhost->healthToken;
		if ($token === null) {
			$token = bin2hex(random_bytes(16));
			$this->repository->setHealthToken((int)$vhost->id, $token);
			$vhost = $this->repository->byId((int)$vhost->id);
		}
		$dir = $this->layout->acmeDir($vhost);
		// JEDEN Abschnitt unterhalb von web/ prüfen, nicht nur den letzten: web/ ist für
		// www-data beschreibbar. War .well-known selbst ein Symlink, legte root dort
		// Ordner und Datei an – und certbot schrieb seine Challenge danach ebenfalls
		// dorthin (SECURITY_RISKS.md). enableSsl() und extendCertificate() laufen beide
		// hier durch, bevor certbot startet.
		$path = $this->layout->webDir($vhost);
		foreach (explode('/', trim(substr($dir, strlen($path)), '/')) as $segment) {
			$path .= '/' . $segment;
			if (is_link($path)) {
				throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
			}
		}
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			// Kein harter Fehler: ohne Marker fehlt nur die Anzeige, der vHost selbst
			// funktioniert. Deshalb still aufgeben statt das Schreiben abzubrechen.
			return $vhost;
		}
		$file = $this->layout->healthFile($vhost);
		if (is_link($file)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $file");
		}
		if (!is_file($file) || trim((string)file_get_contents($file)) !== $token) {
			@file_put_contents($file, $token . "\n");
		}
		if (is_file($file)) {
			@chmod($file, 0644);
		}
		return $vhost;
	}

	/**
	 * PHP für einen vHost einschalten: Systembenutzer, Pool, Rechte, nginx.
	 *
	 * Reihenfolge ist hier sicherheitsrelevant und nicht beliebig:
	 *  1. Systembenutzer anlegen. Fehlt er, verweigert php-fpm den Start des Pools mit
	 *     "Unable to find user" – und zwar des gesamten Dienstes, womit auch alle
	 *     anderen Hosts kein PHP mehr hätten.
	 *  2. Kennzeichen in der Datenbank setzen, damit Layout und Renderer ab jetzt den
	 *     eigenen Benutzer und den Socket dieses Hosts liefern.
	 *  3. tmp/ und php.log anlegen, Rechte setzen (web/ gehört jetzt dem neuen Benutzer).
	 *  4. Pool schreiben und php-fpm neu laden. Scheitert das, wird die Pool-Datei
	 *     zurückgenommen und das Kennzeichen wieder zurückgesetzt – sonst bliebe eine
	 *     kaputte Pool-Datei liegen, an der jeder weitere Reload scheitert.
	 *  5. nginx neu schreiben, damit .php an den Socket geht statt 404 zu liefern.
	 *
	 * Mehrfach aufrufbar: ist PHP schon an, passiert nichts.
	 *
	 * @throws \RuntimeException wenn Benutzer, Pool oder Reload scheitern
	 */
	public function enablePhp(Vhost $vhost): void
	{
		if ($vhost->php) {
			return;
		}
		$user = $this->layout->phpUser($vhost);
		if (!$this->systemUsers->exists($user)) {
			$this->systemUsers->create($user, $this->layout->baseDir($vhost));
		}

		$this->repository->setPhp((int)$vhost->id, true);
		$withPhp = $this->repository->byId((int)$vhost->id);

		$pool = $this->layout->phpPoolFile($withPhp);
		$poolExistedBefore = $this->readIfExists($pool);
		try {
			$this->preparePhpDirectories($withPhp);
			$this->applyPermissions($withPhp);
			$this->writePoolFile($withPhp);
			$this->fpmReloader->reload();
		} catch (\Throwable $e) {
			// Alles zurück auf "PHP aus": Pool weg, Kennzeichen zurück, Rechte wieder
			// auf den allgemeinen Besitzer.
			$this->restoreFile($pool, $poolExistedBefore);
			$this->repository->setPhp((int)$vhost->id, false);
			$this->applyPermissions($this->repository->byId((int)$vhost->id));
			throw $e;
		}
		$this->render($withPhp);
	}

	/**
	 * PHP wieder abschalten: Pool entfernen, Rechte zurück, nginx neu schreiben.
	 *
	 * Der Systembenutzer bleibt bestehen. Ihn zu löschen wäre riskant: Dateien in
	 * private/ oder von PHP angelegte Dateien könnten ihm noch gehören und hätten
	 * danach einen Besitzer, den es nicht mehr gibt (eine später neu angelegte
	 * Kennung könnte dieselbe UID bekommen und käme an diese Dateien).
	 */
	public function disablePhp(Vhost $vhost): void
	{
		if (!$vhost->php) {
			return;
		}
		$pool = $this->layout->phpPoolFile($vhost);
		if (file_exists($pool)) {
			unlink($pool);
		}
		$this->fpmReloader->reload();
		$this->repository->setPhp((int)$vhost->id, false);
		$withoutPhp = $this->repository->byId((int)$vhost->id);
		$this->applyPermissions($withoutPhp);
		$this->render($withoutPhp);
	}

	/**
	 * Legt die Ordner und Dateien an, die PHP zum Laufen braucht.
	 *
	 * tmp/ ist das eigene Temporärverzeichnis (open_basedir lässt /tmp nicht zu, damit
	 * Hosts sich nicht über gemeinsame Dateien in die Quere kommen). php.log muss dem
	 * PHP-Benutzer gehören, weil PHP selbst hineinschreibt – nicht der php-fpm-Master.
	 */
	private function preparePhpDirectories(Vhost $vhost): void
	{
		$user = $this->layout->phpUser($vhost);
		$tmp = $this->layout->baseDir($vhost) . '/tmp';
		if (!is_dir($tmp) && !is_link($tmp)) {
			mkdir($tmp, 0700, true);
		}
		$this->ownAs($tmp, $user, $user, 0700);

		$log = $this->layout->phpLog($vhost);
		if (!file_exists($log) && !is_link($log)) {
			touch($log);
		}
		$this->ownAs($log, $user, $this->config->wwwOwner, 0640);
	}

	/**
	 * Schreibt die Pool-Datei; sie gehört root und ist für andere nicht lesbar.
	 *
	 * @throws \RuntimeException wenn das Schreiben scheitert
	 */
	private function writePoolFile(Vhost $vhost): void
	{
		$pool = $this->layout->phpPoolFile($vhost);
		$dir = dirname($pool);
		if (!is_dir($dir)) {
			throw new \RuntimeException("Pool-Verzeichnis von php-fpm fehlt: $dir (ist php-fpm installiert?)");
		}
		if (@file_put_contents($pool, $this->poolRenderer->render($vhost)) === false) {
			throw new \RuntimeException("Pool-Datei konnte nicht geschrieben werden: $pool");
		}
		$this->ownAs($pool, 'root', 'root', 0640);
	}

	/**
	 * Soll-Rechte aller Verzeichnisse eines vHosts neu setzen.
	 *
	 * Für den CLI-Befehl "fix-permissions" nach manuellen Eingriffen.
	 */
	public function applyPermissions(Vhost $vhost): void
	{
		foreach ($this->layout->directories($vhost) as $spec) {
			if (is_dir($spec->path)) {
				$this->applySpec($spec);
			}
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
	 * Alle Verzeichnisse des vHosts anlegen und ihre Soll-Rechte setzen.
	 *
	 * Die Liste kommt aus VhostLayout::directories() (Eltern vor Kindern), damit Service,
	 * Installer und Migration dieselben Rechte anwenden. Jedes Verzeichnis wird einzeln
	 * geprüft und angelegt: seit der Rechteänderung vom Abschlussreview (C2, 2026-09-20)
	 * ist innerhalb des Basisordners nur noch web/ für www-data beschreibbar – trotzdem
	 * könnte dort ein Segment ein untergeschobener Symlink sein.
	 */
	private function makeDirectories(Vhost $vhost): void
	{
		foreach ($this->layout->directories($vhost) as $spec) {
			$this->makeDirectory($spec->path);
			$this->applySpec($spec);
		}
	}

	/**
	 * Legt ein einzelnes Verzeichnis an, falls es fehlt.
	 *
	 * @throws \RuntimeException wenn der Pfad ein Symlink ist oder sich nicht anlegen lässt
	 */
	private function makeDirectory(string $path): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
		if (!is_dir($path) && !mkdir($path, 0775, true)) {
			throw new \RuntimeException("Kann $path nicht anlegen");
		}
	}

	/**
	 * Setzt Besitzer, Gruppe und Rechte eines Verzeichnisses gemäß Vorgabe.
	 *
	 * Besitzer und Gruppe nur als root; die Rechte werden immer gesetzt, damit die
	 * Tests ohne root dieselbe Wirkung prüfen können.
	 *
	 * Kein Fallback auf die www-Gruppe, wenn chown() fehlschlägt (I1, Abschlussreview
	 * 2026-09-20): ein Fallback würde das Verzeichnis dem Webserver-Benutzer zu eigen
	 * machen und eine Fehlkonfiguration (z.B. unbekannter Soll-Besitzer) verschleiern,
	 * statt sie zu melden.
	 *
	 * @throws \RuntimeException wenn der Pfad ein Symlink ist, oder wenn chown() als
	 *         root fehlschlägt (z.B. weil der Soll-Besitzer nicht existiert)
	 */
	private function applySpec(DirectorySpec $spec): void
	{
		if (is_link($spec->path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: {$spec->path}");
		}
		if ($this->isRoot()) {
			if (!@chown($spec->path, $spec->owner)) {
				throw new \RuntimeException("Kann Besitzer von {$spec->path} nicht auf \"{$spec->owner}\" setzen");
			}
			chgrp($spec->path, $spec->group);
		}
		chmod($spec->path, $spec->mode);
	}

	/**
	 * Startseite aus der Vorlage schreiben, falls im Docroot noch keine existiert.
	 *
	 * Ein Symlink gilt dabei ebenfalls als "existiert schon" und wird nicht
	 * überschrieben – file_exists() folgt Symlinks, is_link() nicht.
	 */
	private function writeIndex(Vhost $vhost): void
	{
		$docroot = $this->layout->docroot($vhost);
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
	 * Kein Fallback auf die www-Gruppe, wenn chown() fehlschlägt (I1, wie applySpec()).
	 *
	 * @throws \RuntimeException wenn $path ein Symlink ist (gehört dort nicht hin;
	 *         www-data könnte ihn auf z.B. /etc/cron.d gelegt haben), oder wenn
	 *         chown() als root fehlschlägt
	 */
	private function own(string $path, int $mode): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
		if ($this->isRoot()) {
			if (!@chown($path, $this->config->wwwOwner)) {
				throw new \RuntimeException("Kann Besitzer von $path nicht auf \"{$this->config->wwwOwner}\" setzen");
			}
			chgrp($path, $this->config->wwwGroup);
		}
		chmod($path, $mode);
	}

	/**
	 * Besitzer/Gruppe/Rechte mit ausdrücklich genannten Namen setzen; ohne root nur die Rechte.
	 *
	 * Für die PHP-Dateien: die gehören nicht dem allgemeinen Besitzer, sondern dem
	 * eigenen Benutzer des vHosts (siehe own() für den Regelfall).
	 *
	 * @throws \RuntimeException wenn $path ein Symlink ist oder chown() als root fehlschlägt
	 */
	private function ownAs(string $path, string $owner, string $group, int $mode): void
	{
		if (is_link($path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $path");
		}
		if ($this->isRoot()) {
			if (!@chown($path, $owner)) {
				throw new \RuntimeException("Kann Besitzer von $path nicht auf \"$owner\" setzen");
			}
			if (!@chgrp($path, $group)) {
				throw new \RuntimeException("Kann Gruppe von $path nicht auf \"$group\" setzen");
			}
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
	/**
	 * Schreibt eine Datei, die nur root und die Gruppe www-data lesen dürfen, ohne
	 * Zeitfenster mit zu offenen Rechten.
	 *
	 * tempnam() legt die Zwischendatei mit 0600 an; Gruppe und Rechte werden gesetzt,
	 * BEVOR sie per rename() an ihren Platz kommt. Vorher entstand die Datei mit den
	 * Rechten der Prozessmaske und bekam 0640 erst danach (SECURITY_RISKS.md).
	 */
	private function writeProtected(string $path, string $content): void
	{
		$temp = tempnam(dirname($path), '.' . basename($path) . '.tmp');
		if ($temp === false) {
			throw new \RuntimeException("Kann Zwischendatei nicht anlegen neben: $path");
		}
		try {
			if (file_put_contents($temp, $content) === false) {
				throw new \RuntimeException("Kann nicht schreiben: $temp");
			}
			$this->group($temp);
			chmod($temp, 0640);
			if (!rename($temp, $path)) {
				throw new \RuntimeException("Kann Datei nicht ersetzen: $path");
			}
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	private function hashPassword(string $password): string
	{
		return crypt($password, '$6$' . substr(bin2hex(random_bytes(12)), 0, 16) . '$');
	}
}
