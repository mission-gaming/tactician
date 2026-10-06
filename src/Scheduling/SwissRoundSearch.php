<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Stage\PairKey;
use Throwable;

/**
 * The search that pairs one Swiss round: participants in pairing order, the
 * first unpaired one tried against each later one in turn, backtracking
 * past pairings already played and pairings the constraints reject.
 *
 * ## What it returns
 *
 * The reference search is the plain depth-first one: take the first
 * unpaired participant, try every later unpaired participant in order, skip
 * a pair that has played, skip a pair the constraints reject (asked with a
 * context of the recorded rounds plus the pairs made so far in this round),
 * recurse on the rest, and return the first complete pairing found, or null
 * when the tree holds none. This class returns exactly that, and throws
 * exactly what that search throws, for every input.
 *
 * ## Constraints that are not known to be predicates
 *
 * For a constraint set ConstraintPurity does not know, this class is the
 * plain search and nothing else: each constraint is asked about the same
 * events, with the same contexts, the same number of times and in the same
 * order as the reference search asks. A constraint that counts its calls,
 * or throws for a pair it cannot judge, therefore gets what it always got.
 * The rest of this comment is about the other case: no constraints, or a
 * set ConstraintPurity knows.
 *
 * ## The shortcut, and why it returns the same
 *
 * A known constraint's verdict on a pair does not depend on the round's
 * other pairings (ConstraintPurity says why), so it is the verdict under
 * the context of the recorded rounds alone. Call a pair *open* when it has
 * not played and the constraints accept it under that context. The plain
 * search is then a depth-first walk of the perfect matchings of the graph
 * of open pairs, in a fixed order, and what it returns is the first one in
 * that order, or null when the graph has none.
 *
 * Take a node of the search: a set R of participants still unpaired. A
 * complete pairing below the node is a perfect matching of the open pairs
 * within R, and any such matching is a complete pairing below the node. So
 * the subtree under a node holds a complete pairing exactly when the open
 * pairs within its R have a perfect matching. The shortcut walks the same
 * candidates in the same order and enters a node only when that holds:
 *
 * - a subtree it does not enter is one the plain search returns null from,
 *   after which the plain search goes on to the same next candidate;
 * - a subtree it does enter holds a complete pairing, and by the same
 *   argument so does the first node entered below it, and so on down. It
 *   never backs out of a node, and the pairing it reaches is the first one
 *   the plain search would have reached.
 *
 * PerfectMatching decides whether a set has a perfect matching (Edmonds'
 * algorithm; its class comment gives the argument).
 *
 * ## A constraint that fails
 *
 * Before the shortcut is taken every pair that has not played is put to the
 * constraints once. A known constraint runs no code of the caller's, but
 * it can still fail on a malformed event in the recorded history. If any
 * of those questions throws, the shortcut is given up and the plain search
 * runs to its end, so the failure reaches the caller from the same question
 * as it always did. If none throws, no question the plain search asks can
 * throw either: each is one of those pairs, judged on the same events.
 *
 * ## Why it does not always take the shortcut
 *
 * A round with no dead end is paired by the plain search in about one
 * candidate per pair, and reading the whole graph first would cost more
 * than that. The plain search therefore runs first, with an allowance of
 * candidates; a search still running when the allowance is spent is
 * started again with the shortcut. Both give the same result, so where the
 * switch falls changes the time taken and nothing else. The allowance
 * counts candidates, not time, so a run is repeatable.
 *
 * @internal Not public API: SwissPairingEngine is the way to pair a round.
 */
final class SwissRoundSearch
{
    /** @var list<Participant> The participants to pair, in pairing order; a vertex is a position here */
    private array $participants = [];

    /** How many candidates the plain search may still try; null for no limit. */
    private ?int $allowance = null;

    /** Whether the constraints are ones the shortcut is sound for. */
    private readonly bool $constraintsAreKnown;

    /** How many searches were started again with the shortcut (for tests). */
    private int $prunedSearches = 0;

    /**
     * @param array<string, bool> $playedPairings Pair keys of the pairings already played
     * @param array<string, int> $homeCounts Home assignments so far, by participant id
     * @param SchedulingContext $context The context of the recorded rounds
     * @param int|null $plainAllowance Candidates the plain search may try before the search is
     *                                 started again with the shortcut; null for the default, the
     *                                 square of the number of participants. A test seam: the
     *                                 result is the same for every value. Not read for a
     *                                 constraint set ConstraintPurity does not know, which is
     *                                 searched plainly to the end
     */
    public function __construct(
        private readonly ?ConstraintSet $constraints,
        private readonly array $playedPairings,
        private readonly array $homeCounts,
        private readonly SchedulingContext $context,
        private readonly int $roundNumber,
        private readonly ?int $plainAllowance = null
    ) {
        $this->constraintsAreKnown = ConstraintPurity::isKnown($constraints);
    }

    /**
     * Pair the participants, or return null when no complete pairing exists.
     *
     * @param array<Participant> $orderedParticipants An even number of participants, in pairing order
     * @return list<Event>|null The round's events in the order the pairs were made
     */
    public function pair(array $orderedParticipants): ?array
    {
        $this->participants = array_values($orderedParticipants);
        $positions = array_keys($this->participants);

        if (!$this->constraintsAreKnown) {
            return $this->pairPlainlyToTheEnd($positions);
        }

        $this->allowance = $this->plainAllowance ?? count($positions) * count($positions);

        $events = $this->pairPlainly($positions, $this->context, []);
        if ($events !== false) {
            return $events;
        }

        ++$this->prunedSearches;
        $open = $this->openPairs();
        if ($open === null) {
            // A constraint failed on some pair. Whether the plain search
            // reaches that pair, and what it returns if it does not, is for
            // the plain search to say.
            return $this->pairPlainlyToTheEnd($positions);
        }

        $matching = new PerfectMatching(
            static fn(int $first, int $second): bool => $first < $second
                ? isset($open[$first][$second])
                : isset($open[$second][$first])
        );
        $mate = $matching->of($positions);
        if ($mate === null) {
            return null;
        }

        return $this->pairAlongTheMatchings($positions, $mate, $matching, $open);
    }

