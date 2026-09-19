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
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:32
 */

namespace VhostAdmin\Migration;

use VhostAdmin\Config;
use VhostAdmin\DirectorySpec;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;

final class LayoutMigrator
{
	/** Ordner der neuen Struktur, die beim Verschieben liegen bleiben. */
	private const STRUCTURE = ['web', 'conf', 'cert', 'private', 'logs'];

	/**
	 * Übernimmt Konfiguration, Repository und Layout und merkt sich, wo die
	 * bisherigen nginx-Logdateien liegen.
	 *
	 * @param string $nginxLogDir Verzeichnis der bisherigen nginx-Logdateien
	 */
	public function __construct(
		private readonly Config $config,
		private readonly VhostRepository $repository,
		private readonly VhostLayout $layout,
		private readonly string $nginxLogDir = '/var/log/nginx',
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
	}

	/**
	 * Verschiebt den bisherigen Inhalt des Basisordners nach web/.
	 */
	private function moveContentIntoWeb(string $base, string $webDir): void
	{
		if (!is_dir($webDir) && !mkdir($webDir, 0775)) {
			throw new \RuntimeException("Kann $webDir nicht anlegen");
		}
		foreach (scandir($base) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || in_array($entry, self::STRUCTURE, true)) {
				continue;
			}
			if (!rename($base . '/' . $entry, $webDir . '/' . $entry)) {
				throw new \RuntimeException("Kann $base/$entry nicht nach $webDir verschieben");
			}
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
			if (!@chown($spec->path, $spec->owner)) {
				chown($spec->path, $this->config->wwwGroup);
			}
			chgrp($spec->path, $spec->group);
		}
		chmod($spec->path, $spec->mode);
	}
}
