<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\BenchmarkComparison;
use PHPUnit\Framework\Assert;

// The benchmark suite is not part of the gate (a timing depends on the
// machine), so nothing in `composer ci` runs it. These tests cover what can
// be covered without timing anything: the comparison that decides whether
// the CI job fails, the wiring of the suite, and the job itself.

/**
 * A phpbench XML dump with the given iterations.
 *
 * @param array<string, array<string, list<array{int, int}>>> $subjects Class => subject => [time-net, time-revs] per iteration
 */
function benchmarkDump(array $subjects): string
{
    $xml = '<?xml version="1.0"?><phpbench version="1.7.0"><suite tag="">';
    foreach ($subjects as $class => $bySubject) {
        $xml .= '<benchmark class="' . $class . '">';
        foreach ($bySubject as $subject => $iterations) {
            $xml .= '<subject name="' . $subject . '"><variant revs="1">';
            foreach ($iterations as [$time, $revolutions]) {
                $xml .= '<iteration time-net="' . $time . '" time-revs="' . $revolutions . '"/>';
            }
            $xml .= '</variant></subject>';
        }
        $xml .= '</benchmark>';
    }

    return $xml . '</suite></phpbench>';
}

/**
 * @return list<string> The benchmark class files
 */
function benchmarkClassFiles(): array
{
    $files = glob(dirname(__DIR__, 2) . '/tests/Benchmark/*Bench.php');

    return $files === false ? [] : $files;
}

