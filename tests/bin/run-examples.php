<?php

declare(strict_types=1);

// Smoke-runs every script in examples/ with the PHP binary that runs this
// file, and stops at the first one that exits non-zero. It is what
// `composer examples` executes. It replaces a POSIX shell loop, so the script
// also works where Composer runs scripts through cmd.exe.
//
// The examples' standard output is discarded; their standard error is not
// redirected, so a failing example still shows its message.
// tests/Feature/ExamplesTest.php is the thorough check (full error reporting,
// output markers, pinned output); this is the fast loop.

$scripts = glob(dirname(__DIR__, 2) . '/examples/*.php') ?: [];

if ($scripts === []) {
    fwrite(STDERR, "No example scripts found.\n");

    exit(1);
}

foreach ($scripts as $script) {
    $output = [];
    $exitCode = 0;

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $output, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf("Example %s exited with code %d.\n", basename($script), $exitCode));

        exit(1);
    }
}
