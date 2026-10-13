<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\MutationMemoryLimit;
use PHPUnit\Framework\Assert;

// A mutation can make the code under test allocate without end. On a CI
// runner such a test process took the memory of the whole machine, and the
// job was ended without a summary. tests/Support/MutationMemoryLimit.php
// gives the test process of a mutation a memory limit and has it log that it
// ended there; Composer's autoloader loads tests/mutation-memory.php, which
// installs it. These tests start processes of their own, as the runner
// would, and give each a log of its own.

/**
 * Runs a PHP process and returns how it ended.
 *
 * @param list<string> $command The arguments after the PHP binary
 * @param array<string, string> $environment Set for the process, on top of this one's without the runner's variables
 *
 * @return array{int, string} Exit status, and what the process printed (standard output, then standard error)
 *
 * @throws PHPUnit\Framework\Exception
 */
function runForMutationMemoryLimit(array $command, array $environment): array
{
    $inherited = getenv();
    unset(
        $inherited['PEST_MUTATION_TESTING'],
        $inherited['PEST_MUTATION_FILE'],
        $inherited[MutationMemoryLimit::ENV_LIMIT],
        $inherited[MutationMemoryLimit::ENV_LOG],
        $inherited[MutationMemoryLimit::ENV_RUN],
        // A test process this one starts is not a worker of this run
        $inherited['PARATEST'],
        $inherited['TEST_TOKEN'],
        $inherited['UNIQUE_TEST_TOKEN']
    );

    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'display_errors=1', '-d', 'xdebug.mode=off', ...$command],
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

/**
 * Runs a PHP process that loads the autoloader, as every process of a
 * development checkout does, prints its memory limit and then does what
 * `$then` names: "allocate" (without end), "spawn" (start a process that
 * allocates without end, as a test can and as a parallel run starts its
 * workers, wait for it, and then allocate without end too), "throw", "fatal"
 * (an error that is not about memory) or "pass".
 *
 * @param array<string, string> $environment
 * @param list<string> $arguments Further arguments of the process
 *
 * @return array{int, string}
 *
 * @throws PHPUnit\Framework\Exception
 */
