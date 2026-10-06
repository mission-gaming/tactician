<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
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
 * a pair that has played, skip a pair the constraints reject, recurse on
 * the rest, and return the first complete pairing found, or null when the
 * tree holds none. This class returns exactly that, for every input. It
 * differs only in the subtrees it does not walk.
 *
 * ## Why a subtree may be skipped
 *
 * Take a node of the search: a set R of participants still unpaired. Every
 * complete pairing below the node splits R into pairs, each of which has
 * not played and is accepted by the constraints. Let G be any graph on R
 * that has an edge for every pair that could be used below the node. Then
 * a complete pairing below the node is a perfect matching of G. So:
 *
 *     G has no perfect matching  =>  the subtree holds no complete pairing.
 *
 * The plain search would walk that subtree and return null from it. Not
 * walking it returns the same null, and the search then continues with the
 * same next candidate. Nothing else is ever skipped: a subtree is entered
 * unless G has no perfect matching, the candidates are tried in the same
 * order, and the first complete pairing in that order is returned. The
 * result is therefore the plain search's result, and so is a failure.
 *
 * G is always a superset of the usable pairs (the implication needs only
 * that), in one of two forms:
 *
 * - **The pairs that have not played.** True for any constraints, which can
 *   only remove pairs.
 * - **The pairs that have not played and that the constraints accept**,
 *   when every constraint's verdict on a pair is the same at every node
 *   (see INDEPENDENT_OF_THE_ROUND). Then G is exactly the usable pairs, a
 *   node is entered only when a complete pairing lies below it, and the
 *   search never backtracks. A verdict that could not be computed (the
 *   constraint threw) counts as an edge, which keeps G a superset.
 *
 * PerfectMatching decides whether G has a perfect matching (Edmonds'
 * algorithm; its class comment gives the argument).
 *
 * ## Why it does not always prune
 *
 * A round with no dead end is paired by the plain search in about one
 * candidate per pair, and reading a whole graph first would cost more than
 * that. The plain search therefore runs first, with an allowance of
 * candidates; a search still running when the allowance is spent is
 * started again with pruning. Both give the same result, so where the
 * switch falls changes the time taken and nothing else. The allowance
 * counts candidates, not time, so a run is repeatable.
 *
 * Constraints are assumed to be predicates: the same event and context
 * give the same verdict, with no effect on anything else. The pruned
 * search asks a constraint about fewer pairs on the path, and in the second
 * form of G about pairs the plain search might not have reached.
 *
 * @internal Not public API: SwissPairingEngine is the way to pair a round.
 */
final class SwissRoundSearch
{
    /**
     * The constraint classes whose verdict on a candidate pair does not
     * depend on the pairings already chosen for the round being paired.
     *
     * Each reads, besides the candidate event, the plan and the participant
     * list, only context events that one of the candidate's two
     * participants takes part in (getEventsForParticipant(),
     * getEventsBetween()). The round's other pairings involve neither of
     * them, because a participant plays once in a round. So the verdict
     * under the context of the recorded rounds is the verdict at every
     * node. `tests/Unit/Scheduling/SwissRoundSearchTest.php` checks this
     * for each class.
     *
     * Only an object of exactly one of these classes qualifies: a subclass
     * may override isSatisfied(). MetadataConstraint and CallableConstraint
     * hand the context to a callable of the caller's, which may read
     * anything, so they do not qualify.
     */
    private const array INDEPENDENT_OF_THE_ROUND = [
        NoRepeatPairings::class,
        MinimumRestPeriodsConstraint::class,
        ConsecutiveRoleConstraint::class,
        RoleBalanceConstraint::class,
        SeedProtectionConstraint::class,
    ];

    private const int NOT_READ = 0;
    private const int CLOSED = 1;
    private const int OPEN = 2;
    private const int UNKNOWN = 3;

    /** @var list<Participant> The participants to pair, in pairing order; a vertex is a position here */
    private array $participants = [];

    /** @var array<int, array<int, int>> Lower position => higher position => one of the edge states above */
    private array $edges = [];

    /** How many candidates the plain search may still try. */
    private int $allowance = 0;

    private readonly bool $verdictsAreIndependentOfTheRound;

    /** How many searches were started again with pruning (for tests). */
    private int $prunedSearches = 0;

