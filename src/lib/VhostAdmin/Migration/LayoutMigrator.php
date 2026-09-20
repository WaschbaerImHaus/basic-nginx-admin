<?php
declare(strict_types=1);

/**
 * Bringt vorhandene vHosts von der flachen Struktur auf web/conf/cert/private/logs.
 *
 * Alte Installationen hatten den Docroot direkt im Basisordner. Die Migration legt
 * web/ an, verschiebt alles Vorhandene dorthin und erzeugt die übrigen Ordner. Der
 * Wert "subdir" in der Datenbank bleibt unverändert: aus <base>/www/src wird
 * <base>/web/www/src.
 *
 * Damit ein abgebrochener Lauf nichts verliert und der nächste Lauf sicher fortsetzen
 * kann, wird der Inhalt nie direkt in web/ verschoben, sondern zuerst in den
 * Zwischenordner .web-migrating/ im Basisordner. Erst wenn wirklich alles dort liegt,
 * wird er atomar zu web/ umbenannt. Solange web/ dadurch noch fehlt, hält
 * VhostLayout::needsMigration() den Host weiterhin für nicht migriert.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 10:34
 */

namespace VhostAdmin\Migration;

use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;

final class LayoutMigrator
{
	/** Name des Zwischenordners im Basisordner, in den zuerst verschoben wird. */
	private const STAGING_DIRNAME = '.web-migrating';

