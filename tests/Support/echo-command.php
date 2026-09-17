<?php
declare(strict_types=1);

/**
 * Test-Hilfsskript: gibt Argumente und stdin aus; Exit 3, wenn eines der Argumente "fail" ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 13:05
 */

array_shift($argv);
echo 'ARGS=' . implode('|', $argv) . "\n";
echo 'STDIN=' . stream_get_contents(STDIN) . "\n";
if (in_array('fail', $argv, true)) {
	fwrite(STDERR, "Fehler: Simulation\n");
	exit(3);
}
