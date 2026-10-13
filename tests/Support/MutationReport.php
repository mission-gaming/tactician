<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

/**
 * What a run of `composer mutation` printed, read back from its output.
 *
 * Pest's mutation testing changes one thing at a time in the source (a `<`
 * to a `<=`, a call removed) and runs the tests that cover the line. A
 * mutation the tests fail on is "tested"; one they still pass on is
 * "untested": nothing in the suite notices that change. The score is the
 * share that was noticed.
 *
 * The run prints every mutation that was not noticed, with the change, and
 * ends with the counts and the score. This class reads that text, which may
 * carry terminal colour codes, and writes the summary the CI job publishes.
 * It reads what is printed and nothing else: a run that printed no score
 * has none ({@see MutationReport::$score} is null), and the summary says so.
 *
 * Three things the printed score does not say, which the summary adds:
 *
 * - The runner counts a mutation whose tests ran into the time limit as
 *   tested: its score is (tested + timeout) / all. A change that makes a
 *   search loop for ever is noticed only in that sense, so the summary also
 *   gives the share that a failing test noticed.
 * - A run that is stopped (the CI job has a time limit) prints no totals.
 *   It has printed one character per finished mutation by then, and the
 *   summary counts those: how far the run got and what it had found, with
 *   no score, because a part of a run has none.
 * - The runner counts a mutation whose test process ended at its memory
 *   limit as tested too, and prints nothing that tells it from one a test
 *   failed on. Those processes write a log of their own
 *   ({@see MutationMemoryLimit}); given that log, the summary says how many
 *   there were and leaves them out of the share a failing test noticed. A
 *   run that left no log had no memory limit, and the summary says that
 *   instead: "none ended at the limit" would be untrue of it.
 */
