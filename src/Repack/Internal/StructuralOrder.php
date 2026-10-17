<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * The order the repacker takes the participants in: by what the request
 * says about each of them, and by its id only where that says nothing.
 *
 * Every tie-break of the repacker falls to this order in the end, so an
 * order by id alone made the quality of a repack depend on how the caller
 * happened to name things. Here two participants are told apart, in this
 * order of precedence, by:
 *
 * 1. how many movable events each has (more first);
 * 2. the positions each is pinned at;
 * 3. the same two facts about their opponents, and about the opponents of
 *    those, as far as that goes on telling participants apart (colour
 *    refinement: a class of participants is split by the classes of its
 *    members' opponents, counted with the number of events against each,
 *    and of their pinned opponents by position, until no class splits);
 * 4. where the caller gives the current positions of the movable events
 *    (the repacker does when the fewest moved events are asked for),
 *    those positions, but only between participants the steps above
 *    leave alike (see below);
 * 5. their ids, in the order the caller of of() numbered them.
 *
 * When classes of more than one participant are left after step 3, the
 * first of them is settled, and the refinement goes on from there:
 *
 * - If exchanging any two of its participants leaves the request as it
 *   was (each has the same events against every third participant and
 *   the same pins), the class is put in the order of the current
 *   positions of their events (each position with the class of the
 *   opponent there), and in id order where those are the same. Any
 *   order of such a class gives the same refinement up to the exchange,
 *   so this order changes nothing but which participant has which place
 *   in a schedule that is otherwise the same.
 * - Otherwise each of its participants in turn is tried as the one to
 *   put first, and the one is chosen whose choice gives the refined
 *   classes the smallest description (the sizes of the classes and what
 *   the members of each have against each other class: something that
 *   is the same for two requests that differ only in the ids). Between
 *   participants whose descriptions are equal the current positions
 *   decide, as above, and then the id.
 *
 * So the order is the same for two requests that differ only in the ids
 * whenever the id only ever decides between participants that a symmetry
 * of the request maps onto each other. Where current positions are given,
 * they decide before the id at the two places above, which are the only
 * places they are read: a request with them is put in the order the
 * request without them has, up to a symmetry of that request, and the
 * id decides only where the current positions do not either (two
 * participants with the same positions against opponents of one class). Two participants with equal
 * descriptions are that when the description is of classes of one
 * participant each, because it is then the whole request written in
 * that order. It is not guaranteed when the classes are still larger
 * (two descriptions can be equal for participants that are not alike),
 * nor past the work limit below.
 *
 * The work is bounded by a count of its own (WORK_LIMIT: one for every
 * participant looked at and one for each of its opponents), not by a step
 * budget, so the order never depends on the
 * caller's budget; past the limit no class is split further and no
 * further participant is tried, and the classes still left are put in id
 * order as they stand (in the order of their current positions first,
 * where those are given). The order of such a request does depend on its
 * ids, and so can its outcome. That is why this limit, alone among the
 * repacker's work limits, is not made smaller by a step budget below
 * the default (StepBudget::workLimit()): a share of it would send
 * requests back to their ids that the whole limit orders, and the ids
 * would decide more at a small budget than at a large one. It costs at
 * most about a tenth of a second whatever the request and the budget.
 *
 * @internal
 */
final class StructuralOrder
{
    /**
     * How much the refinement may look at before the classes still left
     * are put in id order, counted as one for a participant and one for
     * each of its opponents and pins on every pass: a limit of its own,
     * which no budget lifts. It is sized for the hardest request of 40 participants there
     * is for this ordering: a complete round robin with a single round
     * played, in which everybody is alike and no two can simply be
     * exchanged, so that every participant left has to be tried at every
     * step. That one needs about 1,300,000 (under a tenth of a second on
     * the machine it was measured on); with 36 participants it needs
     * 870,000, with 44 it needs 1,900,000 and is past the limit, and its
     * participants are then put in id order from where the limit is
     * reached. Rounds played beyond the first tell the participants apart
     * and need far less.
     */
    public const int WORK_LIMIT = 1_500_000;

    /** @var array<int, array<int, int>> Participant => opponent => movable events between them */
    private array $opponents;

    /** @var array<int, list<array{int, int}>> Participant => [position number, opponent] of each pin */
    private array $pinned = [];

    /** @var array<int, list<array{int, int}>> Participant => [position number, opponent] of each current position */
    private array $placed = [];

    /** One more than the most events any two participants have between them */
    private int $base = 2;

    private int $work = 0;

    /**
     * @param array<int, array{int, int}> $events
     * @param array<int, array{int, int, int, int}> $pins
     * @param array<int, array{int, int, int, int}> $current
     */
    private function __construct(
        private readonly int $count,
        array $events,
        private readonly array $pins,
        private readonly int $workLimit,
        private readonly array $current
    ) {
        $this->opponents = array_fill(0, $count, []);
        foreach ($events as [$a, $b]) {
            $this->opponents[$a][$b] = ($this->opponents[$a][$b] ?? 0) + 1;
            $this->opponents[$b][$a] = ($this->opponents[$b][$a] ?? 0) + 1;
            $this->base = max($this->base, $this->opponents[$a][$b] + 1);
        }
    }

