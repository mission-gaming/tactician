<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackObjective;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\ShortestGridFinder;
use MissionGaming\Tactician\Repack\ShortestGridResult;

/**
 * Every pairing of so many participants once, as movable events.
 *
 * @return list<MovableEvent>
 *
 * @throws InvalidConfigurationException
 */
function everyPairingOnce(int $participants): array
{
    $entrants = [];
    for ($i = 0; $i < $participants; ++$i) {
        $entrants[] = new Participant("p{$i}", "P{$i}");
    }
    $events = [];
    for ($a = 0; $a < $participants; ++$a) {
        for ($b = $a + 1; $b < $participants; ++$b) {
            $events[] = new MovableEvent("e{$a}-{$b}", $entrants[$a], $entrants[$b]);
        }
    }

    return $events;
}

describe('ShortestGridFinder', function (): void {
    it('finds the fewest sessions that place every event, and says when counting proves it', function (): void {
        // Six participants meet once each: five events each, so no grid of
        // fewer than five one-slot sessions can hold them, and five do
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(6),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: 3),
            maxSessions: 12
        );

        expect($result->isFound())->toBeTrue()
            ->and($result->getSessionCount())->toBe(5)
            ->and($result->getGrid()?->getSessionCount())->toBe(5)
            ->and($result->getOutcome()?->getUnplacedCount())->toBe(0)
            ->and(count($result->getOutcome()?->getAssignments() ?? []))->toBe(15)
            ->and($result->getRuledOut())->toBe([1, 2, 3, 4])
            ->and($result->getUnplacedBySessionCount())->toBe([5 => 0])
            ->and($result->isProvenShortest())->toBeTrue();
    });

    it('goes on to the next number when a repack leaves an event unplaced, and then does not call the result proven', function (): void {
        // Three participants meet once each. Each has two events, so
        // counting allows two sessions of one slot; but any two of the
        // three events share a participant, so each needs a session
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(3),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: null),
            maxSessions: 6
        );

        expect($result->getSessionCount())->toBe(3)
            ->and($result->getRuledOut())->toBe([1])
            ->and($result->getUnplacedBySessionCount())->toBe([2 => 1, 3 => 0])
            ->and($result->isProvenShortest())->toBeFalse();
    });

    it('says so when no number it may try places everything, and hands back the outcome on the largest grid', function (): void {
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(3),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: null),
            maxSessions: 2
        );

        expect($result->isFound())->toBeFalse()
            ->and($result->getSessionCount())->toBeNull()
            ->and($result->getGrid())->toBeNull()
            ->and($result->getOutcome()?->getUnplacedCount())->toBe(1)
            ->and($result->getUnplacedBySessionCount())->toBe([2 => 1])
            ->and($result->getRuledOut())->toBe([1])
            ->and($result->isProvenShortest())->toBeFalse();
    });

    it('has no outcome when every number was ruled out without a repack', function (): void {
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(4),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: 2),
            maxSessions: 2
        );

        expect($result->isFound())->toBeFalse()
            ->and($result->getOutcome())->toBeNull()
            ->and($result->getUnplacedBySessionCount())->toBe([])
            ->and($result->getRuledOut())->toBe([1, 2]);
    });

    it('rules out a grid by each count on its own: a pin off it, too few places, a participant with too many events', function (array $case): void {
        // Each case is made when the test runs: the events, the pins, the
        // capacity of a slot, the numbers ruled out and the number found
        [$movable, $pinned, $capacity, $ruledOut, $found] = $case;
        $result = (new ShortestGridFinder())->find(
            $movable,
            $pinned,
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: $capacity),
            maxSessions: 8
        );

        expect($result->getRuledOut())->toBe($ruledOut)
            ->and($result->getSessionCount())->toBe($found);
    })->with([
        'a pinned event in the third session' => function (): array {
            $a = new Participant('a', 'A');
            $b = new Participant('b', 'B');

            return [[new MovableEvent('m1', $a, $b)], [new PinnedEvent('x1', $a, $b, 2, 0)], 1, [1, 2], 3];
        },
        'four events of four pairs on slots of one event' => function (): array {
            $events = [];
            for ($i = 0; $i < 4; ++$i) {
                $events[] = new MovableEvent("m{$i}", new Participant("a{$i}", "A{$i}"), new Participant("b{$i}", "B{$i}"));
            }

            return [$events, [], 1, [1, 2, 3], 4];
        },
        'the pinned events count towards the places' => function (): array {
            $pinned = [new PinnedEvent('x1', new Participant('c', 'C'), new Participant('d', 'D'), 0, 0)];

            return [[new MovableEvent('m1', new Participant('a', 'A'), new Participant('b', 'B'))], $pinned, 1, [1], 2];
        },
        'one participant in three events, with room for any number in a slot' => function (): array {
            $a = new Participant('a', 'A');
            $events = [];
            for ($i = 0; $i < 3; ++$i) {
                $events[] = new MovableEvent("m{$i}", $a, new Participant("b{$i}", "B{$i}"));
            }

            return [$events, [], null, [1, 2], 3];
        },
    ]);

    it('starts at the smallest number it is given and asks for a grid once for each number, in ascending order', function (): void {
        $asked = [];
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(4),
            [],
            static function (int $sessions) use (&$asked): SessionGrid {
                $asked[] = $sessions;

                return SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: 2);
            },
            maxSessions: 9,
            minSessions: 2
        );

        expect($result->getSessionCount())->toBe(3)
            ->and($asked)->toBe([2, 3])
            ->and($result->getRuledOut())->toBe([2]);

        // A larger smallest number is where it starts, although fewer would do
        $late = (new ShortestGridFinder())->find(
            everyPairingOnce(4),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1, capacityPerSlot: 2),
            maxSessions: 9,
            minSessions: 5
        );
        expect($late->getSessionCount())->toBe(5)
            ->and($late->getRuledOut())->toBe([])
            ->and($late->isProvenShortest())->toBeTrue();
    });

    it('takes whatever grid the function makes of a number: slots and capacity are the caller\'s', function (): void {
        // Sessions of two slots: six participants need five positions, so
        // three sessions
        $result = (new ShortestGridFinder())->find(
            everyPairingOnce(6),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 2, capacityPerSlot: 3),
            maxSessions: 9
        );

        expect($result->getSessionCount())->toBe(3)
            ->and($result->getGrid()?->getPositionCount())->toBe(6)
            ->and($result->getOutcome()?->getUnplacedCount())->toBe(0);
    });

    it('repacks with the options it is given, and never throws for an outcome that is not clean', function (): void {
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');
        // m1 says it is in session 2, where it can stay once the grid has
        // three sessions; two suffice to place both events
        $movable = [new MovableEvent('m1', $a, $b, 2, 0), new MovableEvent('m2', $a, $c, 0, 0)];
        $gridOf = static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 1);
        $options = new RepackOptions(throwOnViolations: true, objectives: [RepackObjective::LeastChurn]);

        $result = (new ShortestGridFinder())->find($movable, [], $gridOf, 5, 1, $options);

        // One session cannot hold two events of a; two can. On two sessions
        // m1's position is off the grid, so it has moved
        expect($result->getSessionCount())->toBe(2)
            ->and($result->getOutcome()?->getMovedEventIds())->toBe(['m1'])
            ->and($result->getOutcome()?->getAssignmentFor('m2')?->getSession())->toBe(0);

        $fromThree = (new ShortestGridFinder())->find($movable, [], $gridOf, 5, 3, $options);
        expect($fromThree->getOutcome()?->getMovedEventIds())->toBe([]);
    });

    it('gives the same result for the same events, function and options', function (): void {
        $find = static fn(): ShortestGridResult => (new ShortestGridFinder())->find(
            everyPairingOnce(7),
            [],
            static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, 2, capacityPerSlot: 3),
            maxSessions: 10
        );
        $first = $find();
        $second = $find();

        expect($first->getSessionCount())->toBe($second->getSessionCount())
            ->and($first->getOutcome()?->toArray())->toBe($second->getOutcome()?->toArray())
            ->and($first->getUnplacedBySessionCount())->toBe($second->getUnplacedBySessionCount());
    });

    it('refuses a range that is not one, and a function that does not return the grid it was asked for', function (string $returns, int $max, int $min, InvalidConfigurationReason $reason): void {
        $gridOf = match ($returns) {
            'one session more' => static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions + 1),
            'a number' => static fn(int $sessions): int => $sessions,
            default => static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly(max(1, $sessions)),
        };

        try {
            // Through reflection, because one of the functions breaks the
            // declared type of the argument on purpose
            (new ReflectionMethod(ShortestGridFinder::class, 'find'))->invoke(new ShortestGridFinder(), everyPairingOnce(4), [], $gridOf, $max, $min);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception->getReason();
        }

        expect($thrown)->toBe($reason);
    })->with([
        'a smallest number of 0' => ['the grid asked for', 3, 0, InvalidConfigurationReason::ValueOutOfRange],
        'a largest number below the smallest' => ['the grid asked for', 2, 3, InvalidConfigurationReason::ValueOutOfRange],
        'a grid of another number of sessions' => ['one session more', 3, 1, InvalidConfigurationReason::IncompatibleOptions],
        'something that is no grid' => ['a number', 3, 1, InvalidConfigurationReason::WrongValueType],
    ]);
});

