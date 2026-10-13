<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\PlainDataForms;

// Applications hold rows that 0.2.x wrote. Those rows have no format version
// anywhere, and they must keep loading: an array without the key is version
// 1, the form 0.2.x wrote (ADR 0005).
//
// The two files in tests/Fixtures/stored-by-0.2/ are the wire fixtures as
// they were at the v0.2.0 baseline, byte for byte. They are not golden
// files: nothing regenerates them, because what an old release wrote does
// not change. A release that can no longer read them fails here.
//
// forms-written-by-0.2.3.jsonl is wider: one line for each of 124 values,
// of every form that is read, as the v0.2.3 tag wrote it with toArray() and
// json_encode(), or with toJson() where the type has one. The schedules are
// those of the three schedulers, the stage states those of the three engines
// in mid-stage and complete, with byes, withdrawals, two-legged ties, a
// result replaced, two results of one event and an engine stamp. A line
// holds the name of the case, the class that reads it, the participants a
// reader needs as its registry, and the stored JSON as a string, so that
// its bytes are kept. The tags v0.2.0, v0.2.1 and v0.2.2 wrote the same
// bytes for every one of these cases they could write (they lack the pot
// draw, the stamp, the replaced result and the shape-only grid), bar one:
// round-robin options had no `role_assignment` before 0.2.2.
//
// Four of the lines are no longer read, each for a change 0.3.0 made on
// purpose and its changelog states. They are listed below with the reason
// they are refused with, so that a fifth cannot join them unnoticed.

/**
 * A payload stored by 0.2.x: one line of JSON.
 */
function storedByZeroTwo(string $file): string
{
    return rtrim((string) file_get_contents(dirname(__DIR__) . '/Fixtures/stored-by-0.2/' . $file), "\n");
}

/**
 * The cases of forms-written-by-0.2.3.jsonl, keyed by name.
 *
 * @return array<string, array{class: string, registry: array<string, Participant>, stored: string}>
 *
 * @throws JsonException When a line of the file is not JSON
 * @throws LogicException When a line does not have the shape described above
 */
function formsWrittenByZeroTwoThree(): array
{
    $cases = [];
    $lines = file(dirname(__DIR__) . '/Fixtures/stored-by-0.2/forms-written-by-0.2.3.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines === false ? [] : $lines as $line) {
        $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($case) || !is_string($case['case'] ?? null) || !is_string($case['class'] ?? null)
            || !is_array($case['registry'] ?? null) || !is_string($case['stored'] ?? null)) {
            throw new LogicException('A line of the fixture is not a case.');
        }

        $registry = [];
        foreach ($case['registry'] as $row) {
            if (!is_array($row)) {
                throw new LogicException('A registry row of the fixture is not a participant.');
            }
            /** @var array<string, mixed> $row */
            $registry[] = Participant::fromArray($row);
        }

        $cases[$case['case']] = [
            'class' => $case['class'],
            'registry' => PlainDataForms::registryOf($registry),
            'stored' => $case['stored'],
        ];
    }

    return $cases;
}

/**
 * Read a stored case with the reader of its class.
 *
 * @param array{class: string, registry: array<string, Participant>, stored: string} $case
 *
 * @throws JsonException When the stored string is not JSON
 * @throws LogicException When no form is listed for the class
 * @throws InvalidConfigurationException When the reader refuses the configuration or the history
 * @throws InvalidInputException When the reader refuses the data
 */
