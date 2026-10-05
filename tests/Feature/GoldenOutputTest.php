<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\GoldenCases;
use MissionGaming\Tactician\Tests\Support\GoldenText;
use PHPUnit\Framework\Assert;

/*
 * Golden-output tests: the generated output of every algorithm, pinned as
 * readable text under tests/Fixtures/golden/. The baseline was captured
 * from the v0.2.0 tag, so a failure here means generated output changed.
 * That is allowed, but never silently: explain the change in the
 * changelog and regenerate the fixtures with `composer golden-update`.
 *
 * The files under examples/ pin something else: what each plain-text
 * script in examples/ prints. A difference there means the example now
 * prints something else, because the library changed or because the
 * script was edited. Review the difference before regenerating.
 *
 * The cases live in MissionGaming\Tactician\Tests\Support\GoldenCases;
 * this file only compares them with (or, when UPDATE_GOLDEN=1, writes
 * them to) the stored files.
 */

const GOLDEN_DIRECTORY = __DIR__ . '/../Fixtures/golden';

/**
 * Whether this run rewrites the fixtures instead of only comparing them.
 *
 * A rewriting run compares every case with the file it has just written,
 * so it can never fail. That is only acceptable on a developer machine,
 * where the rewritten files show up as a diff to review: on CI it would
 * turn the whole comparison into a silent pass, so it is refused there.
 *
 * @throws PHPUnit\Framework\AssertionFailedError When requested on CI
 */
function goldenUpdateRequested(): bool
{
    if (getenv('UPDATE_GOLDEN') !== '1') {
        return false;
    }

    if (goldenRunsOnCi(getenv('CI'))) {
        Assert::fail(
            'UPDATE_GOLDEN=1 is set on CI. A regenerating run compares each golden file with itself and '
            . 'cannot fail: regenerate locally with `composer golden-update` and commit the reviewed diff.'
        );
    }

    return true;
}

/**
 * @param string|false $ci The CI environment variable, false when unset
 */
function goldenRunsOnCi(string|false $ci): bool
{
    return $ci !== false && !in_array(strtolower($ci), ['', '0', 'false'], true);
}

/**
 * @throws PHPUnit\Framework\AssertionFailedError When the golden file is missing
 */
function goldenStored(string $file): string
{
    $path = GOLDEN_DIRECTORY . '/' . $file;
    if (!is_file($path)) {
        Assert::fail("Golden file tests/Fixtures/golden/{$file} is missing. Generate it with `composer golden-update`.");
    }

    return (string) file_get_contents($path);
}

/**
 * The `== title ==` sections of a stored text fixture, in file order.
 *
 * @return array<string, list<string>> Section title => its lines
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the golden file is missing
 */
function goldenSections(string $file): array
{
    $sections = [];
    foreach (explode("\n\n", rtrim(goldenStored($file), "\n")) as $block) {
        $lines = explode("\n", $block);
        if (preg_match('/^== (.+) ==$/', $lines[0], $matches) === 1) {
            $sections[$matches[1]] = array_slice($lines, 1);
        }
    }

    return $sections;
}

/**
 * The failure message for a stored fixture that no longer matches: names
 * the file and the first differing line, and says what a difference means.
 */
function goldenMismatchMessage(string $file, string $expected, string $actual): string
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $line = 0;
    while (
        isset($expectedLines[$line], $actualLines[$line])
        && $expectedLines[$line] === $actualLines[$line]
    ) {
        ++$line;
    }

    $meaning = str_starts_with($file, 'examples/')
        ? 'This file pins what an example script prints. If the new output is correct, regenerate the fixtures '
            . 'with `composer golden-update` and review the difference; if the library produced it and the '
            . 'example was not edited, it is a change to generated output and belongs in the changelog.'
        : 'A difference from a golden file is a change to generated output. If it is intended, '
            . 'explain it in the changelog and regenerate the fixtures with `composer golden-update`; '
            . 'if it is not, the change under test altered output it should not have.';

    return sprintf(
        "Generated output differs from tests/Fixtures/golden/%s at line %d.\n"
        . "  stored:    %s\n"
        . "  generated: %s\n"
        . '%s',
        $file,
        $line + 1,
        $expectedLines[$line] ?? '(end of file)',
        $actualLines[$line] ?? '(end of file)',
        $meaning
    );
}

/**
 * The stored wire payload: the file is the exact payload followed by one
 * newline.
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the golden file is missing or is not a single line
 */
function goldenWirePayload(string $file): string
{
    $stored = goldenStored($file);
    if (!str_ends_with($stored, "\n") || str_contains(substr($stored, 0, -1), "\n")) {
        Assert::fail("Golden file tests/Fixtures/golden/{$file} must be one payload line followed by one newline.");
    }

    return substr($stored, 0, -1);
}

