<?php
declare(strict_types=1);

/**
 * Köder des Honigtopfs: eine phpinfo-Seite und eine .env mit erfundenen Werten.
 *
 * Beide gehören zu den meistgefragten Pfaden im Log. Statt eines 404 bekommt der
 * Scanner, was er sucht – mit einer Besonderheit: Jede Auslieferung trägt eine eigene
 * Kennung (die ersten zehn Stellen der nginx-$request_id), als Benutzername
 * „deploy-<kennung>" und als Schlüssel in einer internen Adresse. Taucht die Kennung
 * später wieder auf – in einer Anfrage oder einem Anmeldeversuch –, ist belegt, dass der
 * Fund ausgewertet und benutzt wurde, und von welchem Abruf er stammt.
 *
 * Alles darin ist erfunden und gilt nirgends. Mitgeschrieben wird nichts, was ein
 * Besucher eingibt: Die Kennung steckt in dem, was WIR ausliefern.
 *
 * Ausgeliefert wird statisch über nginx (sub_filter setzt die Kennung ein), ohne PHP auf
 * dem Honigtopf. Diese Klasse ist die einzige Quelle für die Pfadmuster – nginx
 * (httpConfig) und die Auswertung (kindOf) benutzen dieselben.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:00
 */

namespace Honeypot;

final class Decoys
{
	/**
	 * Pfadmuster je Köder (PCRE, ohne Begrenzer, gross/klein egal), geprüft gegen den
	 * dekodierten Pfad ($uri). Seit 2026-09-27 auch wp-config.php samt Sicherungskopien,
	 * config.php-Varianten und Apaches Status- und Infoseite – alle im Log gefragt.
	 */
	public const PATTERNS = [
		'phpinfo' => '^/(?:[A-Za-z0-9_-]+/)?(?:phpinfo|php_info|php-info|pinfo|info|infos|old_phpinfo|_phpinfo|i|test)\.php$',
		'env' => '^/(?:[A-Za-z0-9_-]+/)?\.env(?:\.[A-Za-z]+)?$',
		'wpconfig' => '^/(?:[A-Za-z0-9_-]+/)?\.?wp-config(?:\.php)?(?:\.(?:bak|old|save|orig|txt|backup|swp|dist)|~)?$',
		'config' => '^/(?:[A-Za-z0-9_-]+/)?config(?:[._-](?:dev|local|prod|production|staging|inc|test|sample))*\.php(?:\.(?:bak|old|save|orig|txt)|~)?$',
		'serverstatus' => '^/server-status(?:\.php)?/?$',
		'serverinfo' => '^/server-info(?:\.php)?/?$',
	];

	/**
	 * Muster gegen die ROHE Anfrage ($request_uri, samt Abfrage, nicht dekodiert): ein
	 * Ausbruch aus dem Verzeichnis über einen Parameter. Kodierte Schreibweisen
	 * (%2e%2e, %2f) stehen deshalb im Muster selbst.
	 */
	public const QUERY_PATTERNS = [
		'awscreds' => '[?&][A-Za-z0-9_-]+=[^&]*(?:\.\.|%2e%2e)(?:/|%2f)[^&]*\.aws(?:/|%2f)credentials',
	];

	/** Datei je Köder im Ordner /.koeder/ des Docroots. */
	public const FILES = [
		'phpinfo' => 'phpinfo.html',
		'env' => 'env.txt',
		'wpconfig' => 'wp-config.txt',
		'config' => 'config.txt',
		'serverstatus' => 'server-status.html',
		'serverinfo' => 'server-info.html',
		'awscreds' => 'aws-credentials.txt',
	];

	/** Platzhalter in den Köderdateien; nginx ersetzt sie bei jeder Auslieferung. */
	public const TOKEN_PLACEHOLDER = 'KOEDERKENNUNG';
	public const HOST_PLACEHOLDER = 'KOEDERHOST';
	/** Uhrzeit der Auslieferung (für die Statusseite, die sonst stehen bliebe). */
	public const TIME_PLACEHOLDER = 'KOEDERZEIT';

	/** Name des nginx-Logformats und der Logdatei mit den ausgegebenen Kennungen. */
	public const LOG_FORMAT = 'vhostadmin_decoy';
	public const LOG_FILE = 'decoy.log';

	/** Markierungen des verwalteten Abschnitts in den eigenen Direktiven. */
	public const BEGIN = '# <honigtopf-koeder> verwaltet von honeypot/install-site.sh - nicht von Hand aendern';
	public const END = '# </honigtopf-koeder>';