    /**
     * How many times pair() gave up the plain search and started again with
     * the shortcut.
     */
    public function getPrunedSearchCount(): int
    {
        return $this->prunedSearches;
    }

    /**
     * The plain search with no allowance: the reference search itself.
     *
     * @param list<int> $positions
     * @return list<Event>|null
     */
    private function pairPlainlyToTheEnd(array $positions): ?array
    {
        $this->allowance = null;
        $events = $this->pairPlainly($positions, $this->context, []);

        if ($events === false) {
            throw new InvariantViolationException('A Swiss round search with no allowance ran out of it');
        }

        return $events;
    }

    /**
     * The plain search, stopped when the allowance is spent.
     *
     * @param list<int> $remaining Positions still unpaired, in pairing order
     * @param SchedulingContext $context The recorded rounds plus this round's events so far
     * @param list<Event> $roundEvents
     * @return list<Event>|false|null Null for "none below here"; false once the allowance is spent,
     *                               which says nothing about what lies below
     */
    private function pairPlainly(array $remaining, SchedulingContext $context, array $roundEvents): array|false|null
    {
        if ($remaining === []) {
            return $roundEvents;
        }

        $pivot = array_shift($remaining);

        foreach ($remaining as $index => $opponent) {
            if ($this->allowance !== null && $this->allowance-- <= 0) {
                return false;
            }

            if ($this->havePlayed($pivot, $opponent)) {
                continue;
            }

            $event = $this->createEvent($pivot, $opponent);
            if ($this->constraints !== null && !$this->constraints->isSatisfied($event, $context)) {
                continue;
            }

            $rest = $remaining;
            unset($rest[$index]);

            $pairings = $this->pairPlainly(
                array_values($rest),
                // Constraints read the round's events so far; without
                // constraints nothing reads the context.
                $this->constraints === null ? $context : $context->withEvents([$event]),
                [...$roundEvents, $event]
            );

            if ($pairings !== null) {
                return $pairings;
            }
        }

        return null;
    }

    /**
     * The open pairs: those that have not played and that the constraints
     * accept under the context of the recorded rounds. Null when a
     * constraint threw for some pair.
     *
     * @return array<int, array<int, true>>|null Lower position => higher position => true
     */
    private function openPairs(): ?array
    {
        $open = [];
        $count = count($this->participants);

        for ($first = 0; $first < $count - 1; ++$first) {
            for ($second = $first + 1; $second < $count; ++$second) {
                if ($this->havePlayed($first, $second)) {
                    continue;
                }

                if ($this->constraints !== null) {
                    try {
                        // The earlier position is the pivot when the search
                        // makes this pair, which decides the event built.
                        if (!$this->constraints->isSatisfied($this->createEvent($first, $second), $this->context)) {
                            continue;
                        }
                    } catch (Throwable) {
                        return null;
                    }
                }

                $open[$first][$second] = true;
            }
        }

        return $open;
    }

    /**
     * The first complete pairing in search order, for a set of positions
     * whose open pairs have a perfect matching: at each step the first
     * opponent of the first unpaired position that is open to it and leaves
     * a set that still has a perfect matching.
     *
     * @param list<int> $remaining Positions still unpaired, in pairing order
     * @param array<int, int> $mate A perfect matching of $remaining among the open pairs
     * @param array<int, array<int, true>> $open
     * @return list<Event>
     */
    private function pairAlongTheMatchings(array $remaining, array $mate, PerfectMatching $matching, array $open): array
    {
        $roundEvents = [];

        while ($remaining !== []) {
            $pivot = array_shift($remaining);
            $chosen = null;

            foreach ($remaining as $index => $opponent) {
                if (!isset($open[$pivot][$opponent])) {
                    continue;
                }

                $restMate = $matching->without($mate, $pivot, $opponent);
                if ($restMate !== null) {
                    $chosen = $index;
                    $mate = $restMate;
                    break;
                }
            }

            if ($chosen === null) {
                // The pivot's partner in $mate is such an opponent.
                throw new InvariantViolationException('A perfectly matched set of participants has no first pairing');
            }

            $roundEvents[] = $this->createEvent($pivot, $remaining[$chosen]);
            unset($remaining[$chosen]);
            $remaining = array_values($remaining);
        }

        return $roundEvents;
    }

    private function havePlayed(int $first, int $second): bool
    {
        return isset($this->playedPairings[PairKey::of(
            $this->participants[$first]->getId(),
            $this->participants[$second]->getId()
        )]);
    }

    /**
     * Create the event with home going to whichever participant has had
     * fewer home assignments (the lower-placed participant on a tie).
     */
    private function createEvent(int $higherPlaced, int $lowerPlaced): Event
    {
        $higher = $this->participants[$higherPlaced];
        $lower = $this->participants[$lowerPlaced];

        $higherHomes = $this->homeCounts[$higher->getId()] ?? 0;
        $lowerHomes = $this->homeCounts[$lower->getId()] ?? 0;

        $participants = $higherHomes < $lowerHomes
            ? [$higher, $lower]
            : [$lower, $higher];

        return new Event($participants, new Round($this->roundNumber));
    }
}