function readStoredCase(array $case): object
{
    $data = json_decode($case['stored'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new LogicException('The stored JSON is not an array.');
    }

    foreach (PlainDataForms::all() as $form) {
        if ($form['class'] === $case['class']) {
            return $form['read']($data, $case['registry']);
        }
    }

    throw new LogicException("No form is listed for {$case['class']}.");
}

/**
 * The cases 0.3.0 no longer reads, with the reason each is refused with.
 *
 * @return array<string, InvalidConfigurationReason>
 */
function storedCasesNoLongerRead(): array
{
    return [
        // The shuffled strategy needs a seed in plain data (changelog: "a
        // shuffled leg strategy needs a seed"); 0.2.x wrote the identifier alone
        'options rr shuffled unseeded' => InvalidConfigurationReason::IncompatibleOptions,
        'options rr shuffled randomizer' => InvalidConfigurationReason::IncompatibleOptions,
        'options rr fromArray every key' => InvalidConfigurationReason::IncompatibleOptions,
        // Two slots a year apart in rounds a year apart: the second slot of a
        // round is the first of the next (changelog: the slots of a round
        // come before the next round)
        'timeline slot interval P1Y' => InvalidConfigurationReason::OverlappingTime,
    ];
}

describe('every form as 0.2.3 wrote it', function (): void {
    it('covers every form that is read', function (): void {
        $classes = array_values(array_unique(array_column(formsWrittenByZeroTwoThree(), 'class')));
        $listed = array_values(array_unique(array_map(static fn(array $form): string => $form['class'], PlainDataForms::all())));
        sort($classes);
        sort($listed);

        expect($classes)->toBe($listed)
            ->and(formsWrittenByZeroTwoThree())->toHaveCount(124);
    });

    it('has no format version anywhere', function (): void {
        foreach (formsWrittenByZeroTwoThree() as $case) {
            expect($case['stored'])->not->toContain('format_version');
        }
    });

    it('loads, and is written back as the same data with the version in front', function (string $name): void {
        $case = formsWrittenByZeroTwoThree()[$name];

        $read = readStoredCase($case);

        $written = match (true) {
            method_exists($read, 'toJson') => $read->toJson(),
            method_exists($read, 'toArray') => json_encode($read->toArray(), JSON_THROW_ON_ERROR),
            default => $this->fail($read::class . ' cannot be written.'),
        };

        expect($written)->toBe('{"format_version":1,' . substr($case['stored'], 1));
    })->with(fn(): array => array_values(array_diff(array_keys(formsWrittenByZeroTwoThree()), array_keys(storedCasesNoLongerRead()))));

    it('is refused in the four cases the changelog of 0.3.0 states, and in no other', function (): void {
        $refused = [];
        foreach (formsWrittenByZeroTwoThree() as $name => $case) {
            try {
                readStoredCase($case);
            } catch (InvalidConfigurationException $exception) {
                $refused[$name] = $exception->getReason();
            }
        }

        expect($refused)->toBe(storedCasesNoLongerRead());
    });
});

// The format version and the fingerprint scheme are two versions. 0.3.0
// changed the scheme (`tactician:v2:`) and left the stage state form at
// version 1, so a state 0.2.3 stamped is read as it always was, stamp
// included, and it is the engine that refuses the stamp until the
// application has decided that the stage goes on (ADR 0005, "The
// fingerprints are not format versions").
describe('a stage state that 0.2.3 stamped', function (): void {
    it('loads with its stamp, which the engine of this release refuses until it is stamped again', function (
        string $name,
        FingerprintedEngine&StageEngineInterface $engine
    ): void {
        $state = readStoredCase(formsWrittenByZeroTwoThree()[$name]);
        assert($state instanceof StageState);

        $stamp = $state->getEngineFingerprint();
        expect($stamp)->toStartWith('tactician:v1:')
            ->and(str_replace('tactician:v1:', 'tactician:v2:', (string) $stamp))->toBe($engine->getFingerprint())
            ->and($state->toArray()['format_version'])->toBe(1);

        try {
            $engine->isComplete($state);
            $this->fail('The stamp of 0.2.3 was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch)
                ->and($exception->getMessage())->toContain('the stamp was written by an earlier release of the library');
        }

        $stampedAgain = $state->withEngineFingerprint($engine->getFingerprint());
        expect($engine->isComplete($stampedAgain))->toBeBool()
            ->and($stampedAgain->toArray()['format_version'])->toBe(1);
    })->with([
        'Swiss' => ['state swiss stamped', new SwissPairingEngine()],
        'single elimination over two legs' => [
            'state single elim stamped two-legged',
            new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
        ],
    ]);

    it('is every stored case with a stamp of the library', function (): void {
        $stamped = [];
        foreach (formsWrittenByZeroTwoThree() as $name => $case) {
            if (str_contains($case['stored'], 'tactician:v')) {
                $stamped[] = $name;
            }
        }

        expect($stamped)->toBe(['state swiss stamped', 'state single elim stamped two-legged']);
    });
});

describe('data stored by 0.2.x', function (): void {
    it('has no format version', function (string $file): void {
        expect(storedByZeroTwo($file))->not->toContain('format_version')
            ->and(storedByZeroTwo($file))->toStartWith('{"participants":');
    })->with(['schedule.json', 'stage-state.json']);

    it('loads as a schedule and is written back as the same data, with the version in front', function (): void {
        $stored = storedByZeroTwo('schedule.json');

        $schedule = Schedule::fromJson($stored);

        expect($schedule)->toHaveCount(3)
            ->and($schedule->getMetadataValue('algorithm'))->toBe('round-robin')
            ->and($schedule->toJson())->toBe('{"format_version":1,' . substr($stored, 1))
            ->and(Schedule::fromJson($schedule->toJson()))->toEqual($schedule);
    });

    it('loads as a stage state and is written back as the same data, with the version in front', function (): void {
        $stored = storedByZeroTwo('stage-state.json');

        $state = StageState::fromJson($stored);

        expect($state->getParticipants())->toHaveCount(5)
            ->and($state->getRoundsPlayed())->toHaveCount(1)
            ->and($state->getResults())->toHaveCount(1)
            ->and($state->getByeIds())->toBe(['1', '2', '3'])
            ->and($state->getEngineFingerprint())->toBeNull()
            ->and($state->toJson())->toBe('{"format_version":1,' . substr($stored, 1))
            ->and(StageState::fromJson($state->toJson()))->toEqual($state);
    });
});