	/**
	 * Übernimmt Konfiguration, Repository und Layout und merkt sich, wo die
	 * bisherigen nginx-Logdateien und die certbot-Renewal-Konfigurationen liegen.
	 *
	 * @param string $nginxLogDir Verzeichnis der bisherigen nginx-Logdateien
	 * @param string $renewalDir  Verzeichnis der certbot-Renewal-Konfigurationen
	 *                            (C3, Abschlussreview 2026-09-20: dort steht der beim
	 *                            Ausstellen verwendete Webroot-Pfad, der bei alten
	 *                            vHosts noch auf den Basisordner statt auf web/ zeigt)
	 */
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly VhostLayout $layout,
		private readonly string $nginxLogDir = '/var/log/nginx',
		private readonly string $renewalDir = '/etc/letsencrypt/renewal',
	) {
	}

	/**
	 * vHosts, die noch die alte Struktur haben.
	 *
	 * @return list<Vhost>
	 */
	public function pending(): array
	{
		return array_values(array_filter(
			$this->repository->all(),
			fn(Vhost $vhost): bool => $this->layout->needsMigration($vhost)
		));
	}

	/**
	 * Sichert die Basisordner der übergebenen vHosts als Tar-Archiv.
	 *
	 * @param list<Vhost> $vhosts
	 * @return string Pfad des erzeugten Archivs
	 * @throws \RuntimeException wenn nichts zu sichern ist oder tar fehlschlägt
	 */
	public function backup(array $vhosts, string $targetDir): string
	{
		if ($vhosts === []) {
			throw new \RuntimeException('Keine vHosts zu sichern.');
		}
		if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
			throw new \RuntimeException("Kann Sicherungsverzeichnis nicht anlegen: $targetDir");
		}
		$archive = $targetDir . '/vhost-admin-migration-' . date('Ymd-His') . '.tar.gz';
		$slugs = [];
		foreach ($vhosts as $vhost) {
			if (is_dir($this->layout->baseDir($vhost))) {
				$slugs[] = escapeshellarg($vhost->slug());
			}
		}
		if ($slugs === []) {
			throw new \RuntimeException('Keine vorhandenen Basisordner zu sichern.');
		}
		$command = sprintf(
			'tar czf %s -C %s %s 2>&1',
			escapeshellarg($archive),
			escapeshellarg($this->config->wwwRoot),
			implode(' ', $slugs)
		);
		exec($command, $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException("Sicherung fehlgeschlagen:\n" . implode("\n", $output));
		}
		// Das Archiv enthält den kompletten Inhalt der Basisordner, auch private/, und
		// entsteht mit dem Umask von root (üblicherweise 0644 – für alle lesbar). I2,
		// Abschlussreview 2026-09-20: nur noch der Besitzer (root) darf lesen.
		if (!chmod($archive, 0600)) {
			throw new \RuntimeException("Kann Rechte der Sicherung nicht auf 0600 setzen: $archive");
		}
		return $archive;
	}

	/**
	 * Bringt einen einzelnen vHost auf die neue Struktur. Mehrfach aufrufbar.
	 */
	public function migrate(Vhost $vhost): void
	{
		$base = $this->layout->baseDir($vhost);
		if (!is_dir($base)) {
			return;
		}
		if ($this->layout->needsMigration($vhost)) {
			$this->moveContentIntoWeb($base, $this->layout->webDir($vhost));
		}
		foreach ($this->layout->directories($vhost) as $spec) {
			$this->ensureDirectory($spec);
		}
		$this->moveLogs($vhost);
		$this->migrateCertbotRenewalConfig($vhost);
	}

	/**
	 * Zieht eine vorhandene certbot-Renewal-Konfiguration auf den neuen Webroot nach.
	 *
	 * certbot merkt sich den beim Ausstellen verwendeten Webroot-Pfad in
	 * "<renewalDir>/<domain>.conf", und zwar zweifach: als Fallback in
	 * "webroot_path" (kommagetrennte Liste) und je Domain unter "[[webroot_map]]".
	 * Zeigt einer der beiden noch auf den alten Basisordner (vor C3, Review vom
	 * 2026-09-20), würde certbot beim nächsten "certbot renew" die Challenge-Datei
	 * unterhalb des Basisordners statt unterhalb von web/ ablegen – dort, wo sie der
	 * Renderer erwartet, läge sie dann nicht. Ein fehlendes Renewal-Verzeichnis oder
	 * eine fehlende/unpassende Konfigurationsdatei ist kein Fehler: nicht jeder
	 * vHost hat schon ein Zertifikat.
	 */
	private function migrateCertbotRenewalConfig(Vhost $vhost): void
	{
		if (!is_dir($this->renewalDir)) {
			return;
		}
		$file = $this->renewalDir . '/' . $vhost->name . '.conf';
		if (!is_file($file) || is_link($file)) {
			return;
		}
		$old = $this->layout->baseDir($vhost);
		$new = $this->layout->webDir($vhost);
		$content = (string)file_get_contents($file);
		// Der alte Basisordner wird nur ersetzt, wenn er als eigenständiger Pfadwert
		// auftritt (davor "=", "," oder Leerraum; danach ",", Leerraum oder Textende) –
		// so bleibt ein bereits migrierter Eintrag ("<basis>/web") unverändert, weil
		// ihm dort kein Trenner/Textende folgt, sondern "/web".
		$updated = preg_replace(
			'/(?<=[=,\s])' . preg_quote($old, '/') . '(?=[,\s]|$)/m',
			$new,
			$content
		);
		if ($updated !== null && $updated !== $content) {
			file_put_contents($file, $updated);
		}
	}

	/**
	 * Verschiebt den bisherigen Inhalt des Basisordners nach web/.
	 *
	 * Läuft über den Zwischenordner .web-migrating/: Der Inhalt landet zuerst dort,
	 * ein Eintrag nach dem anderen. Existiert der Zwischenordner schon von einem
	 * früheren, abgebrochenen Lauf, setzt diese Methode einfach dort fort, statt zu
	 * scheitern. Bevor ein Eintrag verschoben wird, prüft sie, ob im Zwischenordner
	 * schon etwas gleichnamiges liegt – dann bricht sie mit einer Ausnahme ab, statt
	 * es stillschweigend zu überschreiben. Erst wenn der komplette Basisordner leer
	 * verschoben ist, wird der Zwischenordner in einem Schritt zu web/ umbenannt; bis
	 * dahin bleibt web/ nicht vorhanden, und needsMigration() bleibt wahr, sodass ein
	 * erneuter Lauf automatisch fortsetzt statt den Host für fertig zu halten.
	 */
	private function moveContentIntoWeb(string $base, string $webDir): void
	{
		if (is_link($webDir)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $webDir");
		}
		$staging = $base . '/' . self::STAGING_DIRNAME;
		if (is_link($staging)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: $staging");
		}
		if (!is_dir($staging) && !mkdir($staging, 0775)) {
			throw new \RuntimeException("Kann $staging nicht anlegen");
		}
		foreach (scandir($base) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === self::STAGING_DIRNAME) {
				continue;
			}
			$from = $base . '/' . $entry;
			$to = $staging . '/' . $entry;
			if (file_exists($to) || is_link($to)) {
				throw new \RuntimeException("Ziel existiert bereits, wird nicht überschrieben: $to");
			}
			if (!rename($from, $to)) {
				throw new \RuntimeException("Kann $from nicht nach $to verschieben");
			}
		}
		if (!rename($staging, $webDir)) {
			throw new \RuntimeException("Kann $staging nicht nach $webDir umbenennen");
		}
	}

	/**
	 * Verschiebt vorhandene nginx-Logdateien des vHosts nach logs/.
	 */
	private function moveLogs(Vhost $vhost): void
	{
		$pairs = [
			$this->nginxLogDir . '/' . $vhost->slug() . '.access.log' => $this->layout->accessLog($vhost),
			$this->nginxLogDir . '/' . $vhost->slug() . '.error.log' => $this->layout->errorLog($vhost),
		];
		foreach ($pairs as $from => $to) {
			if (is_file($from) && !is_link($from) && !file_exists($to)) {
				rename($from, $to);
			}
		}
	}

	/**
	 * Legt ein Verzeichnis an, falls es fehlt, und setzt seine Soll-Rechte.
	 */
	private function ensureDirectory(DirectorySpec $spec): void
	{
		if (is_link($spec->path)) {
			throw new \RuntimeException("Symlink gehört hier nicht hin, wird nicht angefasst: {$spec->path}");
		}
		if (!is_dir($spec->path) && !mkdir($spec->path, 0775, true)) {
			throw new \RuntimeException("Kann {$spec->path} nicht anlegen");
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($spec->path, $spec->owner);
			chgrp($spec->path, $spec->group);
		}
		chmod($spec->path, $spec->mode);
	}
}