describe('Golden output', function (): void {
    it('matches the stored golden file', function (string $file): void {
        $actual = GoldenCases::all()[$file]();
        $path = GOLDEN_DIRECTORY . '/' . $file;

        if (goldenUpdateRequested()) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $actual);
        }

        if (!is_file($path)) {
            Assert::fail(
                "Golden file tests/Fixtures/golden/{$file} is missing. Every golden case needs a stored "
                . 'baseline: generate it with `composer golden-update` and review it before committing.'
            );
        }

        $expected = (string) file_get_contents($path);
        if ($actual !== $expected) {
            Assert::fail(goldenMismatchMessage($file, $expected, $actual));
        }

        expect($actual)->toBe($expected);
    })->with(array_keys(GoldenCases::all()));

    // A fixture nothing generates is a baseline nothing checks
    it('stores no golden file without a case', function (): void {
        $stored = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(GOLDEN_DIRECTORY, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $stored[] = str_replace('\\', '/', substr($file->getPathname(), strlen(GOLDEN_DIRECTORY) + 1));
        }
        sort($stored);

        $cases = array_keys(GoldenCases::all());
        sort($cases);

        expect($stored)->toBe($cases);
    });

    // Deleting a case together with its file satisfies both tests above,
    // so the set of pinned files is itself pinned
    it('pins every file of the matrix', function (): void {
        expect(array_keys(GoldenCases::all()))->toBe([
            'round-robin/mirrored-unseeded.txt',
            'round-robin/mirrored-seed-1.txt',
            'round-robin/mirrored-seed-42.txt',
            'round-robin/mirrored-seed-1337.txt',
            'round-robin/repeated-unseeded.txt',
            'round-robin/repeated-seed-1.txt',
            'round-robin/repeated-seed-42.txt',
            'round-robin/repeated-seed-1337.txt',
            'round-robin/constrained.txt',
            'swiss.txt',
            'single-elimination.txt',
            'double-elimination.txt',
            'repack/scenario.txt',
            'repack/round-robin.txt',
            'wire/schedule.json',
            'wire/stage-state.json',
            'examples/13-swiss-stage-engine.txt',
            'examples/14-groups-to-knockout.txt',
            'examples/15-timeline-assignment.txt',
            'examples/16-backtracking-generation.txt',
            'examples/17-schedule-optimization.txt',
            'examples/18-stateless-web-flow.txt',
            'examples/19-repacking-a-season.txt',
        ]);
    });

    it('stores every fixture with LF line endings and one final newline', function (string $file): void {
        $stored = goldenStored($file);

        expect($stored)->not->toContain("\r")
            ->and(str_ends_with($stored, "\n"))->toBeTrue()
            ->and(str_ends_with($stored, "\n\n"))->toBeFalse();
    })->with(array_keys(GoldenCases::all()));

    it('round-trips the stored Schedule payload through fromJson', function (): void {
        $payload = goldenWirePayload(GoldenCases::WIRE_SCHEDULE);

        expect(Schedule::fromJson($payload)->toJson())->toBe($payload);
    });

    it('round-trips the stored StageState payload through fromJson', function (): void {
        $payload = goldenWirePayload(GoldenCases::WIRE_STAGE_STATE);

        expect(StageState::fromJson($payload)->toJson())->toBe($payload);
    });
});

/*
 * The stored baseline's own shape. A fixture that matches generated output
 * can still pin nothing: an empty section, a seed that changed nothing, a
 * "different" case that came out the same as the plain one. These tests
 * read only the stored files, so they hold whatever the library does.
 */
