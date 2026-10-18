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
 *   the same pins), the class is put in id order, or, where current
 *   positions are given, in the order a refinement that reads them as
 *   well gives (each position with the class of the opponent there),
 *   putting each participant still alike first in turn and keeping the
 *   choice with the smallest description, and in id order only where
 *   that leaves participants alike. Any order of such a class gives the
 *   same refinement up to the exchange, so this order changes nothing
 *   but which participant has which place in a schedule that is
 *   otherwise the same.
 * - Otherwise each of its participants in turn is tried as the one to
 *   put first, and the one is chosen whose choice gives the refined
 *   classes the smallest description (the sizes of the classes and what
 *   the members of each have against each other class: something that
 *   is the same for two requests that differ only in the ids). Between
 *   participants whose descriptions are equal, where current positions
 *   are given, the one is chosen whose refined classes, refined further
 *   with the current positions read as well, have the smaller
 *   description; then the id.
 *
 * So the order is the same for two requests that differ only in the ids
 * whenever the id only ever decides between participants that a symmetry
 * of the request maps onto each other. Where current positions are given,
 * they decide before the id at the two places above, which are the only
 * places they are read, and the classes the order is made from are the
 * ones without them: a request with them is put in the order the request
 * without them has, up to a symmetry of that request. The id decides only
 * between participants that the refinement with the current positions
 * leaves alike, in a round robin everybody being at every position until
 * one of them is put first. Two participants with equal
 * descriptions are alike when the description is of classes of one
 * participant each, because it is then the whole request written in
 * that order. It is not guaranteed when the classes are still larger
 * (two descriptions can be equal for participants that are not alike),
 * nor past the work limit below. The refinements that read the current
 * positions count their work apart, against the same limit, so that
 * reading them never cuts the refinement without them short; past it
 * they decide no more, and a choice they made before stays. They need
 * about twice the work of the refinement without them, so the hardest
 * request (below) is past their limit from 36 participants, and from
 * there its current positions decide only part of the order.
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
 * most about a tenth of a second whatever the request and the budget,
 * and as much again where current positions are given.
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

    /** What the refinements that read the current positions have looked at, against the same limit */
    private int $placedWork = 0;

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
                    array_map(static fn(int $pid): array => [$pid], $this->byPositionsInClass($classes, $at))
                );
                continue;
            }

            $best = null;
            $bestDescription = '';
            // The description of the best trial refined with the current
            // positions, made only once another trial is level with it
            $bestPlaced = null;
            // Whether the current positions still decide between trials
            // with equal descriptions: not once their refinement is past
            // the limit
            $byPlaced = $this->current !== [] && $this->placedWork <= $this->workLimit;
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
                if ($byDescription < 0) {
                    $best = $refined;
                    $bestDescription = $description;
                    $bestPlaced = null;
                } elseif ($byDescription === 0 && $byPlaced) {
                    // A refinement cut short by its limit describes nothing,
                    // so from there on the positions decide no more and the
                    // trial already chosen stays
                    if ($bestPlaced === null) {
                        [, $bestPlaced, $byPlaced] = $this->refineWithPositions($best);
                    }
                    [, $placed, $finished] = $byPlaced ? $this->refineWithPositions($refined) : [[], '', false];
                    $byPlaced = $finished;
                    if ($byPlaced && strcmp($placed, $bestPlaced) < 0) {
                        $best = $refined;
                        $bestPlaced = $placed;
                    }
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
     * The members of the class at $at in an order the current positions
     * decide, and the ids only where those do not either: the classes are
     * refined as refine() does with the current positions read as well
     * (each with its position and the class of the opponent there), and
     * where members are still alike, each in turn is put first and the
     * one is chosen whose refinement has the smallest description, as
     * often as members are left alike. In id order when no current
     * positions were given.
     *
     * The class is one whose members can be exchanged, so any order of it
     * gives the same schedule up to that exchange: the order only decides
     * which member takes which place, and the classes the rest of the
     * order is made from are untouched. Keys against the class as a whole
     * would say nothing where every member is at the same positions
     * against members of the class (in a round robin everybody plays in
     * every round); once one member is put first the others differ by
     * where they meet it.
     *
     * @param list<list<int>> $classes
     *
     * @return list<int>
     */
    private function byPositionsInClass(array $classes, int $at): array
    {
        $class = $classes[$at];
        if ($this->current === []) {
            return $class;
        }

        $members = array_flip($class);
        $partition = $classes;
        while (true) {
            [$partition, , $finished] = $this->refineWithPositions($partition);
            if (!$finished) {
                break;
            }
            $alike = null;
            foreach ($partition as $index => $part) {
                if (count($part) > 1 && isset($members[$part[0]])) {
                    $alike = $index;
                    break;
                }
            }
            if ($alike === null) {
                break;
            }
            $best = $this->bestTrialWithPositions($partition, $alike);
            if ($best === null) {
                break;
            }
            $partition = $best;
        }

        $place = self::classOf($partition);
        $ordered = $class;
        usort($ordered, static fn(int $x, int $y): int => [$place[$x], $x] <=> [$place[$y], $y]);

        return $ordered;
    }

    /**
     * Each member of the class at $at put first in turn, the classes
     * refined with the current positions after each: the refinement with
     * the smallest description, the first in id order among equal ones.
     * Null when the work limit is reached before every member was tried.
     *
     * @param list<list<int>> $classes
     *
     * @return list<list<int>>|null
     */
    private function bestTrialWithPositions(array $classes, int $at): ?array
    {
        $best = null;
        $bestDescription = '';
        foreach ($classes[$at] as $first) {
            $rest = array_values(array_filter($classes[$at], static fn(int $pid): bool => $pid !== $first));
            $tried = $classes;
            array_splice($tried, $at, 1, [[$first], $rest]);
            [$refined, $description, $finished] = $this->refineWithPositions($tried);
            if (!$finished) {
                return null;
            }
            if ($best === null || strcmp($description, $bestDescription) < 0) {
                $best = $refined;
                $bestDescription = $description;
            }
        }

        return $best;
    }

    /**
     * refine(), with the current positions of each participant's events
     * read as well: each with its position in grid order and the class of
     * the opponent there. Its work is counted apart from refine()'s,
     * against the same limit, so that reading the positions never stops
     * the refinement the order is made from any sooner.
     *
     * @param list<list<int>> $classes
     *
     * @return array{list<list<int>>, string, bool} As refine() returns them
     */
    private function refineWithPositions(array $classes): array
    {
        $positions = count($this->pins) + 1;
        $placedPositions = count($this->current) + 1;
        while (true) {
            if ($this->placedWork > $this->workLimit) {
                return [$classes, '', false];
            }

            $classOf = self::classOf($classes);
            $keys = [];
            for ($pid = 0; $pid < $this->count; ++$pid) {
                $seen = [];
                foreach ($this->opponents[$pid] as $other => $events) {
                    $seen[] = $classOf[$other] * $this->base + $events;
                }
                foreach ($this->pinned[$pid] as [$number, $other]) {
                    $seen[] = -1 - ($classOf[$other] * $positions + $number);
                }
                sort($seen);
                $placed = [];
                foreach ($this->placed[$pid] as [$number, $other]) {
                    $placed[] = $classOf[$other] * $placedPositions + $number;
                }
                sort($placed);
                $this->placedWork += count($seen) + count($placed) + 1;
                $keys[$pid] = implode(',', $seen) . '/' . implode(',', $placed);
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
     * What a participant's current positions say about it, naming no
     * participant: each position's number in grid order with the class
     * of the opponent there, sorted. Two participants that a symmetry of
     * the request with its current positions exchanges have the same key.
     *
     * @param list<list<int>> $classes
     */
    private function placedKey(int $pid, array $classes): string
    {
        return $this->placedKeyIn($pid, self::classOf($classes));
    }

    /**
     * @param list<list<int>> $classes
     *
     * @return array<int, int> Participant => the index of its class
     */
    private static function classOf(array $classes): array
    {
        $classOf = [];
        foreach ($classes as $at => $members) {
            foreach ($members as $member) {
                $classOf[$member] = $at;
            }
        }

        return $classOf;
    }

    /**
     * placedKey() with the class of every participant already looked up.
     *
     * @param array<int, int> $classOf
     */
    private function placedKeyIn(int $pid, array $classOf): string
    {
        // Each position as one number, ordered by its position and then by
        // the class of the opponent there, and written in eight bytes each,
        // high byte first, so that strcmp() orders two keys of as many
        // positions as their numbers
        $seen = [];
        foreach ($this->placed[$pid] as [$number, $other]) {
            $seen[] = $number * $this->count + $classOf[$other];
        }
        sort($seen);

        return pack('J*', ...$seen);
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
