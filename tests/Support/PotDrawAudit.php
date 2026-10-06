<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * What a pot draw schedule contains, counted from its events.
 *
 * The tests of the pot draw assert on these counts and not on what the
 * library says about its own output: nothing here reads `Stage\PotDrawPlan`
 * or the schedule metadata. The pots are worked out again from list
 * position (the first block of the participant list is pot 1), which is the
 * rule the format states.
 */
final readonly class PotDrawAudit
{
    /**
     * @param int $events Events in the schedule
     * @param array<int, int> $eventsByRound Round number => events in it
     * @param array<int, array<string, int>> $appearancesByRound Round number => participant ID => events in that round
     * @param array<string, array<int, int>> $opponentsByPot Participant ID => 1-based pot => opponents met from it
     * @param array<string, array<int, int>> $firstRoleByPot Participant ID => 1-based pot => events against it in the first role
     * @param array<string, array<int, int>> $secondRoleByPot Participant ID => 1-based pot => events against it in the second role
     * @param array<string, int> $meetings "a|b" with the IDs sorted => events between the two
     * @param list<string> $malformed Events that cannot be counted at all, described
     */
    private function __construct(
        public int $events,
        public array $eventsByRound,
        public array $appearancesByRound,
        public array $opponentsByPot,
        public array $firstRoleByPot,
        public array $secondRoleByPot,
        public array $meetings,
        public array $malformed
    ) {}

    /**
     * @param array<Participant> $participants The entrants in the order they were given to the scheduler
     * @param int $pots How many pots that list was cut into
     */
    public static function of(Schedule $schedule, array $participants, int $pots): self
    {
        $participants = array_values($participants);
        $potSize = intdiv(count($participants), $pots);

        $potOf = [];
        foreach ($participants as $position => $participant) {
            $potOf[$participant->getId()] = intdiv($position, $potSize) + 1;
        }

        $eventsByRound = [];
        $appearancesByRound = [];
        $opponentsByPot = [];
        $firstRoleByPot = [];
        $secondRoleByPot = [];
        $meetings = [];
        $malformed = [];
        $events = 0;

        foreach ($schedule->getEvents() as $index => $event) {
            ++$events;
            $ids = array_map(
                static fn(Participant $participant): string => $participant->getId(),
                array_values($event->getParticipants())
            );
            $round = $event->getRound()?->getNumber();

            if (count($ids) !== 2 || $round === null || !isset($potOf[$ids[0]], $potOf[$ids[1]]) || $ids[0] === $ids[1]) {
                $malformed[] = sprintf('event %d: [%s] in round %s', $index + 1, implode(', ', $ids), $round ?? 'none');
                continue;
            }

            [$first, $second] = $ids;
            $eventsByRound[$round] = ($eventsByRound[$round] ?? 0) + 1;
            foreach ($ids as $id) {
                $appearancesByRound[$round][$id] = ($appearancesByRound[$round][$id] ?? 0) + 1;
            }

            $opponentsByPot[$first][$potOf[$second]] = ($opponentsByPot[$first][$potOf[$second]] ?? 0) + 1;
            $opponentsByPot[$second][$potOf[$first]] = ($opponentsByPot[$second][$potOf[$first]] ?? 0) + 1;
            $firstRoleByPot[$first][$potOf[$second]] = ($firstRoleByPot[$first][$potOf[$second]] ?? 0) + 1;
            $secondRoleByPot[$second][$potOf[$first]] = ($secondRoleByPot[$second][$potOf[$first]] ?? 0) + 1;

            sort($ids);
            $pair = implode('|', $ids);
            $meetings[$pair] = ($meetings[$pair] ?? 0) + 1;
        }

        ksort($eventsByRound);

        return new self(
            $events,
            $eventsByRound,
            $appearancesByRound,
            $opponentsByPot,
            $firstRoleByPot,
            $secondRoleByPot,
            $meetings,
            $malformed
        );
    }

    /**
     * Every way the counted schedule breaks the rules of a pot draw with
     * the given numbers; empty when it breaks none.
     *
     * The rules, each checked by counting:
     *
     * - the schedule has pots × opponents per pot rounds, numbered from 1,
     *   and entrants × rounds / 2 events;
     * - every entrant is in every round exactly once;
     * - every entrant has exactly `$opponentsPerPot` opponents from every
     *   pot, its own included (so pots × opponents per pot events);
     * - no two entrants meet twice;
     * - the two role counts of an entrant differ by at most one, and are
     *   equal when its number of events is even;
     * - with an even `$opponentsPerPot`, an entrant is in each role exactly
     *   half the time against every pot.
     *
     * @param array<Participant> $participants The entrants in the order they were given to the scheduler
     * @return list<string>
     */
    public function violations(array $participants, int $pots, int $opponentsPerPot): array
    {
        $violations = $this->malformed;
        $ids = array_map(
            static fn(Participant $participant): string => $participant->getId(),
            array_values($participants)
        );
        $rounds = $pots * $opponentsPerPot;

        if (array_keys($this->eventsByRound) !== range(1, $rounds)) {
            $violations[] = sprintf(
                'rounds are [%s], expected 1 to %d',
                implode(', ', array_keys($this->eventsByRound)),
                $rounds
            );
        }

        $expectedEvents = intdiv(count($ids) * $rounds, 2);
        if ($this->events !== $expectedEvents) {
            $violations[] = sprintf('%d events, expected %d', $this->events, $expectedEvents);
        }

        foreach (array_keys($this->eventsByRound) as $round) {
            foreach ($ids as $id) {
                $appearances = $this->appearancesByRound[$round][$id] ?? 0;
                if ($appearances !== 1) {
                    $violations[] = sprintf('%s is in round %d %d time(s)', $id, $round, $appearances);
                }
            }
        }

        foreach ($this->meetings as $pair => $count) {
            if ($count !== 1) {
                $violations[] = sprintf('%s meet %d times', $pair, $count);
            }
        }

        foreach ($ids as $id) {
            $first = 0;
            $second = 0;

            for ($pot = 1; $pot <= $pots; ++$pot) {
                $opponents = $this->opponentsByPot[$id][$pot] ?? 0;
                if ($opponents !== $opponentsPerPot) {
                    $violations[] = sprintf('%s has %d opponent(s) from pot %d, expected %d', $id, $opponents, $pot, $opponentsPerPot);
                }

                $firstAgainstPot = $this->firstRoleByPot[$id][$pot] ?? 0;
                $secondAgainstPot = $this->secondRoleByPot[$id][$pot] ?? 0;
                $first += $firstAgainstPot;
                $second += $secondAgainstPot;

                if ($opponentsPerPot % 2 === 0 && ($firstAgainstPot !== intdiv($opponentsPerPot, 2) || $secondAgainstPot !== intdiv($opponentsPerPot, 2))) {
                    $violations[] = sprintf('%s is first %d and second %d time(s) against pot %d', $id, $firstAgainstPot, $secondAgainstPot, $pot);
                }
            }

            if ($first + $second !== $rounds) {
                $violations[] = sprintf('%s has %d events, expected %d', $id, $first + $second, $rounds);
            }

            if (abs($first - $second) > $rounds % 2) {
                $violations[] = sprintf('%s is first %d and second %d time(s) overall', $id, $first, $second);
            }
        }

        return $violations;
    }
}
