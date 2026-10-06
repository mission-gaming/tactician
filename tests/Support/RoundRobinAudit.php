<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * What a complete round robin is, checked in the events of a schedule and
 * in its `byes` metadata.
 *
 * The rules are the ones the usage guide states (docs/USAGE.md,
 * "Terminology"), counted from first principles: nothing here reads
 * `Stage\RoundRobinPlan` or asks the scheduler's own validator.
 *
 * - A leg is one complete cycle of pairings: n - 1 rounds in a field of even
 *   size and n rounds in a field of odd size, so every leg holds every
 *   pairing exactly once.
 * - Round numbers are 1-based and continuous across legs: the rounds used
 *   are exactly 1 to legs * rounds per leg.
 * - Nobody is in two events of one round, and every round is full: half the
 *   field, rounded down, in events.
 * - A bye is never an event. In a field of odd size the `byes` metadata
 *   names, for every round, the one participant who is in no event of it,
 *   and every participant sits out exactly once per leg. A field of even
 *   size has no byes.
 */
final class RoundRobinAudit
{
    /**
     * Everything that is wrong with the schedule as a complete round robin
     * of the given field over the given number of legs. Empty when nothing is.
     *
     * @param array<Participant> $participants
     * @return list<string>
     */
    public static function faults(Schedule $schedule, array $participants, int $legs): array
    {
        $ids = array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
        $known = array_fill_keys($ids, true);
        $count = count($ids);
        $odd = $count % 2 === 1;
        $roundsPerLeg = $odd ? $count : $count - 1;
        $totalRounds = $roundsPerLeg * $legs;
        $pairings = intdiv($count * ($count - 1), 2);

        $faults = [];

        if (count($schedule) !== $pairings * $legs) {
            $faults[] = sprintf('%d events, expected %d', count($schedule), $pairings * $legs);
        }

        /** @var array<int, array<string, true>> $inRound */
        $inRound = [];
        /** @var array<int, array<string, int>> $meetingsInLeg */
        $meetingsInLeg = [];
        /** @var array<int, int> $eventsInRound */
        $eventsInRound = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $pair = array_values($event->getParticipants());
            $round = $event->getRound()?->getNumber();

            if (count($pair) !== 2 || $round === null) {
                $faults[] = sprintf('event %d has no round or not two participants', $index + 1);

                continue;
            }

            [$first, $second] = [$pair[0]->getId(), $pair[1]->getId()];
            if ($first === $second || !isset($known[$first], $known[$second])) {
                $faults[] = sprintf('event %d pairs %s with %s', $index + 1, $first, $second);

                continue;
            }
            if ($round < 1 || $round > $totalRounds) {
                $faults[] = sprintf('event %d is in round %d, outside 1 to %d', $index + 1, $round, $totalRounds);

                continue;
            }

            foreach ([$first, $second] as $id) {
                if (isset($inRound[$round][$id])) {
                    $faults[] = sprintf('%s is in two events of round %d', $id, $round);
                }
                $inRound[$round][$id] = true;
            }

            $leg = intdiv($round - 1, $roundsPerLeg) + 1;
            $key = strcmp($first, $second) < 0 ? "{$first} + {$second}" : "{$second} + {$first}";
            $meetingsInLeg[$leg][$key] = ($meetingsInLeg[$leg][$key] ?? 0) + 1;
            $eventsInRound[$round] = ($eventsInRound[$round] ?? 0) + 1;
        }

        $usedRounds = array_keys($eventsInRound);
        sort($usedRounds);
        if ($usedRounds !== range(1, $totalRounds)) {
            $faults[] = sprintf('the rounds used are not exactly 1 to %d', $totalRounds);
        }
        foreach ($eventsInRound as $round => $events) {
            if ($events !== intdiv($count, 2)) {
                $faults[] = sprintf('round %d has %d events, expected %d', $round, $events, intdiv($count, 2));
            }
        }

        for ($leg = 1; $leg <= $legs; ++$leg) {
            $meetings = $meetingsInLeg[$leg] ?? [];
            if (count($meetings) !== $pairings) {
                $faults[] = sprintf('leg %d holds %d different pairings, expected %d', $leg, count($meetings), $pairings);
            }
            foreach ($meetings as $key => $times) {
                if ($times !== 1) {
                    $faults[] = sprintf('%s meet %d times in leg %d', $key, $times, $leg);
                }
            }
        }

        return [...$faults, ...self::byeFaults($schedule->getMetadataValue('byes'), $ids, $inRound, $legs)];
    }

    /**
     * @param list<string> $ids
     * @param array<int, array<string, true>> $inRound Who is in an event of each round
     * @return list<string>
     */
    private static function byeFaults(mixed $byes, array $ids, array $inRound, int $legs): array
    {
        if (!is_array($byes)) {
            return ['the byes metadata is not an array'];
        }

        $count = count($ids);
        if ($count % 2 === 0) {
            return $byes === [] ? [] : [sprintf('%d bye(s) recorded in a field of even size', count($byes))];
        }

        $faults = [];
        $totalRounds = $count * $legs;

        if (array_keys($byes) !== range(1, $totalRounds)) {
            $faults[] = sprintf('byes are not recorded for exactly the rounds 1 to %d, in order', $totalRounds);
        }

        /** @var array<int, array<string, int>> $byesInLeg */
        $byesInLeg = [];
        foreach ($byes as $round => $id) {
            if (!is_int($round) || !is_string($id)) {
                $faults[] = 'a bye is not recorded as round number => participant ID';

                continue;
            }

            $sittingOut = array_values(array_diff($ids, array_keys($inRound[$round] ?? [])));
            if ($sittingOut !== [$id]) {
                $faults[] = sprintf(
                    'round %d records the bye of %s, and the participants in no event of it are [%s]',
                    $round,
                    $id,
                    implode(', ', $sittingOut)
                );
            }

            $leg = intdiv($round - 1, $count) + 1;
            $byesInLeg[$leg][$id] = ($byesInLeg[$leg][$id] ?? 0) + 1;
        }

        for ($leg = 1; $leg <= $legs; ++$leg) {
            foreach ($ids as $id) {
                $times = $byesInLeg[$leg][$id] ?? 0;
                if ($times !== 1) {
                    $faults[] = sprintf('%s has %d byes in leg %d', $id, $times, $leg);
                }
            }
        }

        return $faults;
    }
}
