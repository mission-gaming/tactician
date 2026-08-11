<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\Repack\ViolationKind;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The production instance behind this feature: one division of a season
 * two-thirds played (stage 162, snapshot 2026-08-08), 14 participants
 * with wildly unequal outstanding loads. The fixture file is copied
 * verbatim from the source brief and is the single source of truth —
 * nothing in here retypes it.
 *
 * @return array{grid: array{sessions: array<string>, slots: array<string>, timezone: string}, participants: array<array{id: int, label: string, load: int}>, movableEvents: array<array{eventId: int, participants: array{int, int}}>, evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}, session: int, slot: int}>}
 *
 * @throws JsonException
 */
function repackScenario(): array
{
    /** @var array{grid: array{sessions: array<string>, slots: array<string>, timezone: string}, participants: array<array{id: int, label: string, load: int}>, movableEvents: array<array{eventId: int, participants: array{int, int}}>, evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}, session: int, slot: int}>} $data */
    $data = json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/repack-scenario.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return $data;
}

/**
 * @param array{participants: array<array{id: int, label: string, load: int}>, evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}, session: int, slot: int}>} $data
 *
 * @return array<int|string, Participant>
 */
function repackScenarioParticipants(array $data): array
{
    $participants = [];
    foreach ($data['participants'] as $entry) {
        $participants[$entry['id']] = new Participant((string) $entry['id'], $entry['label']);
    }
    foreach ($data['evacuatedDefaultedEvents'] as $entry) {
        foreach ($entry['participants'] as $index => $id) {
            $participants[$id] ??= new Participant((string) $id, $entry['participantLabels'][$index]);
        }
    }

    return $participants;
}

/**
 * @param array{movableEvents: array<array{eventId: int, participants: array{int, int}}>} $data
 * @param array<int|string, Participant> $participants
 * @return array<MovableEvent>
 *
 * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function repackScenarioMovables(array $data, array $participants): array
{
    $movable = [];
    foreach ($data['movableEvents'] as $entry) {
        [$a, $b] = $entry['participants'];
        $movable[] = new MovableEvent((string) $entry['eventId'], $participants[$a], $participants[$b]);
    }

    return $movable;
}

/**
 * @param array{grid: array{sessions: array<string>, slots: array<string>, timezone: string}} $data
 *
 * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function repackScenarioGrid(array $data): SessionGrid
{
    $sessions = [];
    foreach ($data['grid']['sessions'] as $date) {
        $sessions[] = $date . ' ' . $data['grid']['slots'][0];
    }

    // 14 participants means at most 7 concurrent events
    return SessionGrid::fromArray([
        'sessions' => $sessions,
        'timezone' => $data['grid']['timezone'],
        'slot_interval' => 'PT25M',
        'slots_per_session' => count($data['grid']['slots']),
        'capacity_per_slot' => 7,
    ]);
}

describe('Repack production scenario', function (): void {
    it('repacks the production instance clean: everything placed, nobody double-booked, no gaps', function (): void {
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);

        $outcome = (new ScheduleRepacker())->repack(
            new RepackRequest($movable, [], repackScenarioGrid($data))
        );

        expect($outcome->getAssignments())->toHaveCount(71);
        expect($outcome->getUnplaced())->toBe([]);
        expect($outcome->getViolationsOfKind(ViolationKind::ParticipantDoubleBooked))->toBe([]);
        expect($outcome->getViolationsOfKind(ViolationKind::ContiguityBroken))->toBe([]);
        expect($outcome->getViolationsOfKind(ViolationKind::EventUnplaced))->toBe([]);
        expect($outcome->getViolationsOfKind(ViolationKind::CapacityExceeded))->toBe([]);

        // Late starts are the soft objective: allowed, but only shallow
        // ones and not many (some are forced whenever an odd number of
        // participants share a session)
        $lateStarts = $outcome->getViolationsOfKind(ViolationKind::LateStart);
        expect(count($lateStarts))->toBeLessThanOrEqual(10);

        // Kickoffs are UTC: August in Europe/London is BST, so the 20:15
        // wall-clock first slot emits as 19:15
        $first = $outcome->getAssignments()[0];
        expect($first->getKickoff()->getTimezone()->getName())->toBe('UTC');
        $slotZeroKickoffs = ['19:15', '19:40', '20:05', '20:30'];
        expect(in_array($first->getKickoff()->format('H:i'), $slotZeroKickoffs, true))->toBeTrue();
    });

    it('reports the mis-pinned variant as infeasible: CapacityExceeded naming Foregone, shortfall exactly 3', function (): void {
        // The wrong-by-design policy: treat the twenty evacuated
        // defaulted events as pins. Foregone is then blocked out of 4 of
        // its 16 positions and 15 events cannot fit 12 free positions.
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);

        $pinned = [];
        foreach ($data['evacuatedDefaultedEvents'] as $entry) {
            [$a, $b] = $entry['participants'];
            $pinned[] = new PinnedEvent(
                (string) $entry['eventId'],
                $participants[$a],
                $participants[$b],
                $entry['session'],
                $entry['slot']
            );
        }

        $outcome = (new ScheduleRepacker())->repack(
            new RepackRequest($movable, $pinned, repackScenarioGrid($data))
        );

        $capacity = $outcome->getViolationsOfKind(ViolationKind::CapacityExceeded);
        expect($capacity)->toHaveCount(1);
        $violation = $capacity[0];
        assert($violation instanceof CapacityExceeded);
        expect($violation->getParticipant()?->getLabel())->toBe('Foregone');
        expect($violation->getDemand())->toBe(15);
        expect($violation->getCapacity())->toBe(12);
        expect($violation->getShortfall())->toBe(3);

        // Exactly the shortfall goes unplaced, every drop names Foregone,
        // and everything else is placed
        expect($outcome->getUnplaced())->toHaveCount(3);
        foreach ($outcome->getUnplaced() as $unplaced) {
            expect($unplaced->getReason())->toBe(UnplacedReason::ParticipantOverCapacity);
            expect($unplaced->getParticipant()?->getLabel())->toBe('Foregone');
        }
        expect($outcome->getAssignments())->toHaveCount(68);

        // Properness holds even against the pins
        expect($outcome->getViolationsOfKind(ViolationKind::ParticipantDoubleBooked))->toBe([]);

        // Pins are never re-emitted or moved
        $pinnedIds = array_fill_keys(array_map(
            static fn (PinnedEvent $pin): string => $pin->getId(),
            $pinned
        ), true);
        foreach ($outcome->getAssignments() as $assignment) {
            expect($pinnedIds)->not->toHaveKey($assignment->getEventId());
        }
    });

    it('returns byte-identical output when the fixture lists are shuffled', function (): void {
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);
        $grid = repackScenarioGrid($data);

        $repacker = new ScheduleRepacker();
        $baseline = $repacker->repack(new RepackRequest($movable, [], $grid))->toArray();

        $rng = new Randomizer(new Mt19937(42));
        $shuffled = $repacker->repack(
            new RepackRequest($rng->shuffleArray($movable), [], $grid)
        )->toArray();

        expect(json_encode($shuffled))->toBe(json_encode($baseline));
    });
});