    /**
     * @param int $count How many participants there are, numbered from 0 in id order
     * @param array<int, array{int, int}> $events The movable events, as pairs of those numbers
     * @param array<int, array{int, int, int, int}> $pins The pinned events: [participant, participant, session, slot]
     * @param int $workLimit Work done before the rest falls to id order; the repacker always
     *                       passes WORK_LIMIT, and the unit tests pass a smaller one to see the fallback
     * @param array<int, array{int, int, int, int}> $current The current positions of the movable
     *                                                       events that state one:
     *                                                       [participant, participant, session, slot].
     *                                                       Read only between participants nothing
     *                                                       else tells apart; none leaves the order
     *                                                       what it is without them
     *
     * @return list<int> The participants' numbers, in structural order
     */
    public static function of(
        int $count,
        array $events,
        array $pins,
        int $workLimit = self::WORK_LIMIT,
        array $current = []
    ): array {
        if ($count === 0) {
            return [];
        }

        return (new self($count, $events, $pins, $workLimit, $current))->order();
    }

    /**
     * @return list<int>
     */
    private function order(): array
    {
        // The first split is by events (more first) and pinned positions.
        // The positions are numbered in grid order for the later steps
        $degree = array_fill(0, $this->count, 0);
        $maxDegree = 0;
        foreach ($this->opponents as $pid => $byOpponent) {
            $degree[$pid] = array_sum($byOpponent);
            $maxDegree = max($maxDegree, $degree[$pid]);
        }
        $positionNumbers = [];
        foreach ($this->pins as [, , $session, $slot]) {
            $positionNumbers[sprintf('%010d.%010d', $session, $slot)] = 0;
        }
        ksort($positionNumbers, SORT_STRING);
        $positionNumbers = array_flip(array_keys($positionNumbers));
        $this->pinned = array_fill(0, $this->count, []);
        foreach ($this->pins as [$a, $b, $session, $slot]) {
            $number = $positionNumbers[sprintf('%010d.%010d', $session, $slot)];
            $this->pinned[$a][] = [$number, $b];
            $this->pinned[$b][] = [$number, $a];
        }

        // The current positions, numbered in grid order too, apart from
        // the pins: they are read only where the classes leave a choice
        $this->placed = array_fill(0, $this->count, []);
        $currentNumbers = [];
        foreach ($this->current as [, , $session, $slot]) {
            $currentNumbers[sprintf('%010d.%010d', $session, $slot)] = 0;
        }
        ksort($currentNumbers, SORT_STRING);
        $currentNumbers = array_flip(array_keys($currentNumbers));
        foreach ($this->current as [$a, $b, $session, $slot]) {
            $number = $currentNumbers[sprintf('%010d.%010d', $session, $slot)];
            $this->placed[$a][] = [$number, $b];
            $this->placed[$b][] = [$number, $a];
        }

        $keys = [];
        for ($pid = 0; $pid < $this->count; ++$pid) {
            $at = array_column($this->pinned[$pid], 0);
            sort($at);
            $keys[$pid] = sprintf('%010d|', $maxDegree - $degree[$pid]) . implode(',', $at);
        }
        // Cut short by the work limit or not, these classes are in an
        // order the ids had no part in; the loop below settles the rest
        [$classes] = $this->refine(self::split([range(0, $this->count - 1)], $keys));

        while (count($classes) < $this->count) {
            // Settle the first class that still holds more than one
            $at = 0;
            while (count($classes[$at]) < 2) {
                ++$at;
            }
            $class = $classes[$at];

            if ($this->interchangeable($class)) {
                array_splice(
                    $classes,
                    $at,
                    1,
                    array_map(static fn(int $pid): array => [$pid], $this->byCurrentPositions($class, $classes))
                );
                continue;
            }

            $best = null;
            $bestDescription = '';
            $bestPlaced = '';
            foreach ($class as $first) {
                if ($this->work > $this->workLimit) {
                    break;
                }
                $rest = array_values(array_filter($class, static fn(int $pid): bool => $pid !== $first));
                $tried = $classes;
                array_splice($tried, $at, 1, [[$first], $rest]);
                [$refined, $description, $finished] = $this->refine($tried);
                if (!$finished) {
                    // The limit was reached inside this trial: what it
                    // describes is not as far as the others were taken
                    break;
                }
                $byDescription = $best === null ? -1 : strcmp($description, $bestDescription);
                $placed = $this->current === [] ? '' : $this->placedKey($first, $classes);
                if ($byDescription < 0 || ($byDescription === 0 && strcmp($placed, $bestPlaced) < 0)) {
                    $best = $refined;
                    $bestDescription = $description;
                    $bestPlaced = $placed;
                }
            }
            if ($best === null) {
                // Out of work: what is left is put in id order as it stands
                $settled = [];
                foreach ($classes as $left) {
                    foreach ($this->byCurrentPositions($left, $classes) as $pid) {
                        $settled[] = [$pid];
                    }
                }
                $classes = $settled;
                break;
            }
            $classes = $best;
        }

        return array_map(static fn(array $class): int => $class[0], $classes);
    }