    /**
     * @param array<string, bool> $playedPairings Pair keys of the pairings already played
     * @param array<string, int> $homeCounts Home assignments so far, by participant id
     * @param SchedulingContext $context The context of the recorded rounds
     * @param int|null $plainAllowance Candidates the plain search may try before the search is
     *                                 started again with pruning; null for the default, the
     *                                 square of the number of participants. A test seam: the
     *                                 result is the same for every value
     */
    public function __construct(
        private readonly ?ConstraintSet $constraints,
        private readonly array $playedPairings,
        private readonly array $homeCounts,
        private readonly SchedulingContext $context,
        private readonly int $roundNumber,
        private readonly ?int $plainAllowance = null
    ) {
        $this->verdictsAreIndependentOfTheRound = $this->constraintsAreIndependentOfTheRound();
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
        $this->edges = [];
        $count = count($this->participants);

        $this->allowance = $this->plainAllowance ?? $count * $count;

        $events = $this->pairPlainly(array_keys($this->participants), $this->context, []);
        if ($events !== false) {
            return $events;
        }

        ++$this->prunedSearches;
        $matching = new PerfectMatching($this->mayBePaired(...));
        $mate = $matching->of(array_keys($this->participants));
        if ($mate === null) {
            return null;
        }

        return $this->pairWithPruning(array_keys($this->participants), $mate, $matching, $this->context, []);
    }

    /**
     * How many times pair() gave up the plain search and started again with
     * pruning.
     */
    public function getPrunedSearchCount(): int
    {
        return $this->prunedSearches;
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
            if ($this->allowance-- <= 0) {
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
     * The same search, entering a node only when its participants have a
     * perfect matching among the pairs that may be used.
     *
     * @param list<int> $remaining Positions still unpaired, in pairing order
     * @param array<int, int> $mate A perfect matching of $remaining in the graph of mayBePaired()
     * @param SchedulingContext $context The recorded rounds plus this round's events so far
     * @param list<Event> $roundEvents
     * @return list<Event>|null
     */
    private function pairWithPruning(
        array $remaining,
        array $mate,
        PerfectMatching $matching,
        SchedulingContext $context,
        array $roundEvents
    ): ?array {
        if ($remaining === []) {
            return $roundEvents;
        }

        $pivot = array_shift($remaining);

        foreach ($remaining as $index => $opponent) {
            if ($this->havePlayed($pivot, $opponent)) {
                continue;
            }

            $event = $this->createEvent($pivot, $opponent);
            if (!$this->accepts($pivot, $opponent, $event, $context)) {
                continue;
            }

            $restMate = $matching->without($mate, $pivot, $opponent);
            if ($restMate === null) {
                // The rest cannot be paired off at all: the subtree holds
                // no complete pairing (see the class comment).
                continue;
            }

            $rest = $remaining;
            unset($rest[$index]);

            $pairings = $this->pairWithPruning(
                array_values($rest),
                $restMate,
                $matching,
                $this->constraints === null || $this->verdictsAreIndependentOfTheRound
                    ? $context
                    : $context->withEvents([$event]),
                [...$roundEvents, $event]
            );

            if ($pairings !== null) {
                return $pairings;
            }
        }

        return null;
    }

    /**
     * The constraints' verdict on a candidate at a node of the pruned
     * search.
     *
     * When verdicts are independent of the round, the verdict read once
     * under the context of the recorded rounds is the verdict here. A
     * verdict that could not be read then (the constraint threw) is asked
     * for again, so that the failure reaches the caller as it does from the
     * plain search.
     */
    private function accepts(int $pivot, int $opponent, Event $event, SchedulingContext $context): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        if ($this->verdictsAreIndependentOfTheRound) {
            $state = $this->edgeState($pivot, $opponent);
            if ($state !== self::UNKNOWN) {
                return $state === self::OPEN;
            }
        }

        return $this->constraints->isSatisfied($event, $context);
    }

    /**
     * Whether two positions are joined in G: the pair has not played and,
     * where the verdict is the same at every node, the constraints do not
     * reject it.
     */
    private function mayBePaired(int $first, int $second): bool
    {
        return $this->edgeState($first, $second) !== self::CLOSED;
    }

    private function edgeState(int $first, int $second): int
    {
        // The pivot of a pair is always the earlier position, which decides
        // the event built for it.
        if ($first > $second) {
            [$first, $second] = [$second, $first];
        }

        $state = $this->edges[$first][$second] ?? self::NOT_READ;
        if ($state !== self::NOT_READ) {
            return $state;
        }

        if ($this->havePlayed($first, $second)) {
            $state = self::CLOSED;
        } elseif ($this->constraints === null || !$this->verdictsAreIndependentOfTheRound) {
            $state = self::OPEN;
        } else {
            try {
                $state = $this->constraints->isSatisfied($this->createEvent($first, $second), $this->context)
                    ? self::OPEN
                    : self::CLOSED;
            } catch (Throwable) {
                // Not this method's failure to report: the pair counts as
                // usable, and accepts() asks again if the search gets here.
                $state = self::UNKNOWN;
            }
        }

        return $this->edges[$first][$second] = $state;
    }

    private function constraintsAreIndependentOfTheRound(): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        // A subclass may override isSatisfied() or getConstraints().
        if ($this->constraints::class !== ConstraintSet::class) {
            return false;
        }

        foreach ($this->constraints->getConstraints() as $constraint) {
            if (!in_array($constraint::class, self::INDEPENDENT_OF_THE_ROUND, true)) {
                return false;
            }
        }

        return true;
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