describe('Golden baseline', function (): void {
    it('stores the whole round-robin matrix in each file', function (string $file): void {
        $sections = goldenSections($file);

        $expectedTitles = [];
        foreach ([2, 3, 4, 5, 6, 7, 8, 14, 17, 20] as $size) {
            foreach ([1, 2, 3, 4] as $legs) {
                $expectedTitles[] = "n={$size} legs={$legs}";
            }
        }
        expect(array_keys($sections))->toBe($expectedTitles);

        foreach ($sections as $title => $lines) {
            sscanf($title, 'n=%d legs=%d', $size, $legs);
            assert(is_int($size) && is_int($legs));
            $rounds = $legs * ($size % 2 === 0 ? $size - 1 : $size);

            $roundLines = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'R')));
            expect($roundLines)->toHaveCount($rounds, $title);
            foreach ($roundLines as $index => $line) {
                [$label, $events] = explode(': ', $line, 2);
                expect($label)->toBe('R' . ($index + 1), $title)
                    ->and(explode(' ', $events))->toHaveCount(intdiv($size, 2), $title . ' ' . $label);
            }

            expect($lines[0])->toStartWith('meta: algorithm=round-robin participant_count=' . $size . ' legs=' . $legs . ' ')
                ->and(str_starts_with($lines[count($lines) - 1], 'byes: '))->toBe($size % 2 === 1, $title);
        }
    })->with([
        'round-robin/mirrored-unseeded.txt',
        'round-robin/mirrored-seed-1.txt',
        'round-robin/mirrored-seed-42.txt',
        'round-robin/mirrored-seed-1337.txt',
        'round-robin/repeated-unseeded.txt',
        'round-robin/repeated-seed-1.txt',
        'round-robin/repeated-seed-42.txt',
        'round-robin/repeated-seed-1337.txt',
    ]);

    // If the randomizer were not wired through, every seed file would
    // equal the unseeded one and the seed dimension would pin nothing
    it('stores a different round robin for every seed', function (string $strategy): void {
        $bySeed = [];
        foreach (['unseeded', 'seed-1', 'seed-42', 'seed-1337'] as $seed) {
            $sections = goldenSections("round-robin/{$strategy}-{$seed}.txt");
            // n=2 has a single pairing whatever the order, so compare a field with room to differ
            $bySeed[$seed] = implode("\n", $sections['n=8 legs=1']);
        }

        expect(array_unique($bySeed))->toHaveCount(4);
    })->with(['mirrored', 'repeated']);

    it('stores leg strategies that agree on one leg and differ on two', function (string $seed): void {
        $mirrored = goldenSections("round-robin/mirrored-{$seed}.txt");
        $repeated = goldenSections("round-robin/repeated-{$seed}.txt");

        expect($mirrored['n=8 legs=1'])->toBe($repeated['n=8 legs=1'])
            ->and($mirrored['n=8 legs=2'])->not->toBe($repeated['n=8 legs=2']);
    })->with(['unseeded', 'seed-1', 'seed-42', 'seed-1337']);

    it('stores a rotation-retry and two backtracking schedules', function (): void {
        $titles = array_keys(goldenSections('round-robin/constrained.txt'));

        expect($titles)->toHaveCount(3)
            ->and($titles[0])->toStartWith('rotation retry: ')
            ->and($titles[1])->toStartWith('backtracking: n=4 ')
            ->and($titles[2])->toStartWith('backtracking: n=5 ');
    });

    it('stores a different Swiss schedule for every seed', function (string $field): void {
        $sections = goldenSections('swiss.txt');

        $bySeed = [];
        foreach (['unseeded', 'Mt19937 seed 1', 'Mt19937 seed 42', 'Mt19937 seed 1337'] as $seed) {
            expect($sections)->toHaveKey("{$field} {$seed}");
            $bySeed[$seed] = implode("\n", $sections["{$field} {$seed}"]);
        }

        expect(array_unique($bySeed))->toHaveCount(4);
    })->with(['n=8 rounds=3', 'n=9 rounds=4']);

    it('stores first-round and full-bracket pairings at every size', function (string $file): void {
        $sections = goldenSections($file);

        foreach ([5, 8, 12] as $size) {
            expect($sections)->toHaveKey("n={$size}, first-named wins");
            $lines = $sections["n={$size}, first-named wins"];
            $last = count($lines) - 1;

            // Round 1, at least one later round, then the standings of all n entrants
            expect($lines[0])->toStartWith('R1 [')
                ->and(count($lines))->toBeGreaterThan(2)
                ->and($lines[$last])->toStartWith('standings: ')
                ->and(explode(' ', substr($lines[$last], strlen('standings: '))))->toHaveCount($size);
        }
    })->with(['single-elimination.txt', 'double-elimination.txt']);

    // Upsets are the only thing that makes re-seeding visible
    it('stores a re-seeded bracket that differs from the fixed one', function (): void {
        $sections = goldenSections('single-elimination.txt');

        expect($sections['n=8 reseedEachRound, last-named wins'])->not->toBe($sections['n=8, last-named wins'])
            ->and($sections['n=8 reseedEachRound, first-named wins'])->toBe($sections['n=8, first-named wins']);
    });

    it('stores a double-elimination grand final reset', function (): void {
        $sections = goldenSections('double-elimination.txt');

        expect(count($sections['n=8, last-named wins']))->toBe(count($sections['n=8, first-named wins']) + 1);
    });

    it('stores a mis-pinned repack that differs from the clean one', function (): void {
        $sections = goldenSections('repack/scenario.txt');

        expect(array_keys($sections))->toBe(['clean: movable events only', 'mis-pinned: evacuated events held as pins'])
            ->and($sections['clean: movable events only'])->not->toBe($sections['mis-pinned: evacuated events held as pins'])
            ->and($sections['clean: movable events only'][0])->not->toBe('assignments: 0');
    });

    it('stores a complete-graph repack for each field size', function (): void {
        $sections = goldenSections('repack/round-robin.txt');

        expect(array_keys($sections))->toBe([
            'n=12: 66 events, 3 sessions x 4 slots, capacityPerSlot=6',
            'n=16: 120 events, 4 sessions x 4 slots, capacityPerSlot=8',
            'n=24: 276 events, 6 sessions x 4 slots, capacityPerSlot=12',
        ]);

        // Every event is accounted for: placed or itemised as unplaced
        foreach ([66, 120, 276] as $index => $events) {
            $lines = array_values($sections)[$index];
            $placed = (int) substr($lines[0], strlen('assignments: '));
            $unplacedLine = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'unplaced: ')))[0];

            expect($placed)->toBeGreaterThan(0)
                ->and($placed + (int) substr($unplacedLine, strlen('unplaced: ')))->toBe($events);
        }
    });
});