    /**
     * Split the classes by what their members have against each class,
     * until no class splits or the work limit is passed. A long chain of
     * participants takes one pass for every two of them, so without the
     * limit here the work of a single call grew with the square of the
     * request.
     *
     * @param list<list<int>> $classes
     *
     * @return array{list<list<int>>, string, bool} The classes; their description (for each class in
     *                                              order its size and what each of its members has
     *                                              against every class, which names no participant);
     *                                              and whether no class splits any more, without which
     *                                              the description is empty
     */
    private function refine(array $classes): array
    {
        $positions = count($this->pins) + 1;
        while (true) {
            if ($this->work > $this->workLimit) {
                return [$classes, '', false];
            }

            $classOf = [];
            foreach ($classes as $at => $class) {
                foreach ($class as $pid) {
                    $classOf[$pid] = $at;
                }
            }

            $keys = [];
            for ($pid = 0; $pid < $this->count; ++$pid) {
                $seen = [];
                foreach ($this->opponents[$pid] as $other => $events) {
                    $seen[] = $classOf[$other] * $this->base + $events;
                }
                foreach ($this->pinned[$pid] as [$number, $other]) {
                    $seen[] = -1 - ($classOf[$other] * $positions + $number);
                }
                $this->work += count($seen) + 1;
                sort($seen);
                $keys[$pid] = implode(',', $seen);
            }

            $refined = self::split($classes, $keys);
            if (count($refined) === count($classes)) {
                $description = [];
                foreach ($classes as $class) {
                    $description[] = count($class) . ':' . $keys[$class[0]];
                }

                return [$classes, implode('|', $description), true];
            }
            $classes = $refined;
        }
    }

    /**
     * Split every class by its members' keys, keeping the order of the
     * classes and putting the parts of one class in key order. Members
     * stay in ascending number (id) order within a part.
     *
     * @param list<list<int>> $classes
     * @param array<int, string> $keys
     *
     * @return list<list<int>>
     */
    private static function split(array $classes, array $keys): array
    {
        $result = [];
        foreach ($classes as $class) {
            if (count($class) === 1) {
                $result[] = $class;
                continue;
            }
            $parts = [];
            foreach ($class as $pid) {
                $parts['k' . $keys[$pid]][] = $pid;
            }
            ksort($parts, SORT_STRING);
            foreach ($parts as $part) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * The members of a class in the order of their current positions,
     * and in id order where those are the same; in id order when no
     * current positions were given.
     *
     * @param list<int> $class In id order
     * @param list<list<int>> $classes
     *
     * @return list<int>
     */
    private function byCurrentPositions(array $class, array $classes): array
    {
        if ($this->current === [] || count($class) < 2) {
            return $class;
        }

        $keyed = [];
        foreach ($class as $pid) {
            $keyed[] = [$this->placedKey($pid, $classes), $pid];
        }
        usort($keyed, static fn(array $x, array $y): int => strcmp($x[0], $y[0]) !== 0 ? strcmp($x[0], $y[0]) : $x[1] <=> $y[1]);

        return array_column($keyed, 1);
    }

    /**
     * What a participant's current positions say about it, naming no
     * participant: each position's number in grid order with the class
     * of the opponent there, sorted. Two participants that a symmetry of
     * the request with its current positions exchanges have the same key.
     *
     * @param list<list<int>> $classes
     */
    private function placedKey(int $pid, array $classes): string
    {
        $classOf = [];
        foreach ($classes as $at => $members) {
            foreach ($members as $member) {
                $classOf[$member] = $at;
            }
        }
        $seen = [];
        foreach ($this->placed[$pid] as [$number, $other]) {
            $seen[] = sprintf('%010d.%010d', $number, $classOf[$other]);
        }
        sort($seen);

        return implode(',', $seen);
    }

    /**
     * Whether exchanging any two participants of the class leaves the
     * request as it was: each has the same number of events against every
     * participant other than the two, and the same pins against the same
     * opponents (a pin between the two stays what it is).
     *
     * It is enough to compare each with the first: when exchanging the
     * first with a second and with a third are both symmetries, so is
     * exchanging the second with the third.
     *
     * @param list<int> $class
     */
    private function interchangeable(array $class): bool
    {
        $first = $class[0];
        foreach (array_slice($class, 1) as $other) {
            $mine = $this->opponents[$first];
            $theirs = $this->opponents[$other];
            unset($mine[$other], $theirs[$first]);
            ksort($mine);
            ksort($theirs);
            if ($mine !== $theirs) {
                return false;
            }

            if (self::pinsApartFrom($this->pinned[$first], $other) !== self::pinsApartFrom($this->pinned[$other], $first)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A participant's pins, less those against one opponent, in an order
     * that does not depend on how they were given.
     *
     * @param list<array{int, int}> $pins [position number, opponent]
     *
     * @return list<array{int, int}>
     */
    private static function pinsApartFrom(array $pins, int $opponent): array
    {
        $kept = [];
        foreach ($pins as $pin) {
            if ($pin[1] !== $opponent) {
                $kept[] = $pin;
            }
        }
        sort($kept);

        return $kept;
    }
}
