<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\MutationReport;
use PHPUnit\Framework\Assert;

// The summary the `Mutation testing` CI job publishes is made from what
// `composer mutation` printed (tests/Support/MutationReport.php, run by
// tests/bin/mutation-summary.php). These tests give it output in the shape
// Pest's mutation runner prints, colour codes included.

/**
 * The end of a run as Pest prints it to a terminal: two changes that no
 * test noticed, one that timed out, and the totals.
 */
function mutationRunOutput(): string
{
    $gray = "\e[90m";
    $reset = "\e[39m";

    return <<<TEXT

          {$gray}Tests:{$reset}    \e[32;1m12 passed\e[39;22m{$gray} (40 assertions){$reset}
          {$gray}Duration:{$reset} 0.43s

        \e[1A  {$gray}Parallel:{$reset} 4 processes

          Mutating application files...
          {$gray}7 Mutations for 2 Files created{$reset}

          ..\e[31;1mx\e[39;22m.\e[31;1mx\e[39;22m.t

          ----------------------------------------------------------------------------
           \e[41;1m UNTESTED \e[49;22m  src/Scheduling/SwissScheduler.php  {$gray}> Line 170: GreaterToGreaterOrEqual - ID: 0c8cd21dc19e4ac8{$reset}

                   }
          \e[31m-        if (\$rounds > count(\$participants) - 1) {\e[39m
          \e[32m+        if (\$rounds >= count(\$participants) - 1) {\e[39m
                       throw new InvalidConfigurationException(

          ----------------------------------------------------------------------------
           \e[41;1m UNTESTED \e[49;22m  src/Repack/Internal/LoadPlanner.php  {$gray}> Line 356: RemoveMethodCall - ID: 578efa5845de99fa{$reset}

                   for (\$session = 0; \$session < \$this->sessionCount; ++\$session) {
          \e[31m-            \$this->place(\$otherIndex, \$session);\e[39m
          \e[32m+            \e[39m
                   }

          ----------------------------------------------------------------------------
           \e[43;1m TIMEOUT \e[49;22m  src/Scheduling/SwissScheduler.php  {$gray}> Line 73: WhileAlwaysFalse - ID: f3ec62f8b340861a{$reset}

          \e[31m-            while (!\$engine->isComplete(\$state)) {\e[39m
          \e[32m+            while (true) {\e[39m

          {$gray}Mutations:{$reset} \e[31;1m2 untested\e[39;22m{$gray},{$reset} \e[32;1m1 timeout\e[39;22m{$gray},{$reset} \e[32;1m4 tested\e[39;22m
          {$gray}Score:{$reset}     71.43%
          {$gray}Duration:{$reset}  3.50s
          {$gray}Parallel:{$reset}  4 processes

        TEXT;
}

describe('the mutation report', function (): void {
    it('reads the score, the counts and every change that no test noticed', function (): void {
        $report = MutationReport::fromOutput(mutationRunOutput());

        expect($report->score)->toBe('71.43%')
            ->and($report->counts)->toBe(['untested' => 2, 'timeout' => 1, 'tested' => 4])
            ->and($report->survivors)->toBe([
                [
                    'outcome' => 'untested',
                    'file' => 'src/Scheduling/SwissScheduler.php',
                    'line' => 170,
                    'mutator' => 'GreaterToGreaterOrEqual',
                    'id' => '0c8cd21dc19e4ac8',
                    'change' => [
                        '-        if ($rounds > count($participants) - 1) {',
                        '+        if ($rounds >= count($participants) - 1) {',
                    ],
                ],
                [
                    'outcome' => 'untested',
                    'file' => 'src/Repack/Internal/LoadPlanner.php',
                    'line' => 356,
                    'mutator' => 'RemoveMethodCall',
                    'id' => '578efa5845de99fa',
                    'change' => [
                        '-            $this->place($otherIndex, $session);',
                        '+',
                    ],
                ],
                [
                    'outcome' => 'timeout',
                    'file' => 'src/Scheduling/SwissScheduler.php',
                    'line' => 73,
                    'mutator' => 'WhileAlwaysFalse',
                    'id' => 'f3ec62f8b340861a',
                    'change' => [
                        '-            while (!$engine->isComplete($state)) {',
                        '+            while (true) {',
                    ],
                ],
            ]);
    });

    it('writes the score, the counts, where the untested changes are and the changes themselves', function (): void {
        $markdown = MutationReport::fromOutput(mutationRunOutput())->toMarkdown();

        expect($markdown)
            ->toStartWith("## Mutation testing\n")
            ->toContain('**Score: 71.43%**')
            ->toContain('Mutations: 2 untested, 1 timeout, 4 tested.')
            ->toContain('| `src/Scheduling/SwissScheduler.php` | 1 |')
            ->toContain('| `src/Repack/Internal/LoadPlanner.php` | 1 |')
            ->toContain('| GreaterToGreaterOrEqual | 1 |')
            ->toContain('### The first 2 of 2 untested mutations')
            ->toContain("```diff\n-        if (\$rounds > count(\$participants) - 1) {\n+        if (\$rounds >= count(\$participants) - 1) {\n```")
            // A timeout is counted and not listed among the untested changes
            ->and($markdown)->not->toContain('WhileAlwaysFalse')
            // No terminal code reaches the page
            ->and($markdown)->not->toContain("\e");
    });

    it('shows no more untested changes in full than it is asked for, and still counts them all', function (): void {
        $markdown = MutationReport::fromOutput(mutationRunOutput())->toMarkdown(1);

        expect($markdown)
            ->toContain('### The first 1 of 2 untested mutations')
            ->toContain('GreaterToGreaterOrEqual (`0c8cd21dc19e4ac8`)')
            ->toContain('| RemoveMethodCall | 1 |')
            ->and($markdown)->not->toContain('RemoveMethodCall (`578efa5845de99fa`)');
    });

    it('says that a run printed no score, and invents none', function (string $output): void {
        $report = MutationReport::fromOutput($output);

        expect($report->score)->toBeNull()
            ->and($report->toMarkdown())->toContain('The run printed no score')
            ->and($report->toMarkdown())->not->toContain('Score:');
    })->with([
        'nothing printed' => [''],
        'the suite failed before any mutation' => ["  Tests:    1 failed, 11 passed (40 assertions)\n  Duration: 0.43s\n"],
        'stopped while mutating' => ["  Mutating application files...\n  7 Mutations for 2 Files created\n\n  ...x"],
    ]);

    it('lists no changes for a run in which every change was noticed', function (): void {
        $report = MutationReport::fromOutput("  Mutations: 7 tested\n  Score:     100.00%\n  Duration:  1.20s\n");

        expect($report->score)->toBe('100.00%')
            ->and($report->counts)->toBe(['tested' => 7])
            ->and($report->survivors)->toBe([])
            ->and($report->toMarkdown())->not->toContain('Untested mutations by file');
    });
});

describe('the mutation summary script', function (): void {
    /**
     * @return array{int, string, string} Exit status, standard output, standard error
     */
    $run = function (string ...$arguments): array {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/mutation-summary.php', ...array_values($arguments)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        Assert::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    };

    it('prints the summary of a log and exits 0 whatever the score is', function () use ($run): void {
        $log = tempnam(sys_get_temp_dir(), 'mutation-log-');
        Assert::assertIsString($log);
        file_put_contents($log, mutationRunOutput());

        try {
            [$status, $output, $errors] = $run($log);
        } finally {
            unlink($log);
        }

        expect($status)->toBe(0)
            ->and($errors)->toBe('')
            ->and($output)->toContain('**Score: 71.43%**');
    });

    it('exits 1 with its usage when the log cannot be read', function (array $arguments) use ($run): void {
        [$status, $output, $errors] = $run(...$arguments);

        expect($status)->toBe(1)
            ->and($output)->toBe('')
            ->and($errors)->toContain('Usage: php tests/bin/mutation-summary.php');
    })->with([
        'no argument' => [[]],
        'a file that does not exist' => [[sys_get_temp_dir() . '/no-such-mutation-log-' . bin2hex(random_bytes(6))]],
    ]);
});
