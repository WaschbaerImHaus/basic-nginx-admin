<?php
declare(strict_types=1);

/**
 * Tests der öffentlichen Honigtopf-Seite (honeypot/site/index.html).
 *
 * Geprüft wird, was sich ohne Browser prüfen lässt. Das Aussehen der Wiese selbst prüft
 * debugging/meadow-check.sh mit einem kopflosen Chrome.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 21:00
 */

namespace Tests\Honeypot;

use PHPUnit\Framework\TestCase;

final class SitePageTest extends TestCase
{
	private string $html;

	protected function setUp(): void
	{
		$this->html = (string)file_get_contents(dirname(__DIR__, 2) . '/honeypot/site/index.html');
	}

	/**
	 * Die Seite ist ein Honigtopf: Jede Datei, die sie nachlädt, erschiene als eigene
	 * Anfrage im Log und verwässerte das Signal – und jede fremde Adresse wäre eine
	 * Anfrage bei Dritten. Alles steht deshalb in der Seite selbst.
	 */
	public function testLoadsNothingFromElsewhere(): void
	{
		self::assertDoesNotMatchRegularExpression('~<script[^>]+src=~i', $this->html, 'kein nachgeladenes Skript');
		self::assertDoesNotMatchRegularExpression('~<link[^>]+rel="stylesheet"~i', $this->html, 'kein nachgeladenes CSS');
		self::assertDoesNotMatchRegularExpression('~(src|href)="(https?:)?//~i', $this->html, 'keine fremde Adresse');
		self::assertDoesNotMatchRegularExpression('~url\(\s*["\']?(https?:)?//~i', $this->html, 'keine fremde Adresse in CSS');
		self::assertDoesNotMatchRegularExpression('~@import~i', $this->html);
	}

	/**
	 * Nutzervorgabe vom 2026-09-25: Der Text liegt auf 50 % transparentem Schwarz.
	 */
	public function testTheTextSitsOnHalfTransparentBlack(): void
	{
		self::assertMatchesRegularExpression(
			'~\.wrap\s*\{[^}]*background(-color)?\s*:\s*rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*(0?\.5|50%)\s*\)~',
			$this->html
		);
	}

	/**
	 * Die Wiese ist Dekoration. Ein Screenreader soll sie überspringen, und der Text muss
	 * ohne sie vollständig sein.
	 */
	public function testTheMeadowIsHiddenFromAssistiveTechnology(): void
	{
		self::assertMatchesRegularExpression('~<div[^>]+class="meadow"[^>]*aria-hidden="true"~', $this->html);
	}

	/**
	 * Wer „Bewegung reduzieren" eingestellt hat, bekommt ein stehendes Bild. Ein
	 * dauernd fliegender Hintergrund kann Schwindel auslösen.
	 */
	public function testRespectsReducedMotion(): void
	{
		self::assertStringContainsString('prefers-reduced-motion', $this->html);
	}

	/**
	 * Ohne JavaScript oder in einem Browser ohne die nötigen CSS-Funktionen muss die
	 * Seite trotzdem lesbar sein – Scanner führen ohnehin kein JavaScript aus.
	 */
	public function testTheTextIsPlainHtmlAndNotBuiltByScript(): void
	{
		self::assertStringContainsString('<h1>Imkerei am Hang</h1>', $this->html);
		self::assertStringContainsString('Zwölf Völker', $this->html);
	}

	public function testTheInlineScriptIsSyntacticallyComplete(): void
	{
		preg_match_all('~<script>(.*?)</script>~s', $this->html, $m);
		self::assertNotEmpty($m[1], 'die Wiese braucht ihr Skript');
		foreach ($m[1] as $script) {
			self::assertSame(substr_count($script, '{'), substr_count($script, '}'), 'Klammerbilanz');
			self::assertSame(substr_count($script, '('), substr_count($script, ')'), 'Klammerbilanz');
		}
	}
}
