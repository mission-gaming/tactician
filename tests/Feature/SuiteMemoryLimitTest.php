<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\SuiteMemoryLimit;
use PHPUnit\Framework\Assert;

// The suite needs about as much memory as PHP's default limit allows, and a
// run that collects coverage needs several times that. A process of a test
// run therefore raises a limit that is too low, and must never lower one:
// PHP has no limit on a CI runner, and a limit stated in phpunit.xml, which
// PHPUnit sets outright, ended the Coverage job where the coverage is
// reported. tests/Support/SuiteMemoryLimit.php does it, called from
// tests/Pest.php. These tests start processes of their own, each with the
// limit PHP is to be configured with.

/**
 * Runs a PHP process with a memory limit and returns how it ended.
 *
 * @param string $limit The `memory_limit` PHP starts the process with
 * @param list<string> $command The arguments after the PHP binary and its options
 * @param array<string, string> $environment Set for the process, on top of this one's without the runner's variables
 *
 * @return array{int, string} Exit status, and what the process printed (standard output, then standard error)
 *
 * @throws PHPUnit\Framework\Exception
 */
function runWithMemoryLimit(string $limit, array $command, array $environment = []): array
{
    $inherited = getenv();
    unset(
        $inherited['PEST_MUTATION_TESTING'],
        $inherited['PEST_MUTATION_FILE'],
        $inherited['TACTICIAN_EXPECTED_MEMORY_LIMIT'],
        // A test process this one starts is not a worker of this run
        $inherited['PARATEST'],
        $inherited['TEST_TOKEN'],
        $inherited['UNIQUE_TEST_TOKEN']
    );

    $process = proc_open(
        [PHP_BINARY, '-d', "memory_limit={$limit}", '-d', 'display_errors=1', '-d', 'xdebug.mode=off', ...$command],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        [...$inherited, ...$environment]
    );
    Assert::assertIsResource($process);

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

/** A script that raises the limit as a process of a test run does and prints the limit it then has. */
const RAISES_AND_PRINTS_MEMORY_LIMIT = 'require "vendor/autoload.php";'
    . ' MissionGaming\Tactician\Tests\Support\SuiteMemoryLimit::raise();'
    . ' echo ini_get("memory_limit");';

describe('the memory limit of a test run', function (): void {
    it('has a floor of four gigabytes', function (): void {
        // The figure is in the comment of phpunit.xml and in the changelog
        // as well; SuiteMemoryLimit::FLOOR says what it was measured against
        expect(SuiteMemoryLimit::FLOOR)->toBe('4G');
    });

    it('tells a limit below the floor from one at or above it, and from no limit', function (string $limit, bool $below): void {
        expect(SuiteMemoryLimit::isBelowFloor($limit))->toBe($below);
    })->with([
        'the default of PHP' => ['128M', true],
        'the default of PHP, in bytes' => ['134217728', true],
        'the limit that ended the Coverage job' => ['1G', true],
        'one megabyte under the floor' => ['4095M', true],
        'nothing at all' => ['0', true],
        'the floor' => ['4G', false],
        'the floor in megabytes' => ['4096M', false],
        'the floor in bytes' => ['4294967296', false],
        'twice the floor' => ['8G', false],
        'no limit' => ['-1', false],
    ]);

    it('raises a limit below the floor to the floor', function (string $limit): void {
        [$status, $output] = runWithMemoryLimit($limit, ['-r', RAISES_AND_PRINTS_MEMORY_LIMIT]);

        expect($output)->toBe(SuiteMemoryLimit::FLOOR)
            ->and($status)->toBe(0);
    })->with(['128M', '134217728', '1G', '4095M']);

    it('leaves a limit at or above the floor, and no limit, as PHP was configured', function (string $limit): void {
        [$status, $output] = runWithMemoryLimit($limit, ['-r', RAISES_AND_PRINTS_MEMORY_LIMIT]);

        expect($output)->toBe($limit)
            ->and($status)->toBe(0);
    })->with(['4G', '4096M', '8G', '-1']);

    // The test process of a mutation has a limit of its own, which
    // `composer mutation` installs before Pest starts and which is there so
    // that a change that allocates without end stops itself
    // (tests/Feature/MutationMemoryLimitTest.php). Raised to the floor, four
    // such processes would take the memory of a CI runner.
    it('leaves the limit of the test process of a mutation alone', function (): void {
        [$status, $output] = runWithMemoryLimit('48M', ['-r', RAISES_AND_PRINTS_MEMORY_LIMIT], ['PEST_MUTATION_TESTING' => 'src/Repack/Internal/SessionPacker.php']);

        expect($output)->toBe('48M')
            ->and($status)->toBe(0);
    });

    // The whole of it: Pest, with the configuration of the gate, running a
    // test that fails unless the process has the limit named. This is what
    // shows that tests/Pest.php makes the call, and that nothing in
    // phpunit.xml sets the limit after it.
    it('gives a Pest process the floor, or the higher limit PHP was configured with', function (string $limit, string $expected): void {
        [$status, $output] = runWithMemoryLimit(
            $limit,
            ['vendor/bin/pest', 'tests/Fixtures/memory/ReportsItsLimit.php'],
            ['TACTICIAN_EXPECTED_MEMORY_LIMIT' => $expected]
        );

        expect($status)->toBe(0, $output);
    })->with([
        'the default of PHP' => ['128M', SuiteMemoryLimit::FLOOR],
        'twice the floor' => ['8G', '8G'],
        'no limit, as on a CI runner' => ['-1', '-1'],
    ]);

    // A worker does not get the `-d` options of the process that started
    // it: it has the limit of PHP's configuration files, which this test
    // cannot set. So the worker is asked only for a limit that is not below
    // the floor, whatever it started with.
    it('gives the workers of a parallel run a limit that is not below the floor', function (): void {
        [$status, $output] = runWithMemoryLimit('128M', ['vendor/bin/pest', '--parallel', 'tests/Fixtures/memory/ReportsItsLimit.php']);

        expect($status)->toBe(0, $output);
    });

    it('fails the fixture when the process does not have the limit named', function (): void {
        // Otherwise the two tests above could pass on a fixture that asserts nothing
        [$status, $output] = runWithMemoryLimit(
            '128M',
            ['vendor/bin/pest', 'tests/Fixtures/memory/ReportsItsLimit.php'],
            ['TACTICIAN_EXPECTED_MEMORY_LIMIT' => '128M']
        );

        expect($status)->not->toBe(0, $output)
            ->and($output)->toContain(SuiteMemoryLimit::FLOOR);
    });

    it('has run in this process', function (): void {
        expect(SuiteMemoryLimit::isBelowFloor((string) ini_get('memory_limit')))->toBeFalse();
    });
});
