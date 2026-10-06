<?php

declare(strict_types=1);

// Runs the benchmark suite in tests/Benchmark/ with the benchmark runner
// installed in tools/phpbench/. It is what `composer bench` executes.
//
// Usage: php tests/bin/run-benchmarks.php [phpbench run options]
//
// The runner is not one of the library's development dependencies
// (tools/phpbench/README.md says why), so it may not be installed. This
// script does not install it: it says how, and exits with status 2.
//
// With no option given, the aggregate report is printed. Any option replaces
// that default, so `composer bench -- --filter=Repack --report=default` runs
// part of the suite with another report.

$root = dirname(__DIR__, 2);
$runner = $root . '/tools/phpbench/vendor/bin/phpbench';

if (!is_file($runner)) {
    fwrite(STDERR, "The benchmark runner is not installed. Run `composer bench-install` first.\n");
    exit(2);
}

$options = array_slice($argv, 1);
if ($options === []) {
    $options = ['--report=aggregate'];
}

$process = proc_open(
    [PHP_BINARY, $runner, 'run', '--config=' . $root . '/tests/Benchmark/phpbench.json', ...$options],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root
);

exit(is_resource($process) ? proc_close($process) : 1);
