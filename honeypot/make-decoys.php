<?php
declare(strict_types=1);

/**
 * Erzeugt die Köderdateien des Honigtopfs: phpinfo.html, env.txt und (seit 2026-09-27)
 * wp-config.txt, config.txt, server-status.html, server-info.html, aws-credentials.txt.
 *
 * Die phpinfo-Seite folgt Aufbau, Stil und Reihenfolge einer echten phpinfo()-Ausgabe
 * von PHP 8.1 unter Ubuntu 22.04 mit php-fpm – ein Scanner, der die Seite nach
 * Merkmalen prüft („PHP Version", Tabellenklassen e/v/h, Titel), soll sie für echt
 * halten. Jeder Wert ist erfunden: kein Pfad, kein Name, keine Version dieses Rechners.
 *
 * Drei Platzhalter ersetzt nginx bei jeder Auslieferung (siehe Honeypot\Decoys):
 * KOEDERKENNUNG durch die Kennung des Abrufs, KOEDERHOST durch den Servernamen und
 * KOEDERZEIT durch die Uhrzeit (nur die Statusseite braucht sie).
 *
 * Adressen in den Ködern stammen ausschliesslich aus den Dokumentationsbereichen
 * (RFC 5737: 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24) – keine echte Gegenstelle.
 *
 * Aufruf: php honeypot/make-decoys.php honeypot/site/koeder
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 16:20
 */

const TOKEN = 'KOEDERKENNUNG';
const HOST = 'KOEDERHOST';
const TIME = 'KOEDERZEIT';
const VERSION = '8.1.2-1ubuntu2.19';

/**
 * Erfundene Zugangsdaten. Fest, damit die Dateien reproduzierbar sind; gültig sind sie
 * nirgends. Nur die Kennung unterscheidet einen Abruf vom anderen.
 */
const SECRETS = [
	'DB_PASSWORD' => 'Rk7#vQ2m!pLw9sZe',
	'MAIL_PASSWORD' => 'n0reply-Hx4t2Vb8',
	'DEPLOY_PASSWORD' => 'Tz9!mW3q#Ld6Yp2k',
	'APP_KEY' => 'base64:q3Vt8mPz4Lw1sYc7Hn2Rk9Dj6Fb0Xe5Ga3Uo8Ti1Ms=',
	'WP_DB_PASSWORD' => 'Wq4#pZ9v!Lm2Tx7r',
	'CONFIG_DB_PASSWORD' => 'dev-Jk8s!3Nq#Vw5z',
	// Erfundene AWS-Schlüssel im gültigen Format. Gelten nirgends; die Kennung steckt
	// in der endpoint_url des zweiten Profils.
	'AWS_KEY_1' => 'AKIAQ7ZV3M2K8TXN4PLE',
	'AWS_SECRET_1' => 'Hv3Kp9sQ2wLm7Zt4Rb8Nx1Cy6Df0Gj5Ae3Uo7Ti2',
	'AWS_KEY_2' => 'AKIAR4WX9N6T2QJM8VKC',
	'AWS_SECRET_2' => 'Pz8Lq3Vn6Wt1Kc9Hs4Jm7Rb2Xf5Dg0Ya8Ue3Oi6Tq',
];

/**
 * Zufall mit festem Startwert: Die Dateien sehen bei jedem Lauf gleich aus und bleiben
 * im Repository nachvollziehbar.
 */
function fixed_random_string(int $length, string $alphabet, int $seed): string
{
	mt_srand($seed);
	$out = '';
	for ($i = 0; $i < $length; $i++) {
		$out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
	}
	return $out;
}

/** Umgebung des FPM-Pools, wie sie phpinfo() unter „Environment" zeigt. */
function environment(): array
{
	return [
		'USER' => 'www-data',
		'HOME' => '/var/www',
		'APP_ENV' => 'production',
		'APP_URL' => 'https://' . HOST,
		'DB_CONNECTION' => 'mysql',
		'DB_HOST' => '127.0.0.1',
		'DB_PORT' => '3306',
		'DB_DATABASE' => 'shop_prod',
		'DB_USERNAME' => 'shop',
		'DB_PASSWORD' => SECRETS['DB_PASSWORD'],
		'DEPLOY_USER' => 'deploy-' . TOKEN,
		'DEPLOY_PASSWORD' => SECRETS['DEPLOY_PASSWORD'],
		'INTERNAL_STATUS_URL' => 'https://' . HOST . '/api/internal/status?key=' . TOKEN,
		'BACKUP_URL' => 'https://' . HOST . '/backup/db-' . TOKEN . '.sql.gz',
	];
}

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Tabelle Schlüssel => Wert (Klassen e/v wie im Original, mit Leerzeichen am Zellenende). */
function pairs(array $rows): string
{
	$out = "<table>\n";
	foreach ($rows as $key => $value) {
		$shown = $value === '' ? '<i>no value</i>' : h((string)$value);
		$out .= '<tr><td class="e">' . h((string)$key) . ' </td><td class="v">' . $shown . " </td></tr>\n";
	}
	return $out . "</table>\n";
}

/** Tabelle mit Local Value / Master Value – die Direktiven eines Moduls. */
function directives(array $rows): string
{
	$out = "<table>\n<tr class=\"h\"><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>\n";
	foreach ($rows as $key => $value) {
		$shown = $value === '' ? '<i>no value</i>' : h((string)$value);
		$out .= '<tr><td class="e">' . h((string)$key) . '</td><td class="v">' . $shown . '</td><td class="v">' . $shown . "</td></tr>\n";
	}
	return $out . "</table>\n";
}

