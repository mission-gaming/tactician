<?php

declare(strict_types=1);

// Benchmarks the library as it is in another directory (the base of a
// change) and as it is in the working tree (the change), by turns, and fails
// when a subject of the change is slower than the base by more than a margin.
// It is what `composer bench-compare` executes, and what the Benchmarks job
// of the CI workflow runs for a pull request.
//
// Usage: php tests/bin/compare-benchmarks.php <base src directory> [margin] [rounds]
//
//   <base src directory>  a copy of src/ from the commit to compare with, e.g.
//                         `git archive <commit> src | tar -x -C build/base`
//                         gives build/base/src
//   [margin]              how many times the base's time the change may take
//                         (default 1.5)
//   [rounds]              how many times each side is run (default 3)
//
// Why this does not report noise as a regression, on a shared CI runner too:
//
// - Both sides run on the same machine, in the same job, minutes apart. No
//   figure is compared with one recorded on another machine or another day.
// - The sides are run by turns (base, change, base, change, ...), so a slow
//   spell of the machine falls on both.
// - The figure compared is the fastest revolution of all rounds. Whatever
//   else the machine does makes a revolution slower, never faster, so the
//   fastest of many is the one least disturbed
//   (tests/Support/BenchmarkComparison.php).
// - The margin is wide: a change has to take half as long again as the base
//   before it is flagged.
//
// A subject the base cannot run (it times out there, or the benchmark is
// newer than the base) has no baseline: it is listed and does not fail the
// run. A subject the change cannot run does fail it, whether or not the base
// could run it.

use MissionGaming\Tactician\Tests\Support\BenchmarkComparison;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

$arguments = $_SERVER['argv'];
$baseSource = $arguments[1] ?? '';
$margin = (float) ($arguments[2] ?? '1.5');
$rounds = (int) ($arguments[3] ?? '3');

if ($baseSource === '' || !is_dir($baseSource) || $margin <= 1.0 || $rounds < 1) {
    fwrite(STDERR, "Usage: php tests/bin/compare-benchmarks.php <base src directory> [margin > 1] [rounds >= 1]\n");
    exit(2);
}
// A directory that is not a copy of src/ (its parent, say) would leave every
// class to Composer, and the change would be compared with itself.
if (!is_file($baseSource . '/DTO/Participant.php')) {
    fwrite(STDERR, "{$baseSource} is not a copy of src/: it holds no DTO/Participant.php\n");
    exit(2);
}
$baseSource = (string) realpath($baseSource);

// The arguments are checked first, so that a wrong call is told what is
// wrong with it whether or not the runner is there. The runner is installed
// apart from the library's development dependencies
// (tools/phpbench/README.md says why), so it may not be.
$runner = $root . '/tools/phpbench/vendor/bin/phpbench';
if (!is_file($runner)) {
    fwrite(STDERR, "The benchmark runner is not installed. Run `composer bench-install` first.\n");
    exit(2);
}

$output = $root . '/build/phpbench';
if (!is_dir($output) && !mkdir($output, 0o777, true) && !is_dir($output)) {
    fwrite(STDERR, "Cannot create {$output}\n");
    exit(2);
}

/**
 * One run of the suite against one source tree, dumped as XML.
 *
 * @return string|null The dump, or null when the run wrote none
 */
$run = static function (string $side, int $round, ?string $source) use ($root, $output, $runner): ?string {
    $dump = "{$output}/{$side}-{$round}.xml";
    if (is_file($dump)) {
        unlink($dump);
    }

    $command = [
        PHP_BINARY,
        $runner,
        'run',
        '--config=' . $root . '/tests/Benchmark/phpbench.json',
        '--dump-file=' . $dump,
        '--progress=none',
    ];
    $environment = getenv();
    unset($environment['TACTICIAN_BENCH_SRC']);
    if ($source !== null) {
        $environment['TACTICIAN_BENCH_SRC'] = $source;
    }

    fwrite(STDOUT, "Round {$round}: {$side}\n");
    $process = proc_open($command, [1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        return null;
    }
    // The exit status is not what decides: a subject that errors on the base
    // gives a non-zero status and a dump with the other subjects in it.
    proc_close($process);

    $contents = is_file($dump) ? file_get_contents($dump) : false;

    return $contents === false ? null : $contents;
};

$dumps = ['base' => [], 'change' => []];
for ($round = 1; $round <= $rounds; ++$round) {
    foreach (['base' => $baseSource, 'change' => null] as $side => $source) {
        $dump = $run($side, $round, $source);
        if ($dump !== null) {
            $dumps[$side][] = $dump;
        }
    }
}

$rows = BenchmarkComparison::compare(
    BenchmarkComparison::fastestOverRuns($dumps['base']),
    BenchmarkComparison::fastestOverRuns($dumps['change']),
    $margin,
    BenchmarkComparison::subjectsOverRuns($dumps['change'])
);

fwrite(STDOUT, "\nFastest revolution over {$rounds} rounds, base against change (margin {$margin}):\n\n");
fwrite(STDOUT, BenchmarkComparison::table($rows));

if ($rows === []) {
    fwrite(STDERR, "\nNo benchmark was measured.\n");
    exit(1);
}

if (BenchmarkComparison::failed($rows)) {
    fwrite(STDERR, "\nA benchmark of the change is slower than the base by more than the margin, or could not be measured.\n");
    exit(1);
}

exit(0);
