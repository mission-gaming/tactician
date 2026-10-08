<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use LogicException;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * How regular a pot draw schedule is, counted from its events.
 *
 * A pot draw is built with a shape the format does not ask for: whole pots
 * set against each other in a round, the pairings between two pots a
 * rotation of one member order. The scheduler then walks away from that
 * shape. These counts say how much of it is left, so that a test can state
 * how much may be. The roles are given after the walk; the count of blocks
 * in which one pot is first throughout says how they fell.
 *
 * Like `PotDrawAudit`, nothing here reads `Stage\PotDrawPlan` or the
 * schedule metadata: the pots are worked out from list position.
 */
final readonly class PotDrawRegularity
{
    /**
     * @param int $rounds Rounds in the schedule
     * @param int $wholePotRounds Rounds in which every pot meets one pot only (another, or itself)
     * @param int $roundsWithAnEventInsideAPot Rounds holding an event between two members of one pot
     * @param int $blocks Times two different pots meet in two or more events of one round
     * @param int $blocksWithOnePotFirst Those blocks in which one of the two pots holds the first role throughout
     * @param int $roundsListedByPotPair Rounds whose events are listed pot pair by pot pair
     * @param int $potPairs Pairs of different pots
     * @param int $potPairsInRotation Those pairs whose pairings pass `isRotation()`
     * @param int $potTriples Sets of three different pots
     * @param int $potTriplesInRotation Those sets that pass `tripleIsRotation()`
     * @param int $betweenPotComponents Groups of entrants that the events between different pots connect
     * @param int $roundPairs Pairs of rounds
     * @param int $roundPairsInOneCycle Those pairs whose events form one cycle through every entrant
     */
    private function __construct(
        public int $rounds,
        public int $wholePotRounds,
        public int $roundsWithAnEventInsideAPot,
        public int $blocks,
        public int $blocksWithOnePotFirst,
        public int $roundsListedByPotPair,
        public int $potPairs,
        public int $potPairsInRotation,
        public int $potTriples,
        public int $potTriplesInRotation,
        public int $betweenPotComponents,
        public int $roundPairs,
        public int $roundPairsInOneCycle
    ) {}

    /**
     * @param array<Participant> $participants The entrants in the order they were given to the scheduler
     * @param int $pots How many pots that list was cut into
     */
    public static function of(Schedule $schedule, array $participants, int $pots): self
    {
        $participants = array_values($participants);
        $count = count($participants);
        $potSize = intdiv($count, $pots);

        $position = [];
        foreach ($participants as $index => $participant) {
            $position[$participant->getId()] = $index;
        }

        // Round => list of [first, second] as list positions, in schedule order
        $byRound = [];
        // Entrant => entrant => true, for every two that meet
        $meets = array_fill(0, $count, []);
        foreach ($schedule->getEvents() as $event) {
            $pair = array_values($event->getParticipants());
            $round = $event->getRound()?->getNumber();
            if (count($pair) !== 2 || $round === null) {
                throw new LogicException('A pot draw event has two participants and a round.');
            }

            $first = $position[$pair[0]->getId()];
            $second = $position[$pair[1]->getId()];
            $byRound[$round][] = [$first, $second];
            $meets[$first][$second] = true;
            $meets[$second][$first] = true;
        }

        $wholePotRounds = 0;
        $roundsWithInside = 0;
        $blocks = 0;
        $blocksWithOnePotFirst = 0;
        $roundsListedByPotPair = 0;

        foreach ($byRound as $events) {
            $listed = [];
            $pairsOfPot = [];
            $firstRoleHolders = [];
            $inside = false;
            foreach ($events as [$first, $second]) {
                $a = intdiv($first, $potSize);
                $b = intdiv($second, $potSize);
                $potPair = min($a, $b) . '-' . max($a, $b);
                $listed[] = $potPair;
                $pairsOfPot[$a][$potPair] = true;
                $pairsOfPot[$b][$potPair] = true;
                if ($a === $b) {
                    $inside = true;
                } else {
                    $firstRoleHolders[$potPair][] = $a;
                }
            }

            $wholePotRounds += max(array_map(count(...), $pairsOfPot)) === 1 ? 1 : 0;
            $roundsWithInside += $inside ? 1 : 0;

            // Listed pot pair by pot pair: no pot pair comes back after another
            $runs = 1;
            for ($i = 1; $i < count($listed); ++$i) {
                $runs += $listed[$i] !== $listed[$i - 1] ? 1 : 0;
            }
            $roundsListedByPotPair += $runs === count(array_unique($listed)) ? 1 : 0;

            foreach ($firstRoleHolders as $holders) {
                if (count($holders) >= 2) {
                    ++$blocks;
                    $blocksWithOnePotFirst += count(array_unique($holders)) === 1 ? 1 : 0;
                }
            }
        }

        $potPairs = 0;
        $potPairsInRotation = 0;
        $potTriples = 0;
        $potTriplesInRotation = 0;
        for ($a = 0; $a < $pots; ++$a) {
            for ($b = $a + 1; $b < $pots; ++$b) {
                ++$potPairs;
                $potPairsInRotation += self::isRotation($meets, $a, $b, $potSize) ? 1 : 0;
                for ($c = $b + 1; $c < $pots; ++$c) {
                    ++$potTriples;
                    $potTriplesInRotation += self::tripleIsRotation($meets, $a, $b, $c, $potSize) ? 1 : 0;
                }
            }
        }

        $roundPairs = 0;
        $roundPairsInOneCycle = 0;
        $roundNumbers = array_keys($byRound);
        foreach ($roundNumbers as $i => $x) {
            foreach (array_slice($roundNumbers, $i + 1) as $y) {
                ++$roundPairs;
                $roundPairsInOneCycle += self::cycleLength($byRound[$x], $byRound[$y]) === $count ? 1 : 0;
            }
        }

        return new self(
            count($byRound),
            $wholePotRounds,
            $roundsWithInside,
            $blocks,
            $blocksWithOnePotFirst,
            $roundsListedByPotPair,
            $potPairs,
            $potPairsInRotation,
            $potTriples,
            $potTriplesInRotation,
            self::betweenPotComponents($meets, $potSize),
            $roundPairs,
            $roundPairsInOneCycle
        );
    }

    /**
     * Whether the pairings between pots `$a` and `$b` pass a test that
     * every rotation passes.
     *
     * When the pairings are rotations of one member order (position i of
     * the one pot meets positions i + d of the other, for a few d), moving
     * every member one position on maps the pairings onto themselves. So
     * every member of a pot has the same numbers of opponents in common
     * with the other members of its pot, taken as a sorted list. With two
     * opponents per pot the pairings are cycles, and the test is that all
     * of them have one length, which a rotation always gives.
     *
     * @param array<int, array<int, true>> $meets
     */
    private static function isRotation(array $meets, int $a, int $b, int $potSize): bool
    {
        foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
            $opponents = [];
            for ($member = $from * $potSize; $member < ($from + 1) * $potSize; ++$member) {
                $opponents[$member] = array_values(array_filter(
                    array_keys($meets[$member]),
                    static fn(int $other): bool => intdiv($other, $potSize) === $to
                ));
            }

            $profiles = [];
            foreach ($opponents as $member => $own) {
                $profile = [];
                foreach ($opponents as $other => $theirs) {
                    if ($other !== $member) {
                        $profile[] = count(array_intersect($own, $theirs));
                    }
                }
                sort($profile);
                $profiles[implode(',', $profile)] = true;
            }
            if (count($profiles) !== 1) {
                return false;
            }
        }

        // The cycles the pairings form, when every member has two opponents
        $lengths = [];
        $seen = [];
        for ($start = $a * $potSize; $start < ($a + 1) * $potSize; ++$start) {
            $neighbours = self::opponentsIn($meets, $start, $b, $potSize);
            if (count($neighbours) !== 2) {
                return true;
            }
            if (isset($seen[$start])) {
                continue;
            }

            $length = 0;
            $previous = -1;
            $current = $start;
            do {
                $seen[$current] = true;
                ++$length;
                $side = intdiv($current, $potSize) === $a ? $b : $a;
                $neighbours = self::opponentsIn($meets, $current, $side, $potSize);
                $next = $neighbours[0] === $previous ? $neighbours[1] : $neighbours[0];
                $previous = $current;
                $current = $next;
            } while ($current !== $start);
            $lengths[$length] = true;
        }

        return count($lengths) === 1;
    }

    /**
     * Whether every member of pot `$a` is in the same number of sets of
     * three entrants, one from each of the three pots, who all meet. When
     * the pairings between each two of the pots are rotations of one
     * member order per pot, moving every member one position on keeps
     * those sets, so the number is the same for every member.
     *
     * @param array<int, array<int, true>> $meets
     */
    private static function tripleIsRotation(array $meets, int $a, int $b, int $c, int $potSize): bool
    {
        $counts = [];
        for ($member = $a * $potSize; $member < ($a + 1) * $potSize; ++$member) {
            $sets = 0;
            foreach (self::opponentsIn($meets, $member, $b, $potSize) as $second) {
                foreach (self::opponentsIn($meets, $second, $c, $potSize) as $third) {
                    $sets += isset($meets[$third][$member]) ? 1 : 0;
                }
            }
            $counts[$sets] = true;
        }

        return count($counts) === 1;
    }

    /**
     * @param array<int, array<int, true>> $meets
     * @return list<int> The opponents of `$member` in pot `$pot`
     */
    private static function opponentsIn(array $meets, int $member, int $pot, int $potSize): array
    {
        return array_values(array_filter(
            array_keys($meets[$member]),
            static fn(int $other): bool => intdiv($other, $potSize) === $pot
        ));
    }

    /**
     * How many entrants the cycle through entrant 0 passes when the events
     * of two rounds are followed in turn.
     *
     * @param list<array{int, int}> $roundX
     * @param list<array{int, int}> $roundY
     */
    private static function cycleLength(array $roundX, array $roundY): int
    {
        $inX = [];
        foreach ($roundX as [$first, $second]) {
            $inX[$first] = $second;
            $inX[$second] = $first;
        }
        $inY = [];
        foreach ($roundY as [$first, $second]) {
            $inY[$first] = $second;
            $inY[$second] = $first;
        }

        $length = 0;
        $entrant = 0;
        do {
            $length += 2;
            $entrant = $inY[$inX[$entrant]];
        } while ($entrant !== 0);

        return $length;
    }

    /**
     * @param array<int, array<int, true>> $meets
     */
    private static function betweenPotComponents(array $meets, int $potSize): int
    {
        $component = [];
        $components = 0;
        foreach (array_keys($meets) as $start) {
            if (isset($component[$start])) {
                continue;
            }

            ++$components;
            $queue = [$start];
            $component[$start] = $components;
            while ($queue !== []) {
                $entrant = array_pop($queue);
                foreach (array_keys($meets[$entrant]) as $other) {
                    if (!isset($component[$other]) && intdiv($other, $potSize) !== intdiv($entrant, $potSize)) {
                        $component[$other] = $components;
                        $queue[] = $other;
                    }
                }
            }
        }

        return $components;
    }
}