/** Ein Modul: Überschrift mit Anker, Kenndaten, Direktiven. */
function module(string $name, array $info, array $directives = []): string
{
	$out = '<h2><a name="module_' . h(strtolower($name)) . '">' . h($name) . "</a></h2>\n";
	if ($info !== []) {
		$out .= pairs($info);
	}
	if ($directives !== []) {
		$out .= directives($directives);
	}
	return $out;
}

function phpinfo_page(): string
{
	$css = <<<'CSS'
body {background-color: #fff; color: #222; font-family: sans-serif;}
pre {margin: 0; font-family: monospace;}
a:link {color: #009; text-decoration: none; background-color: #fff;}
a:hover {text-decoration: underline;}
table {border-collapse: collapse; border: 0; width: 934px; box-shadow: 1px 2px 3px #ccc;}
.center {text-align: center;}
.center table {margin: 1em auto; text-align: left;}
.center th {text-align: center !important;}
td, th {border: 1px solid #666; font-size: 75%; vertical-align: baseline; padding: 4px 5px;}
th {position: sticky; top: 0; background: inherit;}
h1 {font-size: 150%;}
h2 {font-size: 125%;}
.p {text-align: left;}
.e {background-color: #ccf; width: 300px; font-weight: bold;}
.h {background-color: #99c; font-weight: bold;}
.v {background-color: #ddd; max-width: 300px; overflow-x: auto; word-wrap: break-word;}
.v i {color: #999;}
img {float: right; border: 0;}
hr {width: 934px; background-color: #ccc; border: 0; height: 1px;}
CSS;

	$out = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">' . "\n"
		. "<html xmlns=\"http://www.w3.org/1999/xhtml\"><head>\n<style type=\"text/css\">\n$css\n</style>\n"
		. '<title>PHP ' . VERSION . ' - phpinfo()</title><meta name="ROBOTS" content="NOINDEX,NOFOLLOW,NOARCHIVE" /></head>' . "\n"
		. "<body><div class=\"center\">\n<table>\n<tr class=\"h\"><td>\n"
		. '<h1 class="p">PHP Version ' . VERSION . "</h1>\n</td></tr>\n</table>\n";

	$out .= pairs([
		'System' => 'Linux web01 5.15.0-119-generic #129-Ubuntu SMP Fri Aug 2 19:25:20 UTC 2024 x86_64',
		'Build Date' => 'Jun 13 2024 15:21:04',
		'Build System' => 'Linux',
		'Server API' => 'FPM/FastCGI',
		'Virtual Directory Support' => 'disabled',
		'Configuration File (php.ini) Path' => '/etc/php/8.1/fpm',
		'Loaded Configuration File' => '/etc/php/8.1/fpm/php.ini',
		'Scan this dir for additional .ini files' => '/etc/php/8.1/fpm/conf.d',
		'Additional .ini files parsed' => '/etc/php/8.1/fpm/conf.d/10-mysqlnd.ini, /etc/php/8.1/fpm/conf.d/10-opcache.ini, '
			. '/etc/php/8.1/fpm/conf.d/10-pdo.ini, /etc/php/8.1/fpm/conf.d/15-xml.ini, /etc/php/8.1/fpm/conf.d/20-calendar.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-ctype.ini, /etc/php/8.1/fpm/conf.d/20-curl.ini, /etc/php/8.1/fpm/conf.d/20-dom.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-exif.ini, /etc/php/8.1/fpm/conf.d/20-fileinfo.ini, /etc/php/8.1/fpm/conf.d/20-ftp.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-gd.ini, /etc/php/8.1/fpm/conf.d/20-gettext.ini, /etc/php/8.1/fpm/conf.d/20-iconv.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-intl.ini, /etc/php/8.1/fpm/conf.d/20-mbstring.ini, /etc/php/8.1/fpm/conf.d/20-mysqli.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-pdo_mysql.ini, /etc/php/8.1/fpm/conf.d/20-phar.ini, /etc/php/8.1/fpm/conf.d/20-posix.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-simplexml.ini, /etc/php/8.1/fpm/conf.d/20-sockets.ini, /etc/php/8.1/fpm/conf.d/20-tokenizer.ini, '
			. '/etc/php/8.1/fpm/conf.d/20-xmlreader.ini, /etc/php/8.1/fpm/conf.d/20-xmlwriter.ini, /etc/php/8.1/fpm/conf.d/20-zip.ini',
		'PHP API' => '20210902',
		'PHP Extension' => '20210902',
		'Zend Extension' => '420210902',
		'Zend Extension Build' => 'API420210902,NTS',
		'PHP Extension Build' => 'API20210902,NTS',
		'Debug Build' => 'no',
		'Thread Safety' => 'disabled',
		'Zend Signal Handling' => 'enabled',
		'Zend Memory Manager' => 'enabled',
		'Zend Multibyte Support' => 'provided by mbstring',
		'IPv6 Support' => 'enabled',
		'DTrace Support' => 'available, disabled',
		'Registered PHP Streams' => 'https, ftps, compress.zlib, php, file, glob, data, http, ftp, phar, zip',
		'Registered Stream Socket Transports' => 'tcp, udp, unix, udg, ssl, tls, tlsv1.0, tlsv1.1, tlsv1.2, tlsv1.3',
		'Registered Stream Filters' => 'zlib.*, string.rot13, string.toupper, string.tolower, convert.*, consumed, dechunk, convert.iconv.*',
	]);
	$out .= "<table>\n<tr class=\"v\"><td>\nThis program makes use of the Zend Scripting Language Engine:<br />"
		. "Zend Engine v4.1.2, Copyright (c) Zend Technologies<br />    with Zend OPcache v8.1.2-1ubuntu2.19, "
		. "Copyright (c), by Zend Technologies<br />\n</td></tr>\n</table>\n<hr />\n<h1>Configuration</h1>\n";

	$out .= module('cgi-fcgi', [], [
		'cgi.check_shebang_line' => '1', 'cgi.discard_path' => '0', 'cgi.fix_pathinfo' => '1',
		'cgi.force_redirect' => '1', 'cgi.nph' => '0', 'cgi.redirect_status_env' => '',
		'cgi.rfc2616_headers' => '0', 'fastcgi.error_header' => '', 'fastcgi.logging' => '0',
		'fpm.config' => '', 'fastcgi.impersonate' => '0',
	]);
	$out .= module('Core', ['PHP Version' => VERSION], [
		'allow_url_fopen' => 'On', 'allow_url_include' => 'Off', 'arg_separator.input' => '&',
		'arg_separator.output' => '&', 'auto_append_file' => '', 'auto_globals_jit' => 'On',
		'auto_prepend_file' => '', 'browscap' => '', 'default_charset' => 'UTF-8',
		'default_mimetype' => 'text/html', 'disable_classes' => '',
		'disable_functions' => 'pcntl_alarm,pcntl_fork,pcntl_waitpid,pcntl_wait,pcntl_wifexited,pcntl_wifstopped,pcntl_wifsignaled,pcntl_wifcontinued,pcntl_wexitstatus,pcntl_wtermsig,pcntl_wstopsig,pcntl_signal,pcntl_signal_get_handler,pcntl_signal_dispatch,pcntl_get_last_error,pcntl_strerror,pcntl_sigprocmask,pcntl_sigwaitinfo,pcntl_sigtimedwait,pcntl_exec,pcntl_getpriority,pcntl_setpriority,pcntl_async_signals,pcntl_unshare,',
		'display_errors' => 'Off', 'display_startup_errors' => 'Off', 'doc_root' => '',
		'docref_ext' => '', 'docref_root' => '', 'enable_dl' => 'Off', 'enable_post_data_reading' => 'On',
		'error_append_string' => '', 'error_log' => '', 'error_prepend_string' => '',
		'error_reporting' => '22527', 'expose_php' => 'Off', 'extension_dir' => '/usr/lib/php/20210902',
		'file_uploads' => 'On', 'hard_timeout' => '2', 'html_errors' => 'On', 'ignore_repeated_errors' => 'Off',
		'ignore_user_abort' => 'Off', 'include_path' => '.:/usr/share/php', 'input_encoding' => '',
		'internal_encoding' => '', 'log_errors' => 'On', 'mail.add_x_header' => 'Off',
		'max_execution_time' => '30', 'max_file_uploads' => '20', 'max_input_time' => '60',
		'max_input_vars' => '1000', 'memory_limit' => '256M', 'open_basedir' => '',
		'output_buffering' => '4096', 'post_max_size' => '64M', 'precision' => '14',
		'realpath_cache_size' => '4096K', 'realpath_cache_ttl' => '120', 'register_argc_argv' => 'Off',
		'report_memleaks' => 'On', 'request_order' => 'GP', 'sendmail_path' => '/usr/sbin/sendmail -t -i ',
		'serialize_precision' => '-1', 'short_open_tag' => 'Off', 'SMTP' => 'localhost', 'smtp_port' => '25',
		'sys_temp_dir' => '', 'upload_max_filesize' => '64M', 'upload_tmp_dir' => '',
		'user_dir' => '', 'variables_order' => 'GPCS', 'xmlrpc_errors' => 'Off', 'zend.enable_gc' => 'On',
	]);
	$out .= module('ctype', ['ctype functions' => 'enabled']);
	$out .= module('curl', [
		'cURL support' => 'enabled', 'cURL Information' => '7.81.0', 'Age' => '9', 'SSL' => 'Yes',
		'SSL Version' => 'OpenSSL/3.0.2', 'ZLib Version' => '1.2.11', 'libSSH Version' => 'libssh/0.9.6/openssl/zlib',
	], ['curl.cainfo' => '']);
	$out .= module('date', [
		'date/time support' => 'enabled', 'timelib version' => '2021.19', '"Olson" Timezone Database Version' => '0.system',
		'Timezone Database' => 'internal', 'Default timezone' => 'Europe/Berlin',
	], ['date.default_latitude' => '31.7667', 'date.default_longitude' => '35.2333', 'date.timezone' => 'Europe/Berlin']);
	$out .= module('dom', ['DOM/XML' => 'enabled', 'DOM/XML API Version' => '20031129', 'libxml Version' => '2.9.13']);
	$out .= module('fileinfo', ['fileinfo support' => 'enabled', 'libmagic' => '540']);
	$out .= module('filter', ['Input Validation and Filtering' => 'enabled'], ['filter.default' => 'unsafe_raw', 'filter.default_flags' => '']);
	$out .= module('gd', [
		'GD Support' => 'enabled', 'GD Version' => '2.3.0', 'FreeType Support' => 'enabled',
		'GIF Read Support' => 'enabled', 'JPEG Support' => 'enabled', 'PNG Support' => 'enabled', 'WebP Support' => 'enabled',
	], ['gd.jpeg_ignore_warning' => '1']);
	$out .= module('hash', ['hash support' => 'enabled']);
	$out .= module('iconv', ['iconv support' => 'enabled', 'iconv implementation' => 'glibc', 'iconv library version' => '2.35']);
	$out .= module('json', ['json support' => 'enabled']);
	$out .= module('libxml', ['libXML support' => 'active', 'libXML Compiled Version' => '2.9.13', 'libXML Loaded Version' => '20913']);
	$out .= module('mbstring', ['Multibyte Support' => 'enabled', 'Multibyte string engine' => 'libmbfl', 'libmbfl version' => '1.3.2'], [
		'mbstring.detect_order' => '', 'mbstring.encoding_translation' => 'Off', 'mbstring.func_overload' => '0',
		'mbstring.language' => 'neutral', 'mbstring.substitute_character' => '',
	]);
	$out .= module('mysqli', [
		'MysqlI Support' => 'enabled', 'Client API library version' => 'mysqlnd 8.1.2-1ubuntu2.19',
		'Active Persistent Links' => '0', 'Inactive Persistent Links' => '0', 'Active Links' => '0',
	], [
		'mysqli.allow_local_infile' => 'Off', 'mysqli.allow_persistent' => 'On', 'mysqli.default_host' => '',
		'mysqli.default_port' => '3306', 'mysqli.default_pw' => '', 'mysqli.default_socket' => '',
		'mysqli.default_user' => '', 'mysqli.max_links' => 'Unlimited', 'mysqli.max_persistent' => 'Unlimited',
		'mysqli.reconnect' => 'Off',
	]);
	$out .= module('mysqlnd', ['mysqlnd' => 'enabled', 'Version' => 'mysqlnd 8.1.2-1ubuntu2.19', 'Compression' => 'supported', 'SSL' => 'supported']);
	$out .= module('openssl', [
		'OpenSSL support' => 'enabled', 'OpenSSL Library Version' => 'OpenSSL 3.0.2 15 Mar 2022',
		'OpenSSL Header Version' => 'OpenSSL 3.0.2 15 Mar 2022', 'Openssl default config' => '/usr/lib/ssl/openssl.cnf',
	], ['openssl.cafile' => '', 'openssl.capath' => '']);
	$out .= module('pcre', ['PCRE (Perl Compatible Regular Expressions) Support' => 'enabled', 'PCRE Library Version' => '10.39 2021-10-29', 'PCRE JIT Support' => 'enabled'], [
		'pcre.backtrack_limit' => '1000000', 'pcre.jit' => '1', 'pcre.recursion_limit' => '100000',
	]);
	$out .= module('PDO', ['PDO support' => 'enabled', 'PDO drivers' => 'mysql']);
	$out .= module('pdo_mysql', ['PDO Driver for MySQL' => 'enabled', 'Client API version' => 'mysqlnd 8.1.2-1ubuntu2.19'], ['pdo_mysql.default_socket' => '/var/run/mysqld/mysqld.sock']);
	$out .= module('Phar', ['Phar: PHP Archive support' => 'enabled', 'Phar API version' => '1.1.1'], [
		'phar.cache_list' => '', 'phar.readonly' => 'On', 'phar.require_hash' => 'On',
	]);
	$out .= module('session', ['Session Support' => 'enabled', 'Registered save handlers' => 'files user', 'Registered serializer handlers' => 'php_serialize php php_binary'], [
		'session.auto_start' => 'Off', 'session.cookie_httponly' => '', 'session.cookie_lifetime' => '0',
		'session.cookie_path' => '/', 'session.cookie_samesite' => '', 'session.cookie_secure' => '0',
		'session.gc_maxlifetime' => '1440', 'session.name' => 'PHPSESSID', 'session.save_handler' => 'files',
		'session.save_path' => '/var/lib/php/sessions', 'session.use_cookies' => '1', 'session.use_strict_mode' => '0',
	]);
	$out .= module('SimpleXML', ['SimpleXML support' => 'enabled']);
	$out .= module('sockets', ['Sockets Support' => 'enabled']);
	$out .= module('sodium', ['sodium support' => 'enabled', 'libsodium headers version' => '1.0.18', 'libsodium library version' => '1.0.18']);
	$out .= module('SPL', ['SPL support' => 'enabled']);
	$out .= module('standard', ['Dynamic Library Support' => 'enabled', 'Path to sendmail' => '/usr/sbin/sendmail -t -i'], [
		'assert.active' => 'On', 'assert.exception' => 'On', 'auto_detect_line_endings' => 'Off',
		'default_socket_timeout' => '60', 'from' => '', 'session.trans_sid_hosts' => '',
		'session.trans_sid_tags' => 'a=href,area=href,frame=src,form=', 'unserialize_max_depth' => '4096',
		'url_rewriter.hosts' => '', 'url_rewriter.tags' => 'form=', 'user_agent' => '',
	]);
	$out .= module('tokenizer', ['Tokenizer Support' => 'enabled']);
	$out .= module('xml', ['XML Support' => 'active', 'XML Namespace Support' => 'active', 'libxml2 Version' => '2.9.13']);
	$out .= module('Zend OPcache', ['Opcode Caching' => 'Up and Running', 'Optimization' => 'Enabled', 'JIT' => 'Disabled'], [
		'opcache.enable' => 'On', 'opcache.enable_cli' => 'Off', 'opcache.memory_consumption' => '128',
		'opcache.max_accelerated_files' => '10000', 'opcache.revalidate_freq' => '2', 'opcache.validate_timestamps' => 'On',
	]);
	$out .= module('zip', ['Zip' => 'enabled', 'Zip version' => '1.19.5', 'Libzip version' => '1.7.3']);
	$out .= module('zlib', ['ZLib Support' => 'enabled', 'Compiled Version' => '1.2.11', 'Linked Version' => '1.2.11'], [
		'zlib.output_compression' => 'Off', 'zlib.output_compression_level' => '-1', 'zlib.output_handler' => '',
	]);

	$env = environment();
	$out .= "<h2>Environment</h2>\n<table>\n<tr class=\"h\"><th>Variable</th><th>Value</th></tr>\n";
	foreach ($env as $key => $value) {
		$out .= '<tr><td class="e">' . h($key) . ' </td><td class="v">' . h($value) . " </td></tr>\n";
	}
	$out .= "</table>\n";

	$server = $env + [
		'HTTP_HOST' => HOST,
		'HTTP_ACCEPT' => '*/*',
		'REDIRECT_STATUS' => '200',
		'SERVER_NAME' => HOST,
		'SERVER_PORT' => '443',
		'SERVER_ADDR' => '172.17.0.2',
		'REMOTE_PORT' => '51724',
		'REMOTE_ADDR' => '172.17.0.1',
		'SERVER_SOFTWARE' => 'nginx/1.18.0',
		'GATEWAY_INTERFACE' => 'CGI/1.1',
		'HTTPS' => 'on',
		'REQUEST_SCHEME' => 'https',
		'SERVER_PROTOCOL' => 'HTTP/1.1',
		'DOCUMENT_ROOT' => '/var/www/shop/public',
		'DOCUMENT_URI' => '/phpinfo.php',
		'REQUEST_URI' => '/phpinfo.php',
		'SCRIPT_NAME' => '/phpinfo.php',
		'CONTENT_LENGTH' => '',
		'CONTENT_TYPE' => '',
		'REQUEST_METHOD' => 'GET',
		'QUERY_STRING' => '',
		'SCRIPT_FILENAME' => '/var/www/shop/public/phpinfo.php',
		'FCGI_ROLE' => 'RESPONDER',
		'PHP_SELF' => '/phpinfo.php',
	];
	$out .= "<h2>PHP Variables</h2>\n<table>\n<tr class=\"h\"><th>Variable</th><th>Value</th></tr>\n";
	foreach ($server as $key => $value) {
		$shown = $value === '' ? '<i>no value</i>' : h($value);
		$out .= '<tr><td class="e">$_SERVER[\'' . h($key) . '\']</td><td class="v">' . $shown . "</td></tr>\n";
	}
	$out .= "</table>\n<hr />\n<h2>PHP License</h2>\n<table>\n<tr class=\"v\"><td>\n<p>\n"
		. "This program is free software; you can redistribute it and/or modify it under the terms of the PHP License as published by the PHP Group and included in the distribution in the file:  LICENSE\n"
		. "</p>\n<p>This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.\n"
		. "</p>\n<p>If you did not receive a copy of the PHP license, or have any questions about PHP licensing, please contact license@php.net.\n"
		. "</p>\n</td></tr>\n</table>\n</div></body></html>";
	return $out;
}

function env_file(): string
{
	return "APP_NAME=Shop\n"
		. "APP_ENV=production\n"
		. 'APP_KEY=' . SECRETS['APP_KEY'] . "\n"
		. "APP_DEBUG=false\n"
		. 'APP_URL=https://' . HOST . "\n\n"
		. "LOG_CHANNEL=stack\nLOG_LEVEL=warning\n\n"
		. "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=shop_prod\nDB_USERNAME=shop\n"
		. 'DB_PASSWORD=' . SECRETS['DB_PASSWORD'] . "\n\n"
		. "CACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\n\n"
		// Mailserver bewusst lokal: Ein erfundener Name unter der Domain könnte einmal
		// ein echter Dienst werden, und dann zeigte der Köder auf ihn.
		. "MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\n"
		. 'MAIL_USERNAME=noreply@' . HOST . "\n"
		. 'MAIL_PASSWORD=' . SECRETS['MAIL_PASSWORD'] . "\n\n"
		. 'DEPLOY_USER=deploy-' . TOKEN . "\n"
		. 'DEPLOY_PASSWORD=' . SECRETS['DEPLOY_PASSWORD'] . "\n"
		. 'INTERNAL_STATUS_URL=https://' . HOST . '/api/internal/status?key=' . TOKEN . "\n"
		. 'BACKUP_URL=https://' . HOST . '/backup/db-' . TOKEN . ".sql.gz\n";
}

/** Eine wp-config.php, wie sie ein falsch eingestellter Server als Quelltext ausliefert. */
function wp_config(): string
{
	$salt = '';
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|';
	foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $i => $key) {
		$salt .= sprintf("define( '%s',%s'%s' );\n", $key, str_repeat(' ', 17 - strlen($key)), fixed_random_string(64, $alphabet, 4711 + $i));
	}
	return "<?php\n/**\n * The base configuration for WordPress\n *\n"
		. " * The wp-config.php creation script uses this file during the installation.\n"
		. " * You don't have to use the website, you can copy this file to \"wp-config.php\"\n * and fill in the values.\n *\n"
		. " * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/\n *\n * @package WordPress\n */\n\n"
		. "// ** Database settings - You can get this info from your web host ** //\n"
		. "/** The name of the database for WordPress */\ndefine( 'DB_NAME', 'wp_shop' );\n\n"
		. "/** Database username */\ndefine( 'DB_USER', 'wp_shop' );\n\n"
		. "/** Database password */\ndefine( 'DB_PASSWORD', '" . SECRETS['WP_DB_PASSWORD'] . "' );\n\n"
		. "/** Database hostname */\ndefine( 'DB_HOST', 'localhost' );\n\n"
		. "/** Database charset to use in creating database tables. */\ndefine( 'DB_CHARSET', 'utf8mb4' );\n\n"
		. "/** The database collate type. Don't change this if in doubt. */\ndefine( 'DB_COLLATE', '' );\n\n"
		. "/**#@+\n * Authentication unique keys and salts.\n */\n" . $salt . "\n/**#@-*/\n\n"
		. "\$table_prefix = 'wp_';\n\n"
		. "define( 'WP_DEBUG', false );\n\n"
		. "/* Deployment (CI) */\n"
		. "define( 'DEPLOY_USER', 'deploy-" . TOKEN . "' );\n"
		. "define( 'DEPLOY_PASSWORD', '" . SECRETS['DEPLOY_PASSWORD'] . "' );\n"
		. "define( 'BACKUP_URL', 'https://" . HOST . "/backup/wp-" . TOKEN . ".sql.gz' );\n\n"
		. "/* That's all, stop editing! Happy publishing. */\n\n"
		. "/** Absolute path to the WordPress directory. */\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n\n"
		. "/** Sets up WordPress vars and included files. */\nrequire_once ABSPATH . 'wp-settings.php';\n";
}

/** Eine config.php einer kleinen PHP-Anwendung, als Quelltext. */
function config_php(): string
{
	return "<?php\n// Konfiguration – nicht einchecken!\nreturn [\n"
		. "    'env' => 'dev',\n"
		. "    'debug' => true,\n"
		. "    'base_url' => 'https://" . HOST . "',\n"
		. "    'db' => [\n"
		. "        'dsn' => 'mysql:host=127.0.0.1;dbname=app_dev;charset=utf8mb4',\n"
		. "        'user' => 'app_dev',\n"
		. "        'password' => '" . SECRETS['CONFIG_DB_PASSWORD'] . "',\n"
		. "    ],\n"
		. "    'mail' => ['host' => '127.0.0.1', 'port' => 1025],\n"
		. "    'admin' => [\n"
		. "        'user' => 'deploy-" . TOKEN . "',\n"
		. "        'password' => '" . SECRETS['DEPLOY_PASSWORD'] . "',\n"
		. "    ],\n"
		. "    'status_url' => 'https://" . HOST . "/api/internal/status?key=" . TOKEN . "',\n"
		. "];\n";
}

/** ~/.aws/credentials – das Ziel des gefragten Ausbruchs über ?file=../… */
function aws_credentials(): string
{
	return "[default]\n"
		. 'aws_access_key_id = ' . SECRETS['AWS_KEY_1'] . "\n"
		. 'aws_secret_access_key = ' . SECRETS['AWS_SECRET_1'] . "\n"
		. "region = eu-central-1\n\n"
		. "[deploy]\n"
		. 'aws_access_key_id = ' . SECRETS['AWS_KEY_2'] . "\n"
		. 'aws_secret_access_key = ' . SECRETS['AWS_SECRET_2'] . "\n"
		. "region = eu-central-1\n"
		// endpoint_url ist eine gültige Einstellung der AWS-Werkzeuge: Wer das Profil
		// benutzt, spricht mit uns – samt Kennung.
		. 'endpoint_url = https://' . HOST . '/s3/deploy-' . TOKEN . "\n";
}

/**
 * Apaches mod_status, Stand Ubuntu 22.04. Die Anfragezeilen im Scoreboard sind die
 * Stelle für die Kennung: interne Pfade, die ein Scanner gern nachlädt.
 */
function server_status(): string
{
	$rows = [
		['0-0', '10412', '0/118/2204', '_', '1.21', '3', '0', '0.0', '0.21', '9.87', '203.0.113.24', 'http/1.1', HOST . ':443', 'GET /shop/cart HTTP/1.1'],
		['0-0', '10412', '1/97/1893', 'W', '0.88', '0', '0', '4.1', '0.18', '8.02', '127.0.0.1', 'http/1.1', HOST . ':443', 'GET /server-status HTTP/1.1'],
		['1-0', '10413', '0/131/2310', 'K', '1.34', '1', '12', '0.0', '0.25', '10.44', '198.51.100.7', 'http/1.1', HOST . ':443', 'GET /api/internal/status?key=' . TOKEN . ' HTTP/1.1'],
		['1-0', '10413', '0/88/1702', '_', '0.73', '9', '1', '0.0', '0.16', '7.35', '192.0.2.51', 'http/1.1', HOST . ':443', 'POST /wp-cron.php?doing_wp_cron HTTP/1.1'],
		['2-0', '10414', '0/64/1419', '_', '0.52', '22', '2', '0.0', '0.12', '6.10', '10.0.4.12', 'http/1.1', 'backup.internal:80', 'GET /backup/db-' . TOKEN . '.sql.gz HTTP/1.1'],
		['2-0', '10414', '0/140/2451', '_', '1.44', '31', '0', '0.0', '0.27', '11.02', '203.0.113.88', 'h2', HOST . ':443', 'GET /shop/product/42 HTTP/2.0'],
	];
	$table = '';
	foreach ($rows as $r) {
		$table .= '<tr><td><b>' . $r[0] . '</b></td><td>' . $r[1] . '</td><td>' . $r[2] . '</td><td><b>' . $r[3] . '</b></td>'
			. '<td>' . $r[4] . '</td><td>' . $r[5] . '</td><td>' . $r[6] . '</td><td>' . $r[7] . '</td><td>' . $r[8] . '</td>'
			. '<td>' . $r[9] . '</td><td>' . $r[10] . '</td><td>' . $r[11] . '</td><td nowrap>' . $r[12] . '</td>'
			. '<td nowrap>' . htmlspecialchars($r[13]) . "</td></tr>\n\n";
	}
	return "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 3.2 Final//EN\">\n<html><head>\n<title>Apache Status</title>\n</head><body>\n"
		. '<h1>Apache Server Status for ' . HOST . " (via 127.0.0.1)</h1>\n\n"
		. "<dl><dt>Server Version: Apache/2.4.52 (Ubuntu) OpenSSL/3.0.2</dt>\n<dt>Server MPM: event</dt>\n"
		. "<dt>Server Built: 2024-07-17T18:57:26\n</dt></dl><hr /><dl>\n"
		. '<dt>Current Time: ' . TIME . "</dt>\n"
		. "<dt>Restart Time: Wednesday, 09-Sep-2026 04:12:37 UTC</dt>\n"
		. "<dt>Parent Server Config. Generation: 19</dt>\n<dt>Parent Server MPM Generation: 18</dt>\n"
		. "<dt>Server uptime:  18 days 7 hours 41 minutes 2 seconds</dt>\n"
		. "<dt>Server load: 0.14 0.19 0.17</dt>\n"
		. "<dt>Total accesses: 1834220 - Total Traffic: 41.2 GB - Total Duration: 91873412</dt>\n"
		. "<dt>CPU Usage: u412.8 s188.31 cu0 cs0 - .0381% CPU load</dt>\n"
		. "<dt>1.16 requests/sec - 27.3 kB/second - 23.6 kB/request - 50.0888 ms/request</dt>\n"
		. "<dt>2 requests currently being processed, 73 idle workers</dt>\n</dl>\n"
		. "<pre>__W_K__________________________________________________________\n________________________________________________________........\n</pre>\n"
		. "<p>Scoreboard Key:<br />\n\"<b><code>_</code></b>\" Waiting for Connection, \n\"<b><code>S</code></b>\" Starting up, \n"
		. "\"<b><code>R</code></b>\" Reading Request,<br />\n\"<b><code>W</code></b>\" Sending Reply, \n\"<b><code>K</code></b>\" Keepalive (read), \n"
		. "\"<b><code>.</code></b>\" Open slot with no current process<br />\n</p>\n\n"
		. "<table border=\"0\"><tr><th>Srv</th><th>PID</th><th>Acc</th><th>M</th><th>CPU\n</th><th>SS</th><th>Req</th><th>Dur</th>"
		. "<th>Conn</th><th>Child</th><th>Slot</th><th>Client</th><th>Protocol</th><th>VHost</th><th>Request</th></tr>\n\n"
		. $table
		. "</table>\n<hr /> <table>\n <tr><th>Srv</th><td>Child Server number - generation</td></tr>\n"
		. " <tr><th>PID</th><td>OS process ID</td></tr>\n <tr><th>Acc</th><td>Number of accesses this connection / this child / this slot</td></tr>\n"
		. " <tr><th>M</th><td>Mode of operation</td></tr>\n <tr><th>Client</th><td>Client address</td></tr>\n"
		. " <tr><th>VHost</th><td>Virtual host</td></tr>\n <tr><th>Request</th><td>Request</td></tr>\n </table>\n"
		. '<hr /><address>Apache/2.4.52 (Ubuntu) Server at ' . HOST . " Port 443</address>\n</body></html>\n";
}

/** Apaches mod_info: Servereinstellungen und ein Ausschnitt der Konfiguration. */
function server_info(): string
{
	$modules = ['core.c', 'mod_so.c', 'http_core.c', 'event.c', 'mod_access_compat.c', 'mod_alias.c', 'mod_auth_basic.c',
		'mod_authn_core.c', 'mod_authn_file.c', 'mod_authz_core.c', 'mod_authz_host.c', 'mod_authz_user.c', 'mod_autoindex.c',
		'mod_deflate.c', 'mod_dir.c', 'mod_env.c', 'mod_filter.c', 'mod_headers.c', 'mod_info.c', 'mod_mime.c',
		'mod_negotiation.c', 'mod_proxy.c', 'mod_proxy_fcgi.c', 'mod_reqtimeout.c', 'mod_rewrite.c', 'mod_setenvif.c',
		'mod_socache_shmcb.c', 'mod_ssl.c', 'mod_status.c'];
	$list = implode(', ', array_map(static fn(string $m): string => '<a href="#' . $m . '">' . $m . '</a>', $modules));
	return "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n"
		. "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\"><head>\n"
		. "  <title>Server Information</title>\n</head>\n<body><h1 style=\"text-align: center\">Apache Server Information</h1>\n"
		. "<dl><dt><tt>Subpages:<br /><a href=\"?config\">Configuration Files</a>, <a href=\"?server\">Server Settings</a>, "
		. "<a href=\"?list\">Module List</a>, <a href=\"?hooks\">Active Hooks</a>, <a href=\"?providers\">Available Providers</a></tt></dt></dl><hr />\n"
		. "<dl><dt><tt>Sections:<br /><a href=\"#modules\">Loaded Modules</a>, <a href=\"#server\">Server Settings</a>, "
		. "<a href=\"#startup_hooks\">Startup Hooks</a>, <a href=\"#request_hooks\">Request Hooks</a>, <a href=\"#other_hooks\">Other Hooks</a>, "
		. "<a href=\"#providers\">Providers</a></tt></dt></dl><hr />\n"
		. "<h2><a name=\"modules\">Loaded Modules</a></h2><dl><dt><tt>" . $list . "</tt></dt></dl><hr />\n"
		. "<h2><a name=\"server\">Server Settings</a></h2><dl>\n"
		. "<dt><strong>Server Version:</strong> <font size=\"+1\"><tt>Apache/2.4.52 (Ubuntu) OpenSSL/3.0.2</tt></font></dt>\n"
		. "<dt><strong>Server Built:</strong> <font size=\"+1\"><tt>2024-07-17T18:57:26</tt></font></dt>\n"
		. "<dt><strong>Server loaded APR Version:</strong> <tt>1.7.0</tt></dt>\n"
		. "<dt><strong>Module Magic Number:</strong> <tt>20120211:121</tt></dt>\n"
		. '<dt><strong>Hostname/port:</strong> <tt>' . HOST . ":443</tt></dt>\n"
		. "<dt><strong>Timeouts:</strong> <tt>connection: 300 &nbsp;&nbsp; keep-alive: 5</tt></dt>\n"
		. "<dt><strong>MPM Name:</strong> <tt>event</tt></dt>\n"
		. "<dt><strong>MPM Information:</strong> <tt>Max Daemons: 3 Threaded: yes Forked: yes</tt></dt>\n"
		. "<dt><strong>Server Root:</strong> <tt>/etc/apache2</tt></dt>\n"
		. "<dt><strong>Config File:</strong> <tt>/etc/apache2/apache2.conf</tt></dt>\n"
		. "<dt><strong>Server Built With:</strong>\n<tt style=\"white-space: pre;\">\n -D APR_HAS_SENDFILE\n -D APR_HAS_MMAP\n -D APR_USE_PROC_PTHREAD_SERIALIZE\n"
		. " -D SINGLE_LISTEN_UNSERIALIZED_ACCEPT\n -D APR_HAS_OTHER_CHILD\n -D AP_HAVE_RELIABLE_PIPED_LOGS\n -D HTTPD_ROOT=\"/etc/apache2\"\n"
		. " -D SUEXEC_BIN=\"/usr/lib/apache2/suexec\"\n -D DEFAULT_PIDLOG=\"/var/run/apache2.pid\"\n -D SERVER_CONFIG_FILE=\"apache2.conf\"\n</tt></dt>\n</dl><hr />\n"
		. "<h2><a name=\"mod_alias.c\">Module Name:</a> <font size=\"+1\"><tt><a href=\"?mod_alias.c\">mod_alias.c</a></tt></font></h2>\n"
		. "<dl><dt><strong>Content handlers:</strong> <tt>none</tt></dt>\n<dt><strong>Current Configuration:</strong>\n</dt>\n<dd><tt>\n"
		. "In file: /etc/apache2/sites-enabled/000-shop.conf<br />\n"
		. " 14: &nbsp;&nbsp;<a href=\"http://httpd.apache.org/docs/2.4/mod/mod_alias.html#alias\">Alias</a> /backup-" . TOKEN . " /srv/backup<br />\n"
		. " 15: &nbsp;&nbsp;<a href=\"http://httpd.apache.org/docs/2.4/mod/mod_alias.html#alias\">Alias</a> /internal/status /var/www/status/index.php<br />\n"
		. "</tt></dd></dl><hr />\n"
		. "<h2><a name=\"mod_status.c\">Module Name:</a> <font size=\"+1\"><tt><a href=\"?mod_status.c\">mod_status.c</a></tt></font></h2>\n"
		. "<dl><dt><strong>Content handlers:</strong> <tt>yes</tt></dt>\n<dt><strong>Current Configuration:</strong>\n</dt>\n<dd><tt>\n"
		. "In file: /etc/apache2/mods-enabled/status.conf<br />\n"
		. " 12: &nbsp;&nbsp;<a href=\"http://httpd.apache.org/docs/2.4/mod/mod_status.html#extendedstatus\">ExtendedStatus</a> On<br />\n"
		. "</tt></dd></dl><hr />\n"
		. '<address>Apache/2.4.52 (Ubuntu) Server at ' . HOST . " Port 443</address>\n</body></html>\n";
}

$target = $argv[1] ?? '';
if ($target === '' || (!is_dir($target) && !mkdir($target, 0755, true))) {
	fwrite(STDERR, "Aufruf: php honeypot/make-decoys.php <zielordner>\n");
	exit(1);
}
file_put_contents($target . '/phpinfo.html', phpinfo_page());
file_put_contents($target . '/env.txt', env_file());
file_put_contents($target . '/wp-config.txt', wp_config());
file_put_contents($target . '/config.txt', config_php());
file_put_contents($target . '/aws-credentials.txt', aws_credentials());
file_put_contents($target . '/server-status.html', server_status());
file_put_contents($target . '/server-info.html', server_info());
echo "Köder geschrieben nach $target: phpinfo.html, env.txt, wp-config.txt, config.txt, aws-credentials.txt, server-status.html, server-info.html\n";