describe('ShortestGridResult', function (): void {
    it('holds what it is given', function (): void {
        $grid = SessionGrid::shapeOnly(4);
        $outcome = new RepackOutcome([], [], []);
        $result = new ShortestGridResult(4, $grid, $outcome, [3 => 2, 4 => 0], [1, 2]);

        expect($result->isFound())->toBeTrue()
            ->and($result->getSessionCount())->toBe(4)
            ->and($result->getGrid())->toBe($grid)
            ->and($result->getOutcome())->toBe($outcome)
            ->and($result->getUnplacedBySessionCount())->toBe([3 => 2, 4 => 0])
            ->and($result->getRuledOut())->toBe([1, 2])
            ->and($result->isProvenShortest())->toBeFalse();
    });

    it('is proven shortest only when it was found and no repack failed below it', function (): void {
        $grid = SessionGrid::shapeOnly(4);
        $outcome = new RepackOutcome([], [], []);

        expect((new ShortestGridResult(4, $grid, $outcome, [4 => 0], [1, 2, 3]))->isProvenShortest())->toBeTrue()
            ->and((new ShortestGridResult(4, $grid, $outcome, [3 => 1, 4 => 0], [1, 2]))->isProvenShortest())->toBeFalse()
            ->and((new ShortestGridResult(null, null, $outcome, [4 => 1], [1, 2, 3]))->isProvenShortest())->toBeFalse()
            ->and((new ShortestGridResult(null, null, null, [], [1, 2, 3]))->isFound())->toBeFalse();
    });
});