describe('Golden harness', function (): void {
    it('names the first differing line of a mismatch', function (): void {
        $message = goldenMismatchMessage('swiss.txt', "a\nb\nc\n", "a\nX\nc\n");

        expect($message)->toContain('tests/Fixtures/golden/swiss.txt at line 2.')
            ->toContain('stored:    b')
            ->toContain('generated: X')
            ->toContain('changelog')
            ->toContain('composer golden-update');
    });

    it('says that an example fixture pins what the script prints', function (): void {
        $message = goldenMismatchMessage('examples/13-swiss-stage-engine.txt', "Round 1\n", "Round 2\n");

        expect($message)->toContain('tests/Fixtures/golden/examples/13-swiss-stage-engine.txt at line 1.')
            ->toContain('pins what an example script prints')
            ->toContain('composer golden-update');
    });

    it('reports the end of file when one side is a prefix of the other', function (): void {
        $shorterStored = goldenMismatchMessage('swiss.txt', 'a', "a\nb");
        $shorterGenerated = goldenMismatchMessage('swiss.txt', "a\nb", 'a');

        expect($shorterStored)->toContain('at line 2.')
            ->toContain('stored:    (end of file)')
            ->toContain('generated: b')
            ->and($shorterGenerated)->toContain('at line 2.')
            ->toContain('stored:    b')
            ->toContain('generated: (end of file)');
    });

    it('reports a missing final newline as a difference on the last line', function (): void {
        expect(goldenMismatchMessage('swiss.txt', "a\n", 'a'))->toContain('at line 2.');
    });

    it('treats any truthy CI variable as CI', function (string|false $ci, bool $expected): void {
        expect(goldenRunsOnCi($ci))->toBe($expected);
    })->with([
        'unset' => [false, false],
        'empty' => ['', false],
        'zero' => ['0', false],
        'false' => ['false', false],
        'False' => ['False', false],
        'true' => ['true', true],
        'one' => ['1', true],
    ]);

    it('renders events emitted out of round order as a repeated round line', function (): void {
        [$a, $b, $c, $d] = GoldenCases::field(4);
        $schedule = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$c, $d], new Round(2)),
            new Event([$a, $c], new Round(1)),
            new Event([$b, $d]),
        ], ['algorithm' => 'test', 'byes' => []]);

        expect(GoldenText::schedule($schedule))->toBe([
            'meta: algorithm=test byes=[]',
            'R1: 1-2',
            'R2: 3-4',
            'R1: 1-3',
            'R?: 2-4',
        ]);
    });

    it('renders metadata, event metadata and byes for a schedule', function (): void {
        [$a, $b, $c] = GoldenCases::field(3);
        $schedule = new Schedule(
            [new Event([$b, $a], new Round(1), ['tie_leg' => 2])],
            ['algorithm' => 'test', 'legs' => 1, 'byes' => [1 => '3']]
        );

        expect(GoldenText::schedule($schedule))->toBe([
            'meta: algorithm=test legs=1',
            'R1: 2-1{"tie_leg":2}',
            'byes: R1=3',
        ])->and($c->getId())->toBe('3');
    });

    it('renders a schedule without metadata or events as a bare meta line', function (): void {
        expect(GoldenText::schedule(new Schedule()))->toBe(['meta:']);
    });

    it('assembles a document from a comment header and titled sections', function (): void {
        expect(GoldenText::document(['Title.', '', 'Note.'], ['one' => ['a', 'b'], 'two' => []]))
            ->toBe("# Title.\n#\n# Note.\n\n== one ==\na\nb\n\n== two ==\n");
    });
});
