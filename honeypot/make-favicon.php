<?php
declare(strict_types=1);

/**
 * Erzeugt favicon.ico aus derselben Geometrie wie favicon.svg.
 *
 * Auf diesem System gibt es weder GD noch ImageMagick, deshalb wird das Bild hier
 * gerastert und die PNG-Datei von Hand zusammengesetzt: Signatur, IHDR, IDAT
 * (zlib-komprimiert – genau das liefert gzcompress) und IEND, jeweils mit CRC32.
 * Die ICO-Datei umschliesst das PNG; alle heutigen Browser lesen PNG-in-ICO.
 *
 * Aufruf: php honeypot/make-favicon.php honeypot/site/favicon.ico
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 11:10
 */

const SIZE = 32;

/** Liegt der Punkt im Vieleck? Strahlverfahren. */
function inPolygon(float $x, float $y, array $points): bool
{
	$inside = false;
	$count = count($points);
	for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
		[$xi, $yi] = $points[$i];
		[$xj, $yj] = $points[$j];
		if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
			$inside = !$inside;
		}
	}
	return $inside;
}

/** Abstand eines Punktes zum Rand des Vielecks (grob, über die Eckpunkte hinweg). */
function distanceToEdge(float $x, float $y, array $points): float
{
	$min = PHP_FLOAT_MAX;
	$count = count($points);
	for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
		[$xi, $yi] = $points[$i];
		[$xj, $yj] = $points[$j];
		$dx = $xj - $xi;
		$dy = $yj - $yi;
		$len = $dx * $dx + $dy * $dy;
		$t = $len > 0 ? max(0, min(1, (($x - $xi) * $dx + ($y - $yi) * $dy) / $len)) : 0;
		$px = $xi + $t * $dx;
		$py = $yi + $t * $dy;
		$min = min($min, sqrt(($x - $px) ** 2 + ($y - $py) ** 2));
	}
	return $min;
}

/** Der Honigtropfen: Spitze oben, runder Bauch unten. */
function inDrop(float $x, float $y): bool
{
	$cx = 16.0;
	if ($y >= 17.5) {
		return (($x - $cx) ** 2) / (5.2 ** 2) + (($y - 17.5) ** 2) / (5.2 ** 2) <= 1;
	}
	// Oberhalb des Bauches läuft die Form keilförmig zur Spitze bei y = 8,5 zusammen.
	$width = 5.2 * (($y - 8.5) / 9.0);
	return $width > 0 && abs($x - $cx) <= $width;
}

$hexagon = [[9.5, 3], [22.5, 3], [29, 16], [22.5, 29], [9.5, 29], [3, 16]];
$pixels = [];
for ($y = 0; $y < SIZE; $y++) {
	$row = '';
	for ($x = 0; $x < SIZE; $x++) {
		// Mittelpunkt des Bildpunktes, damit die Kante nicht um einen halben Punkt springt.
		$px = $x + 0.5;
		$py = $y + 0.5;
		if (!inPolygon($px, $py, $hexagon)) {
			$row .= "\x00\x00\x00\x00";
			continue;
		}
		if (distanceToEdge($px, $py, $hexagon) <= 1.6) {
			$row .= "\x6f\x44\x03\xff";   // Rand
		} elseif (inDrop($px, $py)) {
			$row .= "\xfd\xee\xc2\xff";   // Tropfen
		} else {
			$row .= "\xe9\xa2\x1c\xff";   // Wabe
		}
	}
	$pixels[] = "\x00" . $row;            // Filterbyte 0 = ohne Vorhersage
}

/** PNG-Abschnitt mit Länge, Typ, Daten und Prüfsumme. */
function chunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

$ihdr = pack('NN', SIZE, SIZE) . "\x08\x06\x00\x00\x00"; // 8 Bit, RGBA, ohne Interlace
$png = "\x89PNG\r\n\x1a\n"
	. chunk('IHDR', $ihdr)
	. chunk('IDAT', gzcompress(implode('', $pixels), 9))
	. chunk('IEND', '');

$ico = pack('vvv', 0, 1, 1)                      // Reserviert, Typ 1 (Symbol), ein Bild
	. pack('CCCC', SIZE, SIZE, 0, 0)             // Breite, Höhe, Farben, reserviert
	. pack('vv', 1, 32)                          // Ebenen, Bit je Bildpunkt
	. pack('VV', strlen($png), 22);              // Grösse und Anfang der Bilddaten

$target = $argv[1] ?? 'favicon.ico';
file_put_contents($target, $ico . $png);
printf("%s geschrieben (%d Bytes, %dx%d)\n", $target, strlen($ico . $png), SIZE, SIZE);
