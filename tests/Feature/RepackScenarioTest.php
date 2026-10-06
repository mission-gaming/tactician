<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\LateStart;
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
 * The reference instance behind this feature: a synthetic division
 * shaped like a season two-thirds played, 14 participants with wildly
 * unequal outstanding loads and 12 doubled pairings. The fixture file is
 * the single source of truth — nothing in here retypes it.
 *
 * @return array{grid: array{sessions: array<string>, slots: array<string>, timezone: string}, participants: array<array{id: int, label: string, load: int}>, movableEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}}>, pinnedEvents: array<mixed>, evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}, session: int, slot: int}>}
 *
 * @throws JsonException
 */
function repackScenario(): array
{
    /** @var array{grid: array{sessions: array<string>, slots: array<string>, timezone: string}, participants: array<array{id: int, label: string, load: int}>, movableEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}}>, pinnedEvents: array<mixed>, evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, participantLabels: array{string, string}, session: int, slot: int}>} $data */
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

/**
 * The mis-pinned variant's pins: every evacuated defaulted event held at
 * the position the fixture records for it.
 *
 * @param array{evacuatedDefaultedEvents: array<array{eventId: int, participants: array{int, int}, session: int, slot: int}>} $data
 * @param array<int|string, Participant> $participants
 * @return array<PinnedEvent>
 *
 * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function repackScenarioPins(array $data, array $participants): array
{
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

    return $pinned;
}

describe('Repack reference scenario', function (): void {
    it('repacks the reference instance clean: everything placed, nobody double-booked, no gaps', function (): void {
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);
        $movableById = [];
        foreach ($movable as $event) {
            $movableById[$event->getId()] = $event;
        }

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
        foreach ($lateStarts as $lateStart) {
            assert($lateStart instanceof LateStart);
            expect($lateStart->getFirstSlot())->toBeLessThanOrEqual(2);
        }

        // Counted straight off the assignments rather than trusted from
        // the audit: each event placed once, nobody in two events at one
        // position, and no position over the grid's concurrency
        $eventIds = [];
        $occupied = [];
        $perPosition = [];
        foreach ($outcome->getAssignments() as $assignment) {
            $eventIds[] = $assignment->getEventId();
            $position = $assignment->getSession() . ':' . $assignment->getSlot();
            $perPosition[$position] = ($perPosition[$position] ?? 0) + 1;
            foreach ($movableById[$assignment->getEventId()]->getParticipants() as $participant) {
                $occupied[] = $participant->getId() . '@' . $position;
            }
        }
        sort($eventIds);
        // Numeric-string keys come back as ints
        $expectedIds = array_map(strval(...), array_keys($movableById));
        sort($expectedIds);
        expect($eventIds)->toBe($expectedIds);
        expect(count(array_unique($occupied)))->toBe(count($occupied));
        expect(array_filter($perPosition, static fn(int $events): bool => $events > 7))->toBe([]);

        // Kickoffs are UTC: August in Europe/London is BST, so the 20:15
        // wall-clock first slot emits as 19:15
        $first = $outcome->getAssignments()[0];
        expect($first->getKickoff()->getTimezone()->getName())->toBe('UTC');
        $slotZeroKickoffs = ['19:15', '19:40', '20:05', '20:30'];
        expect(in_array($first->getKickoff()->format('H:i'), $slotZeroKickoffs, true))->toBeTrue();
    });

    it('reports the mis-pinned variant as infeasible: CapacityExceeded naming Fallowmead, shortfall exactly 3', function (): void {
        // The wrong-by-design policy: treat the twenty evacuated
        // defaulted events as pins. Fallowmead is then blocked out of 4 of
        // its 16 positions and 15 events cannot fit 12 free positions.
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);
        $pinned = repackScenarioPins($data, $participants);

        $outcome = (new ScheduleRepacker())->repack(
            new RepackRequest($movable, $pinned, repackScenarioGrid($data))
        );

        $capacity = $outcome->getViolationsOfKind(ViolationKind::CapacityExceeded);
        expect($capacity)->toHaveCount(1);
        $violation = $capacity[0];
        assert($violation instanceof CapacityExceeded);
        expect($violation->getParticipant()?->getLabel())->toBe('Fallowmead');
        expect($violation->getDemand())->toBe(15);
        expect($violation->getCapacity())->toBe(12);
        expect($violation->getShortfall())->toBe(3);

        // Exactly the shortfall goes unplaced, every drop names Fallowmead,
        // and everything else is placed
        expect($outcome->getUnplaced())->toHaveCount(3);
        foreach ($outcome->getUnplaced() as $unplaced) {
            expect($unplaced->getReason())->toBe(UnplacedReason::ParticipantOverCapacity);
            expect($unplaced->getParticipant()?->getLabel())->toBe('Fallowmead');
        }
        expect($outcome->getAssignments())->toHaveCount(68);

        // Properness holds even against the pins
        expect($outcome->getViolationsOfKind(ViolationKind::ParticipantDoubleBooked))->toBe([]);

        // Pins are never re-emitted or moved
        $pinnedIds = array_fill_keys(array_map(
            static fn(PinnedEvent $pin): string => $pin->getId(),
            $pinned
        ), true);
        foreach ($outcome->getAssignments() as $assignment) {
            expect($pinnedIds)->not->toHaveKey($assignment->getEventId());
        }

        // Counted straight off the result rather than trusted from the
        // audit: no movable event shares a position with a pin (or with
        // another movable event) for either of its participants
        $movableById = [];
        foreach ($movable as $event) {
            $movableById[$event->getId()] = $event;
        }
        $occupied = [];
        foreach ($pinned as $pin) {
            foreach ($pin->getParticipants() as $participant) {
                $occupied[] = $participant->getId() . '@' . $pin->getSession() . ':' . $pin->getSlot();
            }
        }
        foreach ($outcome->getAssignments() as $assignment) {
            foreach ($movableById[$assignment->getEventId()]->getParticipants() as $participant) {
                $occupied[] = $participant->getId() . '@' . $assignment->getSession() . ':' . $assignment->getSlot();
            }
        }
        expect(count(array_unique($occupied)))->toBe(count($occupied));

        // Placed and unplaced partition the movable events, and each
        // dropped event really is one of the over-capacity participant's
        $accounted = array_map(
            static fn($assignment): string => $assignment->getEventId(),
            $outcome->getAssignments()
        );
        foreach ($outcome->getUnplaced() as $unplaced) {
            $accounted[] = $unplaced->getEventId();
            $labels = array_map(
                static fn(Participant $participant): string => $participant->getLabel(),
                $movableById[$unplaced->getEventId()]->getParticipants()
            );
            expect($labels)->toContain('Fallowmead');
        }
        sort($accounted);
        // Numeric-string keys come back as ints
        $expectedIds = array_map(strval(...), array_keys($movableById));
        sort($expectedIds);
        expect($accounted)->toBe($expectedIds);
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

    it('returns byte-identical output for the mis-pinned variant when movables and pins are shuffled', function (): void {
        $data = repackScenario();
        $participants = repackScenarioParticipants($data);
        $movable = repackScenarioMovables($data, $participants);
        $pinned = repackScenarioPins($data, $participants);
        $grid = repackScenarioGrid($data);

        $repacker = new ScheduleRepacker();
        $baseline = $repacker->repack(new RepackRequest($movable, $pinned, $grid))->toArray();

        $rng = new Randomizer(new Mt19937(42));
        $shuffled = $repacker->repack(
            new RepackRequest($rng->shuffleArray($movable), $rng->shuffleArray($pinned), $grid)
        )->toArray();

        expect(json_encode($shuffled))->toBe(json_encode($baseline));
    });
});

// The scenario tests above only read ids, pairings and pin positions, so
// the fixture's other tables (loads, repeated labels, the empty pinned
// set) and the arithmetic the design note quotes could drift unnoticed
// under a hand edit. These pin the file against itself.
describe('Repack reference fixture', function (): void {
    it('carries exactly the scenario tables and nothing else', function (): void {
        $data = repackScenario();

        expect(array_keys($data))->toBe(['grid', 'participants', 'movableEvents', 'pinnedEvents', 'evacuatedDefaultedEvents']);
        expect($data['participants'])->toHaveCount(14);
        expect($data['movableEvents'])->toHaveCount(71);
        expect($data['pinnedEvents'])->toBe([]);
        expect($data['evacuatedDefaultedEvents'])->toHaveCount(20);

        foreach ($data['movableEvents'] as $entry) {
            expect(array_keys($entry))->toBe(['eventId', 'participants', 'participantLabels']);
        }
        foreach ($data['evacuatedDefaultedEvents'] as $entry) {
            expect(array_keys($entry))->toBe(['eventId', 'participants', 'participantLabels', 'session', 'slot']);
        }
    });

    it('keeps ids unique and every repeated label in agreement', function (): void {
        $data = repackScenario();

        $participantIds = array_column($data['participants'], 'id');
        expect(count(array_unique($participantIds)))->toBe(14);

        $events = [...$data['movableEvents'], ...$data['evacuatedDefaultedEvents']];
        $eventIds = array_column($events, 'eventId');
        expect(count(array_unique($eventIds)))->toBe(91);

        $labels = array_column($data['participants'], 'label', 'id');
        foreach ($events as $entry) {
            [$a, $b] = $entry['participants'];
            expect($a)->not->toBe($b);
            foreach ($entry['participants'] as $index => $id) {
                $labels[$id] ??= $entry['participantLabels'][$index];
                expect($entry['participantLabels'][$index])->toBe($labels[$id]);
            }
        }

        // 14 division participants plus the 4 outsiders that appear only
        // in evacuated events, no label shared between two ids
        expect($labels)->toHaveCount(18);
        expect(count(array_unique($labels)))->toBe(18);
    });

    it('declares loads that match the movable events: 59 pairings, 12 of them doubled', function (): void {
        $data = repackScenario();

        $loads = array_fill_keys(array_column($data['participants'], 'id'), 0);
        $pairings = [];
        foreach ($data['movableEvents'] as $entry) {
            $pair = $entry['participants'];
            sort($pair);
            $key = implode('-', $pair);
            $pairings[$key] = ($pairings[$key] ?? 0) + 1;
            foreach ($pair as $id) {
                // Movable events stay inside the division
                expect($loads)->toHaveKey($id);
                ++$loads[$id];
            }
        }

        expect($loads)->toBe(array_column($data['participants'], 'load', 'id'));
        expect($pairings)->toHaveCount(59);
        expect(array_keys($pairings, 2, true))->toHaveCount(12);
        expect(array_keys($pairings, 1, true))->toHaveCount(47);
    });

    it('lays its sessions out weekly in summer time on evenly spaced slots', function (): void {
        $grid = repackScenario()['grid'];
        $timezone = new DateTimeZone($grid['timezone']);

        // The helper builds the grid with a fixed 25-minute interval and
        // the clean test expects BST kickoffs; both must hold for the file
        expect($grid['sessions'])->toHaveCount(4);
        expect($grid['slots'])->toHaveCount(4);

        $previous = null;
        foreach ($grid['sessions'] as $date) {
            $session = new DateTimeImmutable($date . ' ' . $grid['slots'][0], $timezone);
            expect($session->format('P'))->toBe('+01:00');
            if ($previous instanceof DateTimeImmutable) {
                expect($previous->diff($session)->days)->toBe(7);
            }
            $previous = $session;
        }

        $first = new DateTimeImmutable($grid['sessions'][0] . ' ' . $grid['slots'][0], $timezone);
        foreach ($grid['slots'] as $index => $slot) {
            $kickoff = new DateTimeImmutable($grid['sessions'][0] . ' ' . $slot, $timezone);
            expect($kickoff->getTimestamp() - $first->getTimestamp())->toBe($index * 25 * 60);
        }
    });

    it('supports the mis-pinned arithmetic: only Fallowmead is over capacity, by exactly 3', function (): void {
        $data = repackScenario();
        $positions = count($data['grid']['sessions']) * count($data['grid']['slots']);
        $labels = array_column($data['participants'], 'label', 'id');
        $loads = array_column($data['participants'], 'load', 'id');

        $blocked = [];
        $outsiders = [];
        foreach ($data['evacuatedDefaultedEvents'] as $entry) {
            expect($entry['session'])->toBeGreaterThanOrEqual(0)->toBeLessThan(count($data['grid']['sessions']));
            expect($entry['slot'])->toBeGreaterThanOrEqual(0)->toBeLessThan(count($data['grid']['slots']));
            foreach ($entry['participants'] as $id) {
                // A pin never double-books its own participants
                expect($blocked[$id][$entry['session']] ?? [])->not->toContain($entry['slot']);
                $blocked[$id][$entry['session']][] = $entry['slot'];
                if (!isset($labels[$id])) {
                    $outsiders[$id] = true;
                }
            }
        }
        expect($outsiders)->toHaveCount(4);

        $shortfalls = [];
        foreach ($loads as $id => $load) {
            $free = $positions - array_sum(array_map(count(...), $blocked[$id] ?? []));
            if ($load > $free) {
                $shortfalls[$labels[$id]] = $load - $free;
            }
        }
        expect($shortfalls)->toBe(['Fallowmead' => 3]);

        // The trap the planner must not walk into: one participant is
        // left a single free position in the first session, and it is
        // the first slot
        $cinderRow = array_search('Cinder Row', $labels, true);
        $taken = $blocked[$cinderRow][0] ?? [];
        sort($taken);
        expect($taken)->toBe([1, 2, 3]);
    });
});