function loadAutoloaderUnderMutationMemoryLimit(string $then, array $environment, array $arguments = []): array
{
    $script = <<<'SCRIPT'
        require 'vendor/autoload.php';
        echo 'limit=', ini_get('memory_limit'), "\n";
        if ($argv[1] === 'spawn') {
            $worker = 'require "vendor/autoload.php"; for ($held = [];;) { $held[] = str_repeat("x", 4096); }';
            (new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=256M', '-r', $worker]))->run();
        }
        if ($argv[1] === 'allocate' || $argv[1] === 'spawn') {
            for ($held = [];;) {
                $held[] = str_repeat('x', 4096);
            }
        }
        if ($argv[1] === 'throw') {
            throw new RuntimeException('A test failed');
        }
        if ($argv[1] === 'fatal') {
            no_such_function();
        }
        SCRIPT;

    return runForMutationMemoryLimit(['-r', $script, '--', $then, ...$arguments], $environment);
}

describe('the memory limit of a mutation run', function (): void {
    beforeEach(function (): void {
        $root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/mutation-memory-' . bin2hex(random_bytes(6));
        $this->log = $this->directory . '/build/mutation-memory.log';
        mkdir($this->directory);
        // What Pest's runner sets for the test process of a mutation: the
        // source file, and the copy of it that holds the change. The copy
        // here is the source unchanged
        copy($root . '/src/Repack/Internal/SessionPacker.php', $this->directory . '/b3d2a3ad669392b9');
        $this->mutant = [
            'PEST_MUTATION_TESTING' => $root . '/src/Repack/Internal/SessionPacker.php',
            'PEST_MUTATION_FILE' => $this->directory . '/b3d2a3ad669392b9',
            MutationMemoryLimit::ENV_LIMIT => '48M',
            MutationMemoryLimit::ENV_LOG => $this->log,
        ];
    });

    afterEach(function (): void {
        foreach ([$this->log, $this->directory . '/b3d2a3ad669392b9'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir(dirname($this->log))) {
            rmdir(dirname($this->log));
        }
        rmdir($this->directory);
    });

    it('ends the test process of a mutation that allocates without end, and logs the mutation', function (): void {
        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('allocate', $this->mutant);

        // A failed process is what the runner counts as a tested mutation
        expect($status)->not->toBe(0)
            ->and($output)->toContain('limit=48M')
            ->toContain('Allowed memory size of 50331648 bytes exhausted');

        // The source file from the root of the repository, the name of the
        // mutated copy, and a name the process made up for this test run;
        // the directory of the log did not exist
        expect(is_file($this->log))->toBeTrue()
            ->and(file_get_contents($this->log))->toMatch('/^src\/Repack\/Internal\/SessionPacker\.php\tb3d2a3ad669392b9\t\d+\.\d+\n$/');
    });

    // The whole of it, in the process the runner starts: Pest, with the
    // configuration of `composer mutation`, running a test that allocates
    // without end. The runner starts that process without `--parallel`,
    // whatever the run itself was given, and with the variables of a
    // worker, so the tests of a mutation run in one process; the second
    // case is a run with workers all the same, where it is a worker that
    // ends at the limit. Pest ends the process in a shutdown function of
    // its own when it sees a fatal error, and no shutdown function
    // registered after that one runs: the line is written only because the
    // autoloader registers ours first. Installed from a PHPUnit bootstrap,
    // the limit held and nothing was logged.
    it('logs the mutation from a Pest process, which ends itself on a fatal error', function (bool $parallel): void {
        [$status, $output] = runForMutationMemoryLimit(
            [
                'vendor/bin/pest',
                '--configuration=phpunit.mutation.xml',
                ...($parallel ? ['--parallel'] : []),
                '--bail',
                'tests/Fixtures/mutation/AllocatesWithoutEnd.php',
            ],
            $parallel ? $this->mutant : [...$this->mutant, 'PARATEST' => '1', 'TEST_TOKEN' => '1', 'UNIQUE_TEST_TOKEN' => '1_mutation']
        );

        expect($status)->not->toBe(0, $output)
            ->and($output)->toContain('Allowed memory size of 50331648 bytes exhausted');
        expect(is_file($this->log))->toBeTrue($output)
            ->and(MutationMemoryLimit::read((string) file_get_contents($this->log)))->toBe(['src/Repack/Internal/SessionPacker.php' => 1]);
    })->with([
        'in one process, as the mutation run starts it' => [false],
        'with workers' => [true],
    ]);

    it('limits the test process of a mutation to one gigabyte unless the environment names another limit', function (): void {
        unset($this->mutant[MutationMemoryLimit::ENV_LIMIT]);
        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('pass', $this->mutant);

        expect(MutationMemoryLimit::DEFAULT)->toBe('1G')
            ->and($status)->toBe(0)
            ->and($output)->toBe("limit=1G\n");

        [, $output] = loadAutoloaderUnderMutationMemoryLimit('pass', [...$this->mutant, MutationMemoryLimit::ENV_LIMIT => '']);
        expect($output)->toBe("limit=1G\n");
    });

    it('writes the log to build/mutation-memory.log of the repository unless the environment names another file', function (): void {
        expect(MutationMemoryLimit::LOG)->toBe('build/mutation-memory.log')
            ->and(file(dirname(__DIR__, 2) . '/.gitignore', FILE_IGNORE_NEW_LINES))->toContain('/build');
    });

    it('logs nothing for a test process that ends in any other way', function (string $then, bool $succeeds): void {
        [$status] = loadAutoloaderUnderMutationMemoryLimit($then, $this->mutant);

        expect($status === 0)->toBe($succeeds)
            ->and(is_file($this->log))->toBeFalse();
    })->with([
        'the tests pass' => ['pass', true],
        'a test fails' => ['throw', false],
        'an error that is not about memory' => ['fatal', false],
    ]);

    it('adds a line for each process, after the lines that are there', function (): void {
        loadAutoloaderUnderMutationMemoryLimit('allocate', $this->mutant);
        loadAutoloaderUnderMutationMemoryLimit('allocate', [
            ...$this->mutant,
            'PEST_MUTATION_TESTING' => dirname(__DIR__, 2) . '/src/Scheduling/PotDrawScheduler.php',
            'PEST_MUTATION_FILE' => '/elsewhere/0f0f0f0f0f0f0f0f',
        ]);

        expect(preg_replace('/\t[^\t\n]+\n/', "\n", (string) file_get_contents($this->log)))
            ->toBe("src/Repack/Internal/SessionPacker.php\tb3d2a3ad669392b9\nsrc/Scheduling/PotDrawScheduler.php\t0f0f0f0f0f0f0f0f\n");
    });

    // The name of the mutated copy is a hash of the mutated source, and two
    // mutators can write the same source. The runner counts those as two
    // mutations and tests each in a run of its own, so a log that told
    // mutations apart by the copy would count one where the runner counted
    // two tested, and the share that a failing test noticed would be one
    // too high
    it('counts two mutations that have the same mutated copy as two', function (): void {
        loadAutoloaderUnderMutationMemoryLimit('allocate', $this->mutant);
        loadAutoloaderUnderMutationMemoryLimit('allocate', $this->mutant);

        $log = (string) file_get_contents($this->log);

        expect(substr_count($log, "\tb3d2a3ad669392b9\t"))->toBe(2)
            ->and(MutationMemoryLimit::read($log))->toBe(['src/Repack/Internal/SessionPacker.php' => 2]);
    });

    // The other way round. The runner starts one process for a mutation,
    // but a test can start a process of its own, and both can end at the
    // limit. The second inherits the name of the run from the first, also
    // through Symfony's Process, which passes on only a part of the
    // environment
    it('counts a mutation once when several processes of its test run ended at the limit', function (): void {
        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('spawn', $this->mutant);

        $log = (string) file_get_contents($this->log);
        $lines = explode("\n", trim($log));

        expect($status)->not->toBe(0)
            ->and($output)->toContain('Allowed memory size of 50331648 bytes exhausted')
            // The process that was started, and then the one that started it
            ->and($lines)->toHaveCount(2)
            ->and($lines[0])->toBe($lines[1])
            ->and(MutationMemoryLimit::read($log))->toBe(['src/Repack/Internal/SessionPacker.php' => 1]);
    });

    // The process that starts the run, which is given `--mutate`: it holds
    // the coverage of the whole first run of the tests, so its memory is
    // not limited here, and it starts the log afresh
    it('leaves the limit of the process that starts a mutation run alone, and has it remove the log of an earlier run', function (): void {
        $environment = [MutationMemoryLimit::ENV_LOG => $this->log, MutationMemoryLimit::ENV_LIMIT => '48M'];
        mkdir(dirname($this->log));
        file_put_contents($this->log, "src/A.php\told\n");

        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('pass', $environment, ['--mutate']);

        expect($status)->toBe(0)
            ->and($output)->toBe("limit=256M\n")
            ->and(is_file($this->log))->toBeFalse();

        // And with no log to remove
        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('pass', $environment, ['--mutate']);
        expect($status)->toBe(0)
            ->and($output)->toBe("limit=256M\n");
    });

    // Every process of a development checkout loads the autoloader: an
    // ordinary test run, a static analysis, the summary script that is
    // about to read the log
    it('does nothing in a process that neither tests a mutation nor starts a mutation run', function (): void {
        mkdir(dirname($this->log));
        file_put_contents($this->log, "src/A.php\tkept\n");

        [$status, $output] = loadAutoloaderUnderMutationMemoryLimit('pass', [MutationMemoryLimit::ENV_LOG => $this->log, MutationMemoryLimit::ENV_LIMIT => '48M']);

        expect($status)->toBe(0)
            ->and($output)->toBe("limit=256M\n")
            ->and(file_get_contents($this->log))->toBe("src/A.php\tkept\n");
    });

    it('is loaded by the autoloader of a development checkout, before anything else can register a shutdown function', function (): void {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        /** @var array{autoload: array<string, mixed>, autoload-dev: array{files: list<string>}} $composer */
        expect($composer['autoload-dev']['files'])->toBe(['tests/mutation-memory.php'])
            // Not in what a consumer loads
            ->and($composer['autoload'])->toHaveKeys(['psr-4'])
            ->and($composer['autoload'])->not->toHaveKey('files');
    });

    it('reads a log as mutations by source file, each once, most first and then by name', function (): void {
        $log = "src/B.php\tb1\t1.1\nsrc/A.php\ta1\t2.2\nsrc/C.php\tc1\t3.3\nsrc/C.php\tc2\t4.4\nsrc/C.php\tc1\t3.3\n"
            // Not lines of the log: no run, no copy, no source, something else
            . "\nnot a line of the log\n\tx\t5.5\nsrc/D.php\t\t6.6\nsrc/D.php\td1\nsrc/D.php\td1\t\n";

        expect(MutationMemoryLimit::read($log))->toBe(['src/C.php' => 2, 'src/A.php' => 1, 'src/B.php' => 1])
            ->and(MutationMemoryLimit::read(''))->toBe([]);
    });
});
