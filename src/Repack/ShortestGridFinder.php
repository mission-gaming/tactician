<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;

/**
 * Finds the shortest grid that places everything: the fewest sessions
 * for which a repack of the events leaves none unplaced.
 *
 * The caller says what a grid of a given number of sessions is (which
 * dates, how many slots each, what capacity) with a function from that
 * number to a SessionGrid; the library knows nothing of calendars. The
 * finder repacks the events on the grid of the smallest number allowed,
 * then of the next, and stops at the first that places every event.
 *
 * It goes through the numbers one by one, in ascending order, and never
 * skips ahead. A search that halves the range would be wrong here:
 * whether a repack places everything does not have to rise with the
 * number of sessions. The caller's function may give a larger number a
 * grid that is no superset of the smaller one, and the repacker is a
 * bounded search, which can find a placement on one grid and miss one on
 * a larger. The number returned is therefore the first that worked, and
 * every smaller number that was searched is accounted for: either
 * counting shows that it cannot hold the events (no repack is run for
 * it), or a repack was run and left events unplaced.
 * {@see ShortestGridResult::isProvenShortest()} tells the two apart.
 *
 * Bounded: at most one repack for each number from the smallest to the
 * largest allowed, each within the step budget of the options. When no
 * number in that range places everything the result says so
 * ({@see ShortestGridResult::isFound()}) and carries the outcome on the
 * largest grid that was repacked. Deterministic: the same events,
 * function and options give the same result.
 *
 * "Places everything" asks for no unplaced event and nothing else: the
 * outcome on the grid found may have gaps and late starts, which it
 * reports. The events are pairwise, as everything a repack takes is.
 *
 * @api
 */
final readonly class ShortestGridFinder
{
    public function __construct(private ScheduleRepacker $repacker = new ScheduleRepacker()) {}

    /**
     * The fewest sessions, from $minSessions to $maxSessions, on which a
     * repack places every movable event around the pinned ones.
     *
     * A number of sessions is ruled out without a repack when counting
     * shows that not every event fits its grid: a pinned event is at a
     * position the grid does not have, the grid has fewer places
     * (positions times capacity) than the request has events, or a
     * participant has more events than the grid has positions. Every
     * other number is repacked.
     *
     * The options are those of every repack; throwOnViolations is not
     * applied, because the search reads outcomes and an outcome with an
     * unplaced event is how it learns to go on. Current positions and
     * objectives work as in any repack; a current position that a
     * smaller grid does not have counts as moved there.
     *
     * @param array<MovableEvent> $movableEvents The events to place
     * @param array<PinnedEvent> $pinnedEvents The events that must not move
     * @param callable(int): SessionGrid $gridOf The grid of a number of sessions; it is called once
     *                                           for each number tried, with the number, and must
     *                                           return a grid of exactly that many sessions
     * @param int $maxSessions The largest number of sessions to try
     * @param int $minSessions The smallest number of sessions to try
     *
     * @throws InvalidConfigurationException When the range is not 1 <= $minSessions <= $maxSessions
     *                                       (reason `ValueOutOfRange`), the function returns
     *                                       something other than a SessionGrid (`WrongValueType`)
     *                                       or a grid of another number of sessions
     *                                       (`IncompatibleOptions`), or the events are input no
     *                                       request accepts on a grid that is repacked (see
     *                                       RepackRequest)
     */
    public function find(
        array $movableEvents,
        array $pinnedEvents,
        callable $gridOf,
        int $maxSessions,
        int $minSessions = 1,
        RepackOptions $options = new RepackOptions()
    ): ShortestGridResult {
        if ($minSessions < 1 || $maxSessions < $minSessions) {
            throw new InvalidConfigurationException(
                'The numbers of sessions to search must be 1 or more, the smallest no larger than the largest',
                ['min_sessions' => $minSessions, 'max_sessions' => $maxSessions],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        $options = new RepackOptions(
            $options->consolidationWeight,
            $options->earlyFillWeight,
            $options->stepBudget,
            false,
            $options->objectives
        );

        /** @var array<int, int> $unplaced */
        $unplaced = [];
        $ruledOut = [];
        $lastOutcome = null;
        for ($sessions = $minSessions; $sessions <= $maxSessions; ++$sessions) {
            $grid = $gridOf($sessions);
            if (!$grid instanceof SessionGrid) {
                throw new InvalidConfigurationException(
                    'The grid function must return a SessionGrid',
                    ['sessions' => $sessions, 'given' => get_debug_type($grid)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            if ($grid->getSessionCount() !== $sessions) {
                throw new InvalidConfigurationException(
                    'The grid function must return a grid of the number of sessions it was asked for',
                    ['sessions' => $sessions, 'returned' => $grid->getSessionCount()],
                    reason: InvalidConfigurationReason::IncompatibleOptions
                );
            }

            if (self::cannotHold($grid, $movableEvents, $pinnedEvents)) {
                $ruledOut[] = $sessions;
                continue;
            }

            try {
                $outcome = $this->repacker->repack(new RepackRequest($movableEvents, $pinnedEvents, $grid, $options));
            } catch (RepackViolationsException $exception) {
                // Not reached: the options above never ask for it
                $outcome = $exception->getOutcome();
            }
            $lastOutcome = $outcome;
            $unplaced[$sessions] = $outcome->getUnplacedCount();
            if ($outcome->getUnplacedCount() === 0) {
                return new ShortestGridResult($sessions, $grid, $outcome, $unplaced, $ruledOut);
            }
        }

        return new ShortestGridResult(null, null, $lastOutcome, $unplaced, $ruledOut);
    }

    /**
     * Whether counting alone shows that a grid cannot hold every event.
     *
     * Entries that are not events are left to the request, which reports
     * them; here they are not counted.
     *
     * @param array<mixed> $movableEvents
     * @param array<mixed> $pinnedEvents
     */
    private static function cannotHold(SessionGrid $grid, array $movableEvents, array $pinnedEvents): bool
    {
        $events = 0;
        /** @var array<string, int> $eventsOf Participant id => its events */
        $eventsOf = [];
        foreach ($pinnedEvents as $pin) {
            if (!$pin instanceof PinnedEvent) {
                continue;
            }
            if (!$grid->hasPosition($pin->getSession(), $pin->getSlot())) {
                return true;
            }
            ++$events;
            foreach ($pin->getParticipants() as $participant) {
                $eventsOf[$participant->getId()] = ($eventsOf[$participant->getId()] ?? 0) + 1;
            }
        }
        foreach ($movableEvents as $movable) {
            if (!$movable instanceof MovableEvent) {
                continue;
            }
            ++$events;
            foreach ($movable->getParticipants() as $participant) {
                $eventsOf[$participant->getId()] = ($eventsOf[$participant->getId()] ?? 0) + 1;
            }
        }

        $positions = $grid->getPositionCount();
        $capacity = $grid->getCapacityLimit();
        if ($capacity !== null && $events > $positions * $capacity) {
            return true;
        }

        return $eventsOf !== [] && max($eventsOf) > $positions;
    }
}