describe('BenchmarkComparison', function (): void {
    it('takes the fastest revolution of a subject', function (): void {
        $dump = benchmarkDump([
            '\Vendor\Benchmark\RepackBench' => [
                // 900 and 700 microseconds for one revolution; 1,200 for four, which is 300 each
                'bench24' => [[900, 1], [700, 1], [1200, 4]],
                'bench40' => [[5000, 1]],
            ],
            '\Vendor\Benchmark\SwissBench' => ['bench24' => [[80, 2]]],
        ]);

        expect(BenchmarkComparison::fastestBySubject($dump))->toBe([
            'RepackBench::bench24' => 300.0,
            'RepackBench::bench40' => 5000.0,
            'SwissBench::bench24' => 40.0,
        ]);
    });

    it('takes the fastest over several runs', function (): void {
        $first = benchmarkDump(['\A\RepackBench' => ['bench24' => [[900, 1]], 'bench40' => [[400, 1]]]]);
        $second = benchmarkDump(['\A\RepackBench' => ['bench24' => [[650, 1]], 'bench40' => [[450, 1]]]]);

        expect(BenchmarkComparison::fastestOverRuns([$first, $second]))->toBe([
            'RepackBench::bench24' => 650.0,
            'RepackBench::bench40' => 400.0,
        ]);
        expect(BenchmarkComparison::fastestOverRuns([]))->toBe([]);
    });

    it('rejects text that is not a phpbench dump', function (string $text): void {
        expect(fn() => BenchmarkComparison::fastestBySubject($text))->toThrow(RuntimeException::class, 'Not a phpbench XML dump.');
    })->with(['nothing' => [''], 'not XML' => ['time-net="1"'], 'other XML' => ['<?xml version="1.0"?><testsuites/>']]);

    it('flags a subject slower than the margin and no other', function (): void {
        $rows = BenchmarkComparison::compare(
            ['a' => 100.0, 'b' => 100.0, 'c' => 100.0, 'gone' => 100.0],
            ['a' => 150.0, 'b' => 150.1, 'c' => 20.0, 'new' => 70.0],
            1.5
        );

        expect(array_column($rows, 'verdict', 'subject'))->toBe([
            // Exactly the margin is within it.
            'a' => 'ok',
            'b' => 'regression',
            'c' => 'ok',
            // The change could not run it: that is a failure, not a pass.
            'gone' => 'not measured',
            // The base could not run it: nothing to compare with.
            'new' => 'no baseline',
        ]);
        expect(array_column($rows, 'ratio', 'subject')['c'])->toBe(0.2);
        expect(BenchmarkComparison::failed($rows))->toBeTrue();
    });

    it('passes when every subject is within the margin or has no baseline', function (): void {
        $rows = BenchmarkComparison::compare(['a' => 100.0], ['a' => 149.0, 'new' => 5.0], 1.5);

        expect(BenchmarkComparison::failed($rows))->toBeFalse();
        expect(BenchmarkComparison::failed([]))->toBeFalse();
    });

    it('fails for a regression alone and for an unmeasured subject alone', function (): void {
        expect(BenchmarkComparison::failed(BenchmarkComparison::compare(['a' => 100.0], ['a' => 151.0], 1.5)))->toBeTrue();
        expect(BenchmarkComparison::failed(BenchmarkComparison::compare(['a' => 100.0], [], 1.5)))->toBeTrue();
    });

    it('names a subject that threw or timed out, which has no iteration to measure', function (): void {
        // What phpbench writes for a subject that failed: the subject, its
        // variant and the errors, and no iteration.
        $dump = '<?xml version="1.0"?><phpbench version="1.7.0"><suite tag="">'
            . '<benchmark class="\Vendor\Benchmark\RepackBench">'
            . '<subject name="benchThrows"><variant revs="1"><errors><error exception-class="RuntimeException">boom</error></errors></variant></subject>'
            . '<subject name="benchFine"><variant revs="1"><iteration time-net="500" time-revs="1"/></variant></subject>'
            . '</benchmark></suite></phpbench>';

        expect(BenchmarkComparison::fastestBySubject($dump))->toBe(['RepackBench::benchFine' => 500.0]);
        expect(BenchmarkComparison::subjects($dump))->toBe(['RepackBench::benchThrows', 'RepackBench::benchFine']);
        expect(BenchmarkComparison::subjectsOverRuns([$dump, benchmarkDump(['\A\SwissBench' => ['bench24' => [[80, 2]]]])]))
            ->toBe(['RepackBench::benchFine', 'RepackBench::benchThrows', 'SwissBench::bench24']);
        expect(BenchmarkComparison::subjectsOverRuns([]))->toBe([]);
    });

    it('fails for a subject the change could not run, whatever the base did', function (): void {
        // "new" fails on the change and does not exist on the base; "both"
        // fails on both sides. Neither side has a figure for either, so
        // only the names the change's runs gave make them rows.
        $rows = BenchmarkComparison::compare(['a' => 100.0], ['a' => 100.0], 1.5, ['a', 'both', 'new']);

        expect(array_column($rows, 'verdict', 'subject'))->toBe(['a' => 'ok', 'both' => 'not measured', 'new' => 'not measured']);
        expect(BenchmarkComparison::failed($rows))->toBeTrue();
        // Without the names the same figures pass.
        expect(BenchmarkComparison::failed(BenchmarkComparison::compare(['a' => 100.0], ['a' => 100.0], 1.5)))->toBeFalse();
    });

    it('has no baseline for a base figure of zero', function (): void {
        $rows = BenchmarkComparison::compare(['a' => 0.0], ['a' => 10.0], 1.5);

        expect($rows[0]['verdict'])->toBe('no baseline');
        expect($rows[0]['ratio'])->toBeNull();
    });

    it('prints times in milliseconds with a point, whatever the locale', function (): void {
        $table = BenchmarkComparison::table(BenchmarkComparison::compare(['a' => 1234.0], ['a' => 2468.0, 'new' => 10.0], 1.5));

        expect($table)->toBe(
            "subject       base ms     change ms    ratio  verdict\n"
            . "a               1.234         2.468     2.00  regression\n"
            . "new                 -         0.010        -  no baseline\n"
        );
    });
});

