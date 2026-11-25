<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackObjective;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;

/*
 * Below the default budget, the fourth and the fifth schedules of a
 * repack (the rounds laid on the positions with room, and the events
 * planned in a scrambled order) are made on a work limit of their own and
 * kept only where they place more events, so that an outcome is never
 * worse by its own ranking than it is without them (#244; the design
 * note, decision 77). The requests of
 * tests/Fixtures/repack/below-the-default-budget.json are two on which
 * they were; each holds the figures the repacker gave without the two
 * schedules.
 */

/**
 * @return array<string, array{array<string, mixed>}>
 *
 * @throws JsonException
 */
function belowTheDefaultBudgetCases(): array
{
    /** @var array{cases: list<array<string, mixed>>} $fixture */
    $fixture = json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/repack/below-the-default-budget.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $cases = [];
    foreach ($fixture['cases'] as $case) {
        /** @var string $name */
        $name = $case['request'];
        $cases[$name] = [$case];
    }

    return $cases;
}

/**
 * @param array<string, mixed> $case
 *
 * @throws InvalidConfigurationException
 * @throws RepackViolationsException
 */
function repackBelowTheDefaultBudget(array $case, ?int $stepBudget = null): RepackOutcome
{
    $participants = [];
    $participant = static function (string $id) use (&$participants): Participant {
        return $participants[$id] ??= new Participant($id);
    };
    /** @var list<array{string, string, string, int, int}> $pins */
    $pins = $case['pinned'];
    $pinned = [];
    foreach ($pins as [$id, $a, $b, $session, $slot]) {
        $pinned[] = new PinnedEvent($id, $participant($a), $participant($b), $session, $slot);
    }
    /** @var list<array{string, string, string, int|null, int|null}> $events */
    $events = $case['movable'];
    $movable = [];
    foreach ($events as [$id, $a, $b, $session, $slot]) {
        $movable[] = new MovableEvent($id, $participant($a), $participant($b), $session, $slot);
    }
    /** @var array<int|string, int> $overrides */
    $overrides = $case['overrides'];
    $slotCounts = [];
    foreach ($overrides as $session => $slots) {
        $slotCounts[(int) $session] = $slots;
    }
    /** @var int $sessions */
    $sessions = $case['sessions'];
    /** @var int $slots */
    $slots = $case['slots'];
    /** @var int $capacity */
    $capacity = $case['capacity'];
    /** @var list<string> $objectives */
    $objectives = $case['objectives'];
    /** @var int $budget */
    $budget = $stepBudget ?? $case['step_budget'];

    return new ScheduleRepacker()->repack(new RepackRequest(
        $movable,
        $pinned,
        SessionGrid::shapeOnly($sessions, $slots, $slotCounts, $capacity),
        new RepackOptions(
            stepBudget: $budget,
            objectives: array_map(RepackObjective::from(...), $objectives)
        )
    ));
}

/**
 * @return array<string, int>
 */
function belowTheDefaultBudgetFiguresOf(RepackOutcome $outcome): array
{
    return [
        'unplaced' => count($outcome->getUnplaced()),
        'gap_slots' => $outcome->getGapSlotCount(),
        'moved' => $outcome->getMovedCount(),
        'lone_participant_sessions' => $outcome->getLoneParticipantSessionCount(),
        'idle_sessions' => $outcome->getIdleSessionCount(),
    ];
}

describe('A repack below the default budget', function (): void {
    it('is no worse by its own ranking than without the fourth and fifth schedules', function (array $case): void {
        $outcome = repackBelowTheDefaultBudget($case);

        /** @var array<string, int> $expected */
        $expected = $case['expected'];
        $figures = array_intersect_key(belowTheDefaultBudgetFiguresOf($outcome), $expected);
        ksort($figures);
        ksort($expected);
        expect($figures)->toBe($expected);
    })->with(belowTheDefaultBudgetCases());

    it('places at least as many events at the default budget', function (array $case): void {
        // The fourth and fifth schedules spend from the two budgets there,
        // as they did, and neither request places fewer events than at
        // 2,000 steps
        $outcome = repackBelowTheDefaultBudget($case, 200_000);

        /** @var array{unplaced: int} $expected */
        $expected = $case['expected'];
        expect(count($outcome->getUnplaced()))->toBeLessThanOrEqual($expected['unplaced']);
    })->with(belowTheDefaultBudgetCases());
});
