<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\StageState;

// Applications hold rows that 0.2.x wrote. Those rows have no format version
// anywhere, and they must keep loading: an array without the key is version
// 1, the form 0.2.x wrote (ADR 0004).
//
// The two files in tests/Fixtures/stored-by-0.2/ are the wire fixtures as
// they were at the v0.2.0 baseline, byte for byte. They are not golden
// files: nothing regenerates them, because what an old release wrote does
// not change. A release that can no longer read them fails here.

/**
 * A payload stored by 0.2.x: one line of JSON.
 */
function storedByZeroTwo(string $file): string
{
    return rtrim((string) file_get_contents(dirname(__DIR__) . '/Fixtures/stored-by-0.2/' . $file), "\n");
}

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
