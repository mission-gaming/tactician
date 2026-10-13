<?php

declare(strict_types=1);

/**
 * Starts a mutation testing run. It is what `composer mutation` executes,
 * and arguments given after `--` come here and go on to Pest:
 *
 *     composer mutation -- --path=src/Repack/Internal
 *
 * The run mutates the two directories below, the lines a test executes
 * only, in parallel, with the tests named in `phpunit.mutation.xml`, and
 * sets no minimum score. A path given by the caller replaces the two.
 *
 * It is a script and not a line of `composer.json` for one reason: the test
 * process of every mutation is to have a memory limit and to log that it
 * ended there (tests/Support/MutationMemoryLimit.php), and what installs
 * that has to run before Pest does. So this writes a configuration file
 * that makes PHP run `tests/mutation-memory.php` first, and starts Pest
 * with the directory of that file added to `PHP_INI_SCAN_DIR`. The variable
 * is inherited, so every process of the run reads the file; the caller's own
 * value is kept. Where the file cannot be written, the run goes on without
 * the limit, and its summary says that it had none.
 *
 * It exits with the status of the run.
 */

use MissionGaming\Tactician\Tests\Support\MutationMemoryLimit;

require dirname(__DIR__) . '/Support/MutationMemoryLimit.php';

$root = dirname(__DIR__, 2);

$environment = getenv();
$scan = MutationMemoryLimit::scanDirectories($root, $root . '/' . MutationMemoryLimit::INI_DIRECTORY, getenv('PHP_INI_SCAN_DIR'));
if ($scan !== null) {
    $environment['PHP_INI_SCAN_DIR'] = $scan;
}

$process = proc_open(
    [
        PHP_BINARY,
        $root . '/vendor/bin/pest',
        '--configuration=phpunit.mutation.xml', '--mutate', '--everything', '--covered-only', '--path=src/Scheduling,src/Repack/Internal', '--parallel',
        ...array_slice($argv, 1),
    ],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $root,
    $environment
);

if ($process === false) {
    fwrite(STDERR, "Could not start Pest.\n");

    exit(1);
}

exit(proc_close($process));