final readonly class MutationReport
{
    /**
     * @param string|null $score The score as printed, for example "83.52%"; null when the run printed none
     * @param array<string, int> $counts Outcome => mutations, as printed: "untested", "tested", "timeout", "uncovered"
     * @param list<array{outcome: string, file: string, line: int, mutator: string, id: string, change: list<string>}> $survivors
     *     Every mutation the run printed as not noticed, in the order printed
     * @param int|null $created How many mutations the run said it created; null when it did not get that far
     * @param array{tested: int, untested: int, timeout: int, uncovered: int} $progress
     *     The mutations the run had finished, counted in the one character it prints for each as it goes
     * @param array<string, int>|null $memoryLimited Source file => mutations whose test process ended at its
     *     memory limit, most first; null when the run left no log of those processes, which is a run
     *     whose test processes had no memory limit
     */
    private function __construct(
        public ?string $score,
        public array $counts,
        public array $survivors,
        public ?int $created,
        public array $progress,
        public ?array $memoryLimited
    ) {}

    /**
     * @param string $output What the run printed
     * @param string|null $memoryLog What its test processes wrote to {@see MutationMemoryLimit::LOG}; an empty
     *     string for a run in which no process ended at the limit, and null for a run that left no such
     *     file, which is one that was not started through `composer mutation` and had no limit
     */
    public static function fromOutput(string $output, ?string $memoryLog = null): self
    {
        $lines = explode("\n", str_replace("\r", '', (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output)));

        $score = null;
        $counts = [];
        $survivors = [];
        $current = null;
        $created = null;
        $progress = ['tested' => 0, 'untested' => 0, 'timeout' => 0, 'uncovered' => 0];
        $inProgress = false;

        foreach ($lines as $line) {
            // The progress of the mutations: one character each, between
            // the line that says how many were created and the first block
            // or the totals. The dots before that line are the test suite's.
            if (preg_match('/^\s*(\d+) Mutations for \d+ Files created\s*$/', $line, $start) === 1) {
                $created = (int) $start[1];
                $inProgress = true;

                continue;
            }
            if ($inProgress) {
                $marks = trim($line);
                if ($marks === '') {
                    continue;
                }
                if (preg_match('/^[.xt-]+$/', $marks) === 1 && preg_match('/^-{20,}$/', $marks) !== 1) {
                    $progress['tested'] += substr_count($marks, '.');
                    $progress['untested'] += substr_count($marks, 'x');
                    $progress['timeout'] += substr_count($marks, 't');
                    $progress['uncovered'] += substr_count($marks, '-');

                    continue;
                }
                $inProgress = false;
            }

            if (preg_match('/^\s*(UNTESTED|UNCOVERED|TIMEOUT)\s+(\S+)\s+> Line (\d+): (\w+) - ID: (\w+)\s*$/', $line, $header) === 1) {
                if ($current !== null) {
                    $survivors[] = $current;
                }
                $current = [
                    'outcome' => strtolower($header[1]),
                    'file' => $header[2],
                    'line' => (int) $header[3],
                    'mutator' => $header[4],
                    'id' => $header[5],
                    'change' => [],
                ];

                continue;
            }

            if (preg_match('/^\s*Mutations:\s+(.+)$/', $line, $summary) === 1) {
                foreach (explode(',', $summary[1]) as $part) {
                    if (preg_match('/^\s*(\d+)\s+(\w+)\s*$/', $part, $count) === 1) {
                        $counts[$count[2]] = (int) $count[1];
                    }
                }
            } elseif (preg_match('/^\s*Score:\s+(\d+(?:\.\d+)?%)\s*$/', $line, $printed) === 1) {
                $score = $printed[1];
            }

            if ($current === null) {
                continue;
            }

            // A block ends at the rule under it, or at the summary
            if (preg_match('/^\s*-{20,}\s*$/', $line) === 1 || preg_match('/^\s*(Mutations|Score|Duration|Parallel):/', $line) === 1) {
                $survivors[] = $current;
                $current = null;

                continue;
            }

            // The changed lines only: the context around them is not kept
            if (preg_match('/^\s{2}[-+]\s/', $line) === 1 || preg_match('/^\s{2}[-+]$/', rtrim($line)) === 1) {
                $current['change'][] = rtrim(substr($line, 2));
            }
        }

        if ($current !== null) {
            $survivors[] = $current;
        }

        return new self($score, $counts, $survivors, $created, $progress, $memoryLog === null ? null : MutationMemoryLimit::read($memoryLog));
    }

    /**
     * The summary for the CI job, as Markdown: the score, the counts, where
     * the unnoticed mutations are, and the first of them with their change.
     *
     * @param int $limit How many unnoticed mutations to show in full
     * @param string|null $shard The name of the part of the source this run mutated, when it was one of several
     * @param list<string> $paths The files or directories of that part
     */
    public function toMarkdown(int $limit = 25, ?string $shard = null, array $paths = []): string
    {
        $markdown = [($shard === null ? '## Mutation testing' : "## Mutation testing: {$shard}") . "\n"];

        if ($paths !== []) {
            $markdown[] = 'Mutated: ' . implode(', ', array_map(static fn(string $path): string => "`{$path}`", $paths)) . ".\n";
        }

        if ($this->score === null) {
            $markdown[] = "The run printed no score: it did not finish. See the log of the step above.\n";

            $finished = array_sum($this->progress);
            if ($this->created !== null && $finished > 0) {
                $markdown[] = sprintf(
                    "It was stopped after %d of %d mutations: %d tested, %d untested, %d timeout%s. That is not a score: the mutations it did not reach are not counted, and the untested ones are listed only at the end of a run.\n",
                    $finished,
                    $this->created,
                    $this->progress['tested'],
                    $this->progress['untested'],
                    $this->progress['timeout'],
                    $this->progress['uncovered'] > 0 ? ", {$this->progress['uncovered']} uncovered" : ''
                );
            }
            $markdown[] = $this->memorySentence() . "\n";

            return implode("\n", $markdown);
        }

        $markdown[] = "**Score: {$this->score}**\n";

        $counts = [];
        foreach ($this->counts as $outcome => $count) {
            $counts[] = "{$count} {$outcome}";
        }
        $markdown[] = 'Mutations: ' . implode(', ', $counts) . ".\n";
        $markdown[] = "A tested mutation is a change to the source that made a test fail. An untested one is a change that no test noticed. The score is reported, not enforced: this job does not fail on it.\n";

        $markdown[] = $this->memorySentence() . "\n";

        // The second figure, always: without a timeout and without a
        // process that ended at its memory limit it is the score
        $all = array_sum($this->counts);
        $noticed = max(0, ($this->counts['tested'] ?? 0) - array_sum($this->memoryLimited ?? []));
        if ($all > 0) {
            $markdown[] = sprintf(
                "%sA failing test noticed %d of %d mutations, which is %s%%.\n",
                ($this->counts['timeout'] ?? 0) > 0
                    ? 'The score counts a mutation whose tests ran into the time limit as tested: no test failed on it, the tests were cut off. '
                    : '',
                $noticed,
                $all,
                number_format($noticed / $all * 100, 2)
            );
        }

        $untested = array_values(array_filter($this->survivors, static fn(array $survivor): bool => $survivor['outcome'] === 'untested'));
        if ($untested === []) {
            return implode("\n", $markdown);
        }

        $byFile = [];
        $byMutator = [];
        foreach ($untested as $survivor) {
            $byFile[$survivor['file']] = ($byFile[$survivor['file']] ?? 0) + 1;
            $byMutator[$survivor['mutator']] = ($byMutator[$survivor['mutator']] ?? 0) + 1;
        }
        arsort($byFile);
        arsort($byMutator);

        $markdown[] = "### Untested mutations by file\n";
        $markdown[] = '| File | Untested |';
        $markdown[] = '|------|---------:|';
        foreach ($byFile as $file => $count) {
            $markdown[] = "| `{$file}` | {$count} |";
        }

        $markdown[] = "\n### Untested mutations by kind\n";
        $markdown[] = '| Mutator | Untested |';
        $markdown[] = '|---------|---------:|';
        foreach ($byMutator as $mutator => $count) {
            $markdown[] = "| {$mutator} | {$count} |";
        }

        $shown = array_slice($untested, 0, $limit);
        $markdown[] = sprintf("\n### The first %d of %d untested mutations\n", count($shown), count($untested));
        foreach ($shown as $survivor) {
            $markdown[] = "`{$survivor['file']}` line {$survivor['line']}, {$survivor['mutator']} (`{$survivor['id']}`)\n";
            $markdown[] = "```diff\n" . implode("\n", $survivor['change']) . "\n```\n";
        }

        return implode("\n", $markdown);
    }

    /**
     * How many mutations ended at the memory limit of their test process,
     * and in which source files; or that the run had no such limit.
     */
    private function memorySentence(): string
    {
        if ($this->memoryLimited === null) {
            return 'The test processes of this run had no memory limit: it left no memory log, which a run started with `composer mutation` does. '
                . 'A mutation that makes the code allocate without end is then stopped at the time limit only, if the machine lasts that long, and none is counted here.';
        }

        $count = array_sum($this->memoryLimited ?? []);
        if ($count === 0) {
            return 'No mutation ended at the memory limit of its test process.';
        }

        $files = [];
        foreach ($this->memoryLimited ?? [] as $file => $mutations) {
            $files[] = "`{$file}` ({$mutations})";
        }

        return sprintf(
            '%d %s at the memory limit of the test process: %s. The runner counts such a mutation as tested, although no test failed on it: PHP ended the process.',
            $count,
            $count === 1 ? 'mutation ended' : 'mutations ended',
            implode(', ', $files)
        );
    }
}
