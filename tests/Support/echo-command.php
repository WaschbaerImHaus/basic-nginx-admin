<?php
declare(strict_types=1);

/**
 * Test-Hilfsskript: gibt Argumente und stdin aus; Exit 3, wenn eines der Argumente "fail" ist;
 * "flood" schreibt je 300 000 Zeichen auf stderr und stdout.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:50
 */

array_shift($argv);
// "flood": erst viel auf stderr, dann auf stdout – mehr als ein Pipe-Puffer (64 KiB).
// Liest der Aufrufer die Kanäle nacheinander, blockieren beide Seiten.
if (in_array('flood', $argv, true)) {
	fwrite(STDERR, str_repeat('e', 300000));
	echo str_repeat('o', 300000);
	exit(0);
}
echo 'ARGS=' . implode('|', $argv) . "\n";
echo 'STDIN=' . stream_get_contents(STDIN) . "\n";
if (in_array('fail', $argv, true)) {
	fwrite(STDERR, "Fehler: Simulation\n");
	exit(3);
}