	/**
	 * Welcher Köder zu einem Pfad gehört; null, wenn keiner. Der Pfad darf eine
	 * Abfrage tragen und prozentkodiert sein – nginx vergleicht den dekodierten $uri.
	 */
	public static function kindOf(string $path): ?string
	{
		// Zuerst die rohe Anfrage (Ausbruch über einen Parameter), dann der Pfad.
		foreach (self::QUERY_PATTERNS as $kind => $pattern) {
			if (preg_match('#' . $pattern . '#i', $path) === 1) {
				return $kind;
			}
		}
		$path = rawurldecode(explode('?', $path, 2)[0]);
		foreach (self::PATTERNS as $kind => $pattern) {
			if (preg_match('#' . $pattern . '#i', $path) === 1) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * Kennungen in einem Text: genau zehn Hexziffern, frei stehend (nicht an Buchstaben oder Ziffern).
	 *
	 * @return list<string>
	 */
	public static function tokensIn(string $text): array
	{
		preg_match_all('/(?<![0-9A-Za-z])[0-9a-f]{10}(?![0-9A-Za-z])/', $text, $m);
		return array_values(array_unique($m[0]));
	}

	/**
	 * nginx-Konfiguration für die http-Ebene (/etc/nginx/conf.d/): Kennung aus der
	 * $request_id, Zuordnung Pfad => Köderdatei und das Logformat mit der Kennung.
	 */
	public static function httpConfig(): string
	{
		$out = "# generiert von honeypot/install-site.sh - Koeder des Honigtopfs (http-Ebene)\n"
			. "map \$request_id \$vhostadmin_decoy_token {\n"
			. "    default \"\";\n"
			. "    \"~^(?<vhostadmin_decoy_short>[0-9a-f]{10})\" \$vhostadmin_decoy_short;\n"
			. "}\n"
			. "map \$uri \$vhostadmin_decoy_file {\n"
			. "    default \"\";\n";
		foreach (self::PATTERNS as $kind => $pattern) {
			$out .= '    "~*' . $pattern . '" ' . self::FILES[$kind] . ";\n";
		}
		$out .= "}\n"
			. "map \$request_uri \$vhostadmin_decoy_query {\n"
			. "    default \"\";\n";
		foreach (self::QUERY_PATTERNS as $kind => $pattern) {
			$out .= '    "~*' . $pattern . '" ' . self::FILES[$kind] . ";\n";
		}
		return $out . "}\n"
			. 'log_format ' . self::LOG_FORMAT . " escape=json '{\"time\":\"\$time_iso8601\",\"token\":\"\$vhostadmin_decoy_token\","
			. "\"request\":\"\$request\",\"status\":\"\$status\",\"agent\":\"\$http_user_agent\",\"ip\":\"\$remote_addr\"}';\n";
	}

	/**
	 * Die eigenen Direktiven für den Server-Block des Honigtopfs.
	 *
	 * Umgeleitet wird auf Server-Ebene (rewrite läuft vor der Wahl des location-Blocks):
	 * Die Regeln des Wrappers für Punktdateien und .php sind reguläre Ausdrücke und
	 * stünden sonst vor jedem location-Block, den ein Snippet anhängen kann.
	 */
	public static function serverSnippet(string $logsDir): string
	{
		return self::BEGIN . "\n"
			// Der Ausbruch über einen Parameter zuerst; das "?" am Ende wirft die Abfrage ab.
			. "if (\$vhostadmin_decoy_query != \"\") {\n"
			. "    rewrite ^ /.koeder/\$vhostadmin_decoy_query? last;\n"
			. "}\n"
			. "if (\$vhostadmin_decoy_file != \"\") {\n"
			. "    rewrite ^ /.koeder/\$vhostadmin_decoy_file last;\n"
			. "}\n"
			. "location ^~ /.koeder/ {\n"
			. "    internal;\n"
			. "    expires -1;\n"
			. "    sub_filter_types text/plain;\n"
			. "    sub_filter_once off;\n"
			. "    sub_filter '" . self::TOKEN_PLACEHOLDER . "' '\$vhostadmin_decoy_token';\n"
			. "    sub_filter '" . self::HOST_PLACEHOLDER . "' '\$server_name';\n"
			. "    sub_filter '" . self::TIME_PLACEHOLDER . "' '\$time_local';\n"
			. "    access_log $logsDir/access.log;\n"
			. "    access_log $logsDir/" . self::LOG_FILE . ' ' . self::LOG_FORMAT . ";\n"
			. "}\n"
			// Punktdateien beantworten UND protokollieren. Der Wrapper verweigert sie mit
			// access_log off – auf einem Honigtopf verschwände /.git/config spurlos.
			. "location ^~ /. {\n"
			. "    return 404;\n"
			. "}\n"
			. self::END . "\n";
	}

	/**
	 * Setzt den Köderabschnitt in bestehende eigene Direktiven ein: ersetzt einen
	 * vorhandenen, sonst angehängt. Alles andere bleibt, wie es ist.
	 */
	public static function merge(string $existing, string $block): string
	{
		$start = strpos($existing, self::BEGIN);
		if ($start !== false) {
			$end = strpos($existing, self::END, $start);
			$end = $end === false ? strlen($existing) : $end + strlen(self::END);
			if (($existing[$end] ?? '') === "\n") {
				$end++;
			}
			return substr($existing, 0, $start) . $block . substr($existing, $end);
		}
		if ($existing === '') {
			return $block;
		}
		return rtrim($existing, "\n") . "\n" . $block;
	}
}
