<?php

declare(strict_types=1);

// Smoke-runs every script in examples/ with the PHP binary that runs this
// file, and stops at the first one that fails. It is what `composer examples`
// executes. It replaces a POSIX shell loop, so the script also works where
// Composer runs scripts through cmd.exe.
//
// An example fails when it exits non-zero, or when it writes anything to
// standard error. Each example runs with every error level reported and sent
// to standard error, so a warning, a notice or a deprecation fails the run
// even though PHP still exits with 0 after one. No example writes to standard
// error on purpose.
//
// An example that succeeds prints nothing here: its standard output is
// captured and dropped. When an example fails, both of its captured streams
// are written to standard error, so the failure states its reason whichever
// stream PHP reported it on.
//
// Gap left knowingly: an example that turns error display off itself, or
// sends its errors to standard output, hides a warning from this runner.
// tests/Feature/ExamplesTest.php is the thorough check (output markers,
// pinned output); this is the fast loop.
//
// An optional argument names another directory to run, which is how
// tests/Feature/ExamplesTest.php exercises the failure paths.

$directory = $argv[1] ?? dirname(__DIR__, 2) . '/examples';
$scripts = glob(rtrim($directory, '/\\') . '/*.php') ?: [];

if ($scripts === []) {
    fwrite(STDERR, "No example scripts found in {$directory}.\n");

    exit(1);
}

foreach ($scripts as $script) {
    // Temporary files, not pipes: a pipe that fills while the other stream is
    // being read would block the example for ever.
    $stdout = tmpfile();
    $stderr = tmpfile();

    if ($stdout === false || $stderr === false) {
        fwrite(STDERR, "Could not create a temporary file for the output of an example.\n");

        exit(1);
    }

    $process = proc_open(
        [
            PHP_BINARY,
            '-d', 'error_reporting=-1',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            '-d', 'html_errors=0',
            $script,
        ],
        [1 => $stdout, 2 => $stderr],
        $pipes
    );

    if ($process === false) {
        fwrite(STDERR, sprintf("Could not start PHP to run example %s.\n", basename($script)));

        exit(1);
    }

    $exitCode = proc_close($process);

    // The example wrote through its own copies of the handles, so this
    // process has to seek explicitly before it reads.
    rewind($stdout);
    rewind($stderr);
    $output = (string) stream_get_contents($stdout);
    $errors = (string) stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);

    if ($exitCode === 0 && $errors === '') {
        continue;
    }

    foreach ([$output, $errors] as $captured) {
        if ($captured !== '') {
            fwrite(STDERR, rtrim($captured, "\r\n") . "\n");
        }
    }

    fwrite(STDERR, $exitCode !== 0
        ? sprintf("Example %s exited with code %d.\n", basename($script), $exitCode)
        : sprintf("Example %s wrote to standard error.\n", basename($script)));

    exit(1);
}
