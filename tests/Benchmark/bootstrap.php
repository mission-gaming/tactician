<?php

declare(strict_types=1);

// Bootstrap of the benchmark suite (tests/Benchmark/phpbench.json).
//
// With TACTICIAN_BENCH_SRC unset, the benchmarks measure the library of the
// working tree, through Composer's autoloader.
//
// With TACTICIAN_BENCH_SRC set to a directory, they measure the library found
// there: a copy of src/ as it is on another commit. That is how the
// comparison run (tests/bin/compare-benchmarks.php) measures the base of a
// pull request and the change with the same benchmarks, on the same machine,
// minutes apart. A class the directory does not hold is left to Composer.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = getenv('TACTICIAN_BENCH_SRC');

if (is_string($source) && $source !== '') {
    $directory = realpath($source);
    if ($directory === false || !is_dir($directory)) {
        fwrite(STDERR, "TACTICIAN_BENCH_SRC names no directory: {$source}\n");
        exit(1);
    }

    spl_autoload_register(
        static function (string $class) use ($directory): void {
            $prefix = 'MissionGaming\\Tactician\\';
            if (!str_starts_with($class, $prefix) || str_starts_with($class, $prefix . 'Tests\\')) {
                return;
            }

            $file = $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        },
        true,
        // Ahead of Composer's loader, which would load the working tree's class.
        true
    );
}