describe('The benchmark suite', function (): void {
    $root = dirname(__DIR__, 2);

    it('has the subjects the acceptance names', function (): void {
        $subjects = [];
        foreach (benchmarkClassFiles() as $file) {
            preg_match_all('/public function (bench\w+)\(/', (string) file_get_contents($file), $matches);
            $subjects[basename($file, '.php')] = $matches[1];
        }

        expect($subjects)->toBe([
            'RepackBench' => [
                'bench24OnEvenSessions',
                'bench24OnUnevenSessionsWithPins',
                'bench40OnEvenSessions',
                'bench40OnUnevenSessionsWithPins',
            ],
            'RoundRobinBench' => [
                'benchUnconstrained32',
                'benchRetriesUnderSeedProtection32',
                'benchUnsatisfiable24TwoLegs',
            ],
            'SwissBench' => [
                'benchSchedule24UnderARoleStreakLimit',
                'benchSchedule24ToTheLastRound',
            ],
        ]);
    });

    it('gives every benchmark class a time limit', function (): void {
        // Without one, a subject that runs away holds the CI job until the
        // job's own limit, and the base of a pull request may well run away:
        // it is the code before the change.
        expect(benchmarkClassFiles())->toHaveCount(3);
        foreach (benchmarkClassFiles() as $file) {
            Assert::assertMatchesRegularExpression(
                '/^#\[Timeout\(\d+\.\d+\)\]\nfinal class /m',
                (string) file_get_contents($file),
                basename($file) . ' sets no #[Timeout] on its class'
            );
        }
    });

    it('runs from Composer scripts that are not part of the gate', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);
        /** @var array{scripts: array<string, string|list<string>>, require-dev: array<string, string>} $composer */
        // Composer stops a script after five minutes unless told not to, and
        // a comparison of two source trees over several rounds takes longer.
        expect($composer['scripts']['bench'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            'phpbench run --config=tests/Benchmark/phpbench.json --report=aggregate',
        ])
            ->and($composer['scripts']['bench-compare'])->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php tests/bin/compare-benchmarks.php',
            ])
            ->and($composer['require-dev'])->toHaveKey('phpbench/phpbench');

        // A timing depends on the machine: the gate must give the same
        // answer everywhere.
        expect($composer['scripts']['ci'])->not->toContain('@bench')
            ->and($composer['scripts']['ci'])->not->toContain('@bench-compare');
    });

    it('keeps what it writes out of the repository root', function () use ($root): void {
        $configuration = json_decode((string) file_get_contents($root . '/tests/Benchmark/phpbench.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($configuration);

        // build/ is ignored; phpbench's default is .phpbench/ at the root.
        expect($configuration['storage.xml_storage_path'])->toBe('../../build/phpbench')
            ->and($configuration['runner.bootstrap'])->toBe('bootstrap.php')
            ->and(is_file($root . '/tests/Benchmark/bootstrap.php'))->toBeTrue();
    });

    it('refuses a comparison without a base to compare with', function (string $directory, string $message) use ($root): void {
        $process = proc_open(
            [PHP_BINARY, $root . '/tests/bin/compare-benchmarks.php', $root . $directory],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        Assert::assertIsResource($process);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(2)
            ->and($errors)->toContain($message);
    })->with([
        'no such directory' => ['/no-such-directory', 'Usage: php tests/bin/compare-benchmarks.php'],
        // The repository root holds src/ and is not src/: every class would
        // come from the working tree, and the change be compared with itself.
        'a directory that is not a copy of src/' => ['', 'is not a copy of src/'],
    ]);
});

describe('The Benchmarks job', function (): void {
    $root = dirname(__DIR__, 2);

    it('compares the change with its base on one runner, for pull requests only', function () use ($root): void {
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        if (preg_match('/^  benchmarks:\n((?:(?:    .*)?\n)*)/m', $workflow, $definition) !== 1) {
            Assert::fail('ci.yml has no `benchmarks` job');
        }
        $job = $definition[1];

        // There is a base only for a pull request.
        expect($job)->toContain("    if: github.event_name == 'pull_request'\n");

        // The base is taken as an archive of its src/, and both sides are
        // measured by the one script, in the one job.
        expect($job)->toContain('BASE_SHA: ${{ github.event.pull_request.base.sha }}')
            ->and($job)->toContain('git archive "$BASE_SHA" src | tar -x -C build/base')
            ->and($job)->toContain("      run: composer bench-compare -- build/base/src\n");
        expect(preg_match_all('/^\s*run:.*\bbench/m', $workflow))->toBe(1);
    });

    it('is not a required check and slows no required check', function () use ($root): void {
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        if (preg_match('/^  benchmarks:\n((?:(?:    .*)?\n)*)/m', $workflow, $definition) !== 1) {
            Assert::fail('ci.yml has no `benchmarks` job');
        }
        $job = $definition[1];

        // Its check name is its own, and none of the four that are required.
        preg_match_all('/^    name: (.+)$/m', $job, $names);
        expect($names[1])->toBe(['Benchmarks']);

        // A job of its own: no required job waits for it, and it waits for none.
        Assert::assertDoesNotMatchRegularExpression('/^\s*needs:/m', $workflow, 'A job of ci.yml waits for another');

        // A regression fails the job, where it is seen; it is not hidden
        // behind a tolerated failure.
        Assert::assertDoesNotMatchRegularExpression('/^\s*continue-on-error:/m', $job, 'The `benchmarks` job tolerates its own failure');

        // A limit of its own, so that a benchmark that runs away ends it.
        Assert::assertMatchesRegularExpression('/^    timeout-minutes: \d+$/m', $job, 'The `benchmarks` job has no time limit');
    });
});
