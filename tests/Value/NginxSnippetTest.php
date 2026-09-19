<?php
declare(strict_types=1);

/**
 * Tests für die Prüfung des nginx-Snippets aus der Oberfläche.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:28
 */

namespace Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\NginxSnippet;

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
		yield 'Block' => ["location /api {\n\tproxy_pass http://127.0.0.1:9000;\n\tproxy_set_header Host \$host;\n}\n"];
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
		yield 'deny' => ["deny all;\n", 'deny'];
		yield 'include' => ["include /etc/passwd;\n", 'include'];
		yield 'access_log' => ["access_log /tmp/x.log;\n", 'access_log'];
		yield 'error_log' => ["error_log /tmp/x.log;\n", 'error_log'];
		yield 'load_module' => ["load_module modules/x.so;\n", 'load_module'];
		yield 'user' => ["user root;\n", 'user'];
		yield 'if' => ["if (\$host) {\n\treturn 404;\n}\n", 'if'];
		yield 'unbekannt' => ["gibtsnicht an;\n", 'gibtsnicht'];
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

	public function testRejectsGzipPrefixedForbiddenDirective(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('gzipfoo');
		NginxSnippet::fromString("gzipfoo bar;");
	}

	public function testAcceptsProxyDirectives(): void
	{
		$text = "proxy_set_header Host \$host;\n";
		self::assertSame($text, NginxSnippet::fromString($text)->value);
	}
}
