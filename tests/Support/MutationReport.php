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
 */
final readonly class MutationReport
{
    /**
     * @param string|null $score The score as printed, for example "83.52%"; null when the run printed none
     * @param array<string, int> $counts Outcome => mutations, as printed: "untested", "tested", "timeout", "uncovered"
     * @param list<array{outcome: string, file: string, line: int, mutator: string, id: string, change: list<string>}> $survivors
     *     Every mutation the run printed as not noticed, in the order printed
     */
    private function __construct(
        public ?string $score,
        public array $counts,
        public array $survivors
    ) {}

    public static function fromOutput(string $output): self
    {
        $lines = explode("\n", str_replace("\r", '', (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output)));

        $score = null;
        $counts = [];
        $survivors = [];
        $current = null;

        foreach ($lines as $line) {
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

        return new self($score, $counts, $survivors);
    }

    /**
     * The summary for the CI job, as Markdown: the score, the counts, where
     * the unnoticed mutations are, and the first of them with their change.
     *
     * @param int $limit How many unnoticed mutations to show in full
     */
    public function toMarkdown(int $limit = 25): string
    {
        $markdown = ["## Mutation testing\n"];

        if ($this->score === null) {
            $markdown[] = "The run printed no score: it did not finish. See the log of the step above.\n";

            return implode("\n", $markdown);
        }

        $markdown[] = "**Score: {$this->score}**\n";

        $counts = [];
        foreach ($this->counts as $outcome => $count) {
            $counts[] = "{$count} {$outcome}";
        }
        $markdown[] = 'Mutations: ' . implode(', ', $counts) . ".\n";
        $markdown[] = "A tested mutation is a change to the source that made a test fail. An untested one is a change that no test noticed. The score is reported, not enforced: this job does not fail on it.\n";

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
}
