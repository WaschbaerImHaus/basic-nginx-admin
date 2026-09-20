<?php
declare(strict_types=1);

/**
 * Tests für die Prüfung des nginx-Snippets aus der Oberfläche.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 15:42
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\SnippetScope;

final class NginxSnippetTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function allowedSnippets(): iterable
	{
		yield 'Größenlimit' => ["client_max_body_size 64m;\n"];
		yield 'Ablauf' => ["expires 7d;\n"];
		yield 'Header' => ["add_header X-Frame-Options SAMEORIGIN;\n"];
		yield 'Header-Modul' => ["more_set_headers \"Server: nginx\";\n"];
		yield 'Zeichensatz' => ["charset utf-8;\n"];
		yield 'Verzeichnisliste' => ["autoindex on;\n"];
		yield 'Indexdateien' => ["index index.php index.html;\n"];
		yield 'Fehlerseite' => ["error_page 404 /404.html;\n"];
		yield 'Umschreibung' => ["rewrite ^/alt/(.*)$ /neu/\$1 permanent;\n"];
		yield 'Rückgabe' => ["return 301 https://example.com\$request_uri;\n"];
		yield 'Dateisuche' => ["try_files \$uri \$uri/ /index.php?\$args;\n"];
		yield 'Rate' => ["limit_rate 200k;\nlimit_rate_after 1m;\n"];
		yield 'gzip' => ["gzip on;\ngzip_types text/css application/javascript;\n"];
		// I3 (Abschlussreview): das Ziel darf nicht der eigene Rechner sein, deshalb
		// hier eine Adresse außerhalb (statt des früheren 127.0.0.1) verwenden.
		yield 'Block' => ["location /api {\n\tproxy_pass http://192.0.2.10:9000;\n\tproxy_set_header Host \$host;\n}\n"];
		yield 'verschachtelt' => ["location /a {\n\tlocation /a/b {\n\t\texpires 1d;\n\t}\n}\n"];
		yield 'Kommentar und Leerzeilen' => ["# nur ein Kommentar\n\n\texpires 1d;\n\n"];
		yield 'leer' => [''];
		yield 'nur Leerraum' => ["\n  \n"];
	}

	#[DataProvider('allowedSnippets')]
	public function testAcceptsAllowedDirectives(string $text): void
	{
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testEmptyIsRecognised(): void
	{
		self::assertTrue(NginxSnippet::fromString('')->isEmpty());
		self::assertTrue(NginxSnippet::fromString("\n \n")->isEmpty());
		self::assertFalse(NginxSnippet::fromString("expires 1d;\n")->isEmpty());
		self::assertSame("expires 1d;\n", (string)NginxSnippet::fromString("expires 1d;\n"));
	}

	/** @return iterable<string, array{string, string}> */
	/**
	 * Was auch unter der Sperrliste vom 2026-09-20 abgelehnt bleibt.
	 *
	 * Nicht mehr enthalten, weil jetzt absichtlich erlaubt: "deny" (kann nur
	 * einschränken), "if"/"limit_except" (in fremden confs alltäglich) und unbekannte
	 * Direktiven – die fängt "nginx -t" vor dem Reload ab, samt Rücknahme der Datei.
	 * "allow" bleibt gesperrt, weil es in einem location-Block die geerbten Regeln des
	 * Verzeichnisschutzes ersetzt und mit dessen "satisfy any" den Zugang öffnen würde.
	 */
	public static function forbiddenSnippets(): iterable
	{
		yield 'root' => ["root /etc;\n", 'root'];
		yield 'alias' => ["alias /etc;\n", 'alias'];
		yield 'listen' => ["listen 8081;\n", 'listen'];
		yield 'server_name' => ["server_name boese.example;\n", 'server_name'];
		yield 'ssl_certificate' => ["ssl_certificate /tmp/x.pem;\n", 'ssl_certificate'];
		yield 'auth_basic' => ["auth_basic off;\n", 'auth_basic'];
		yield 'satisfy' => ["satisfy any;\n", 'satisfy'];
		yield 'allow' => ["allow all;\n", 'allow'];
		yield 'include' => ["include /etc/passwd;\n", 'include'];
		yield 'access_log' => ["access_log /tmp/x.log;\n", 'access_log'];
		yield 'error_log' => ["error_log /tmp/x.log;\n", 'error_log'];
		yield 'load_module' => ["load_module modules/x.so;\n", 'load_module'];
		yield 'user' => ["user root;\n", 'user'];
	}

	#[DataProvider('forbiddenSnippets')]
	public function testRejectsForbiddenDirectives(string $text, string $directive): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($directive);
		NginxSnippet::fromString($text);
	}

	public function testRejectsBlockEscape(): void
	{
		$escape = "expires 1d;\n}\nserver {\n\tlisten 8081;\n\troot /etc;\n}\n";
		try {
			NginxSnippet::fromString($escape);
			self::fail('Ausbruch aus dem server-Block muss abgelehnt werden');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Zeile 2', $e->getMessage());
			self::assertStringContainsString('schließende', $e->getMessage());
		}
	}

	public function testRejectsUnbalancedBraces(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('geschlossen');
		NginxSnippet::fromString("location /a {\n\texpires 1d;\n");
	}

	public function testRejectsTooDeepNesting(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('verschachtelt');
		NginxSnippet::fromString("location /a {\n\tlocation /b {\n\t\tlocation /c {\n\t\t\texpires 1d;\n\t\t}\n\t}\n}\n");
	}

	public function testRejectsOversizedSnippet(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('64');
		NginxSnippet::fromString(str_repeat("expires 1d;\n", 6000));
	}

	public function testErrorMessageNamesTheLine(): void
	{
		try {
			NginxSnippet::fromString("expires 1d;\n# Kommentar\n\nroot /etc;\n");
			self::fail('Exception erwartet');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Zeile 4', $e->getMessage());
			self::assertStringContainsString('root', $e->getMessage());
		}
	}

	public function testRejectsMultipleDirectivesOnOneLine(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('root');
		NginxSnippet::fromString("expires 1d; root /etc;");
	}

	public function testRejectsEscapeWithMultipleDirectives(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('schließende');
		NginxSnippet::fromString("expires 1d; } server { root /etc; listen 8081;");
	}

	public function testRejectsClosingBraceWhichWouldNegateDepth(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('schließende');
		NginxSnippet::fromString("expires 1d; }");
	}

	public function testRejectsMultipleNegativeClosingBraces(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('schließende');
		NginxSnippet::fromString("} } server {");
	}

	public function testAcceptsMultipleStatementsOnOneLineInLocation(): void
	{
		$text = "location /a { expires 1d; }\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testRejectsTooDeepNestingOnOneLine(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('verschachtelt');
		NginxSnippet::fromString("location /a { location /b { location /c { expires 1d; } } }");
	}

	public function testAcceptsSemicolonInDoubleQuotes(): void
	{
		$text = "add_header X-Foo \"a; b\";\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testAcceptsBraceInDoubleQuotes(): void
	{
		$text = "add_header X-Foo \"a { b\";\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testAcceptsCommentAfterDirective(): void
	{
		$text = "expires 1d; # root /etc;\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	public function testRejectsUnclosedQuotes(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Anführungszeichen');
		NginxSnippet::fromString("add_header X-Foo \"unclosed;");
	}

	public function testRejectsDirectiveWithoutSemicolon(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Semikolon');
		NginxSnippet::fromString("expires 1d");
	}

	public function testAcceptsGzipDirectives(): void
	{
		$text1 = "gzip on;\n";
		$text2 = "gzip_types text/css;\n";
		self::assertSame($text1, NginxSnippet::fromString($text1)->value);
		self::assertSame($text2, NginxSnippet::fromString($text2)->value);
	}

	/**
	 * Unter der Sperrliste ist eine unbekannte Direktive erlaubt – die Positivliste
	 * hatte sie noch abgelehnt. Abgefangen wird sie von "nginx -t" vor dem Reload,
	 * das die Datei bei einem Fehlschlag zurücknimmt (VhostService::setSnippet()).
	 */
	public function testUnknownDirectivesArePassedOnToNginxForChecking(): void
	{
		self::assertSame("gibtsnicht bar;", NginxSnippet::fromString("gibtsnicht bar;")->value);
		self::assertSame("gzipfoo bar;", NginxSnippet::fromString("gzipfoo bar;")->value);
	}

	public function testAcceptsProxyDirectives(): void
	{
		$text = "proxy_set_header Host \$host;\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	/**
	 * C1 (Abschlussreview): "#", '"' und "'" sind für nginx nur am Anfang eines
	 * Tokens Sonderzeichen. Mitten in einem Wort ist z.B. "#" ein gewöhnliches
	 * Zeichen, sodass alles danach für nginx aktive Konfiguration bleibt, auch
	 * wenn unser bisheriger Tokenizer es fälschlich als Kommentar überspringt
	 * und damit an der Positivliste vorbeischleust. Die Prüfung erfolgt über die
	 * konkrete Fehlermeldung ("mitten im Wort"): der bisherige Tokenizer wirft
	 * bei diesen Eingaben teils ebenfalls eine InvalidArgumentException, aber aus
	 * einem anderen (zufälligen) Grund – ohne den Meldungsvergleich wären diese
	 * Tests vor dem Fix fälschlich grün.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function tokenStartViolations(): iterable
	{
		yield 'Hash mitten im Wort, root danach' => ["add_header X v#; root /etc;\n"];
		yield 'Hash mitten im Wort, innerhalb einer Location' => ["location /x {\n\tadd_header X v#; root /etc;\n}\n"];
		yield 'Hash mitten im Wort, auth_basic danach' => ["add_header X v#; auth_basic off;\n"];
		yield 'Hash mitten im Wort, access_log danach' => ["add_header X v#; access_log /tmp/x.log;\n"];
		yield 'Hash mitten im Wort, CR statt LF' => ["add_header X v#\r root /etc;\n"];
		yield 'Anführungszeichen mitten im Wort' => ["add_header X a\"; root /etc; \"x\";\n"];
		yield 'Hash mitten im Wort vor Blockausbruch' => ["add_header X v#; } server { root /; }\n"];
	}

	#[DataProvider('tokenStartViolations')]
	public function testRejectsSpecialCharactersInMiddleOfToken(string $text): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('mitten im Wort');
		NginxSnippet::fromString($text);
	}

	/**
	 * I3 (Abschlussreview): proxy_pass bleibt erlaubt, darf aber nicht auf den
	 * eigenen Rechner zeigen – sonst wäre die Oberfläche (ohne eigene Anmeldung)
	 * über jede beliebige öffentliche Domain erreichbar, weil nginx den Port beim
	 * server_name-Vergleich abschneidet.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function forbiddenProxyPassTargets(): iterable
	{
		yield 'IPv4-Loopback mit Port' => ["proxy_pass http://127.0.0.1:8080/;\n"];
		yield 'IPv4-Loopback, anderes Oktett' => ["proxy_pass http://127.5.5.5/;\n"];
		yield 'IPv4-Loopback kurz' => ["proxy_pass http://127.1/;\n"];
		yield 'IPv4-Loopback mit führenden Nullen' => ["proxy_pass http://127.000.000.001/;\n"];
		yield 'localhost' => ["proxy_pass https://localhost/;\n"];
		yield 'localhost großgeschrieben' => ["proxy_pass https://LOCALHOST/;\n"];
		yield 'IPv6-Loopback' => ["proxy_pass http://[::1]/;\n"];
		yield 'IPv6-Loopback ohne Schema' => ["proxy_pass [::1]:8080;\n"];
		yield 'unspezifiziert 0.0.0.0' => ["proxy_pass http://0.0.0.0:8080/;\n"];
		yield 'Unix-Socket' => ["proxy_pass unix:/run/php-fpm.sock;\n"];
		yield 'Variable im Ziel' => ["proxy_pass http://\$backend;\n"];
		// Befund 1 (Re-Review 2026-09-20): der bisherige Zeichenkettenvergleich in
		// isOwnMachineHost() erkannte nur eine Handvoll Schreibweisen. Die folgenden
		// Formen wurden im Re-Review per HTTP 200 als funktionierende Umgehungen
		// nachgewiesen und müssen jetzt über den Adressvergleich abgelehnt werden.
		yield 'Loopback dezimal' => ["proxy_pass http://2130706433/;\n"];
		yield 'Loopback oktal' => ["proxy_pass http://0177.0.0.1/;\n"];
		yield 'unspezifiziert kurz (0 statt 0.0.0.0)' => ["proxy_pass http://0:8080/;\n"];
		yield 'localhost mit abschließendem Punkt' => ["proxy_pass http://localhost.:8080/;\n"];
		yield 'IPv4-mapped IPv6-Loopback' => ["proxy_pass http://[::ffff:127.0.0.1]:8080/;\n"];
		yield 'IPv6-Loopback ausgeschrieben' => ["proxy_pass http://[0:0:0:0:0:0:0:1]/;\n"];
		yield 'IPv6-Loopback aufgefüllt' => ["proxy_pass http://[::0001]/;\n"];
		// Nachgetragen in der zweiten Fix-Runde (2026-09-20): Die erste Fassung prüfte
		// beim IPv4-mapped-Fall nur auf das Oktett 127 und kannte :: nicht. Ein
		// connect() auf die unspezifizierte Adresse landet aber beim Betriebssystem auf
		// dem Loopback – in dieser Umgebung nachgemessen erreichten "::ffff:0.0.0.0"
		// und "::ffff:0:0" den Listener auf 127.0.0.1:8080 wirklich.
		yield 'IPv4-mapped unspezifiziert' => ["proxy_pass http://[::ffff:0.0.0.0]:8080/;\n"];
		yield 'IPv4-mapped unspezifiziert, kurz' => ["proxy_pass http://[::ffff:0:0]:8080/;\n"];
		yield 'IPv6 unspezifiziert' => ["proxy_pass http://[::]:8080/;\n"];
		// In dieser Testumgebung (ohne funktionierende Namensauflösung ins Internet)
		// lässt sich diese Form zufällig nicht auflösen – genau deshalb muss sie
		// abgelehnt werden: ein Ziel, dessen Adresse unbekannt ist, könnte auf
		// Loopback zeigen. Verlässlich ist nur, dass sie NIE akzeptiert werden darf.
		yield 'Loopback hexadezimal' => ["proxy_pass http://0x7f000001/;\n"];
	}

	#[DataProvider('forbiddenProxyPassTargets')]
	public function testRejectsProxyPassToOwnMachine(string $text): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('proxy_pass');
		NginxSnippet::fromString($text);
	}

	public function testAcceptsProxyPassToRemoteTarget(): void
	{
		$text = "proxy_pass http://192.0.2.10:8080/;\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	/**
	 * Befund 1 (Re-Review 2026-09-20): Ein regulärer Domainname, der sich in dieser
	 * Testumgebung nicht auflösen lässt (kein Netzzugang zu echtem DNS), darf nicht
	 * deshalb abgelehnt werden – sonst wären beliebige, tatsächlich erreichbare
	 * Ziele blockiert. Abgelehnt wird bei Nichtauflösbarkeit nur, was wie eine Zahl
	 * bzw. Adresse aussieht (siehe "Loopback hexadezimal" oben).
	 */
	public function testAcceptsUnresolvableButNonNumericDomainAsProxyPassTarget(): void
	{
		$text = "proxy_pass https://backend.example.com/;\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}

	/**
	 * Vollständiges Belegbeispiel aus dem Abschlussreview: Vor dem Fix wird dieses
	 * Snippet komplett akzeptiert, weil "# root /etc;" fälschlich als Kommentar
	 * gilt und die verbleibende erste Zeile "add_header X-B v" (Direktive
	 * "add_header") noch gültig erscheint – "root /etc;" landet unverändert und
	 * unvalidiert in der Datei, die nginx tatsächlich auswertet.
	 */
	public function testRejectsFullDisclosedExploitFromReview(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		NginxSnippet::fromString("location /leak {\n\tadd_header X-B v#; root /etc;\nexpires 1d; }\n");
	}
	// ------------------------------------------------------------------
	// Sperrliste statt Positivliste (Nutzerwunsch vom 2026-09-20): erlaubt ist alles,
	// was nicht ausdrücklich aus dem vHost herausführt. Ziel ist, ein Fragment aus der
	// conf eines anderen Projekts per copy-paste einsetzen zu können.
	// ------------------------------------------------------------------

	private static function scope(): SnippetScope
	{
		return new SnippetScope(
			'/var/www/a.de',
			'/var/www/a.de/conf',
			'/var/www/a.de/logs',
			'/run/php/vhost-a.de.sock',
		);
	}

	/**
	 * Der eigentliche Zweck der Umstellung: ein Fragment im ISPConfig-Stil geht durch.
	 */
	public function testAcceptsAFragmentCopiedFromAnotherProject(): void
	{
		$snippet = <<<'NG'
		location ~ \.(?:css|js|woff2)$ {
		    expires 30d;
		    add_header Cache-Control "public, immutable";
		}
		if ($request_method !~ ^(GET|HEAD|POST)$) {
		    return 405;
		}
		location @php {
		    try_files $uri =404;
		    fastcgi_index index.php;
		    fastcgi_intercept_errors on;
		    fastcgi_read_timeout 1200;
		}
		location / {
		    try_files $uri $uri/ /index.php?$args;
		}
		limit_except GET HEAD {
		    deny all;
		}
		location = /health {
		    return 200 "ok";
		}
		NG;
		self::assertSame($snippet, NginxSnippet::fromString($snippet, self::scope())->value);
	}

	/**
	 * Direktiven, die die Positivliste früher abgelehnt hat und die jetzt erlaubt sind.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function nowAllowedDirectives(): iterable
	{
		yield 'sub_filter' => ["sub_filter 'a' 'b';\n"];
		yield 'deny' => ["deny 192.0.2.1;\n"];
		yield 'log_not_found' => ["log_not_found off;\n"];
		yield 'internal' => ["internal;\n"];
		yield 'fastcgi_read_timeout' => ["fastcgi_read_timeout 300;\n"];
		yield 'if-Block' => ["if (\$http_user_agent ~* bot) {\n    return 403;\n}\n"];
		yield 'limit_except' => ["limit_except GET {\n    deny all;\n}\n"];
		yield 'access_log off' => ["access_log off;\n"];
		yield 'root im eigenen vHost' => ["root /var/www/a.de/web/andere;\n"];
		yield 'include aus conf/' => ["include /var/www/a.de/conf/rewrites.conf;\n"];
		yield 'error_log in logs/' => ["error_log /var/www/a.de/logs/extra.log;\n"];
		yield 'eigener FPM-Socket' => ["fastcgi_pass unix:/run/php/vhost-a.de.sock;\n"];
		yield 'fremder Rechner als Ziel' => ["proxy_pass http://192.0.2.10:8080/;\n"];
	}

	#[DataProvider('nowAllowedDirectives')]
	public function testAllowsDirectivesThatDoNotLeaveTheVhost(string $snippet): void
	{
		self::assertSame($snippet, NginxSnippet::fromString($snippet, self::scope())->value);
	}

	/**
	 * Was weiterhin gesperrt bleibt, weil es aus dem vHost herausführt.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function stillForbidden(): iterable
	{
		yield 'root ausserhalb' => ["root /etc;\n", 'ausserhalb'];
		yield 'root im Nachbar-vHost' => ["root /var/www/b.de/web;\n", 'ausserhalb'];
		yield 'root mit ..' => ["root /var/www/a.de/../b.de/web;\n", 'ausserhalb'];
		yield 'alias ausserhalb' => ["alias /root/.ssh;\n", 'ausserhalb'];
		yield 'include ausserhalb conf/' => ["include /etc/nginx/nginx.conf;\n", 'conf/'];
		yield 'include aus web/' => ["include /var/www/a.de/web/evil.conf;\n", 'conf/'];
		yield 'access_log ausserhalb logs/' => ["access_log /var/www/a.de/web/a.log;\n", 'logs/'];
		yield 'auth_basic abschalten' => ["auth_basic off;\n", 'Verzeichnisschutz'];
		yield 'auth_basic_user_file' => ["auth_basic_user_file /tmp/x;\n", 'Verzeichnisschutz'];
		yield 'satisfy' => ["satisfy any;\n", 'Verzeichnisschutz'];
		yield 'fremder Unix-Socket' => ["fastcgi_pass unix:/run/php/php8.5-fpm.sock;\n", 'Socket'];
		yield 'fastcgi_pass auf die Oberflaeche' => ["fastcgi_pass 127.0.0.1:8080;\n", 'eigenen Rechner'];
		yield 'uwsgi_pass auf Loopback' => ["uwsgi_pass http://localhost:8080/;\n", 'eigenen Rechner'];
		yield 'SCRIPT_FILENAME ausserhalb' => ["fastcgi_param SCRIPT_FILENAME /var/www/localhost-8080/web/index.php;\n", 'ausserhalb'];
		yield 'DOCUMENT_ROOT ausserhalb' => ["fastcgi_param DOCUMENT_ROOT /etc;\n", 'ausserhalb'];
		yield 'dav_methods' => ["dav_methods PUT DELETE;\n", 'Schreibzugriff'];
		yield 'perl' => ["perl My::handler;\n", 'Code'];
		yield 'lua' => ["content_by_lua_block { os.execute('id') }\n", 'Code'];
		yield 'load_module' => ["load_module modules/ngx_http_x.so;\n", 'Code'];
		yield 'ssl_certificate' => ["ssl_certificate /etc/ssl/x.pem;\n", 'nicht erlaubt'];
	}

	#[DataProvider('stillForbidden')]
	public function testRejectsDirectivesThatLeaveTheVhost(string $snippet, string $expectedInMessage): void
	{
		try {
			NginxSnippet::fromString($snippet, self::scope());
			self::fail('Snippet hätte abgelehnt werden müssen: ' . trim($snippet));
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString($expectedInMessage, $e->getMessage());
			self::assertMatchesRegularExpression('/Zeile \d+/', $e->getMessage(), 'Die Meldung muss die Zeile nennen');
		}
	}

	/**
	 * Ohne Bezugsrahmen bleiben pfadgebundene Direktiven gesperrt: lieber ablehnen als
	 * ungeprüft zulassen.
	 */
	public function testPathBoundDirectivesAreRejectedWithoutAScope(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		NginxSnippet::fromString("root /var/www/a.de/web;\n");
	}

	/**
	 * Die Klammerbilanz bleibt die wichtigste Prüfung – sonst liesse sich der
	 * server-Block verlassen und ein eigener aufmachen.
	 */
	public function testStillRefusesToLeaveTheServerBlockWithTheBlocklist(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		NginxSnippet::fromString("}\nserver {\n    listen 81;\n    root /etc;\n}\n", self::scope());
	}
}
