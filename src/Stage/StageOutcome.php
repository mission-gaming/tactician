<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;

/**
 * The uniform completion product of a results-driven stage.
 *
 * Every format finishes as "an outcome you can select from": standings,
 * recorded results, bye counts, and the structural final round. This is
 * purely descriptive — there is deliberately no champion or winner
 * vocabulary, because "the champion" is a consumer's interpretation of a
 * derivation, not a scheduling concept.
 *
 * The standings are the table of the stage, rank 1 first. For a league or
 * a Swiss stage that is the table of the recorded results (a Swiss stage
 * credits a bye in it with what the ranking strategy says a bye is worth,
 * a win by default). For an elimination stage it is the bracket
 * placement: the entrant who advanced from the last round is rank 1, the
 * one it beat there is rank 2, and the others follow by the round they
 * went out in, later first, with the calculator's order of their records
 * between those who went out together (see Standings on tiers).
 * MatchOutcomeSelector reads who advanced from the last round, or did not,
 * from the results themselves.
 *
 * An outcome can carry the entrant list of its stage. Where results do not
 * decide between two entries of the table, the position in that list does:
 * a label, a seed attribute or an ID never decides. The engines pass the
 * list they were given. An outcome built by hand for a stage the
 * application ran is given it as the last constructor argument, and its
 * table is then put in that order where entries are level.
 *
 * Pooled stages combine into one outcome optionally carrying the pool
 * structure, so intra-pool slices (top 2 per pool) and cross-pool queries
 * (best 8 overall) share one input type.
 *
 * @experimental
 */
final readonly class StageOutcome
{
    private Standings $standings;

    /** @var list<Participant> */
    private array $entrants;

    /**
     * The outcome holds what it is given, with one derivation: when an
     * entrant list is given, the entries of every tied set of the
     * standings are put in the order of that list
     * (Standings::withTiedSetsOrderedBy()), so that the table and every
     * selection read from it agree. Nothing else is checked. The engines
     * build an outcome from a stage state; build one by hand for a stage
     * the application ran itself (a round-robin pool, for example) so that
     * selectors can read it.
     *
     * @param Standings $standings The final table; rank selections read it
     * @param array<Result> $results The recorded results; outcome selections read them
     * @param array<string, int> $byes Bye counts keyed by participant ID
     * @param RoundPairing|null $finalRound The last round played; outcome selections need it
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label, for pooled stages
     * @param array<Participant> $entrants The entrant list of the stage, position 1 first; empty
     *                                     when the stage has none, and the table is then kept
     *                                     as given
     * @param StandingsCalculator|null $calculator The calculator the standings were calculated
     *                                             with, for a selector that has to calculate a
     *                                             table again (see getCalculator())
     */
    public function __construct(
        Standings $standings,
        private array $results,
        private array $byes = [],
        private ?RoundPairing $finalRound = null,
        private array $pools = [],
        array $entrants = [],
        private ?StandingsCalculator $calculator = null
    ) {
        $this->entrants = array_values($entrants);
        $this->standings = $this->entrants === [] ? $standings : $standings->withTiedSetsOrderedBy($this->entrants);
    }

    /**
     * Combine per-pool outcomes into one pooled outcome.
     *
     * The combined standings rank every pool's participants in one table
     * (the substrate for cross-pool selections); results and bye counts
     * merge; there is no single final round across pools. Pool insertion
     * order is preserved — selectors iterate pools in this order.
     *
     * The combined table is calculated afresh from all the results by the
     * given calculator; the pools keep their own tables. A participant is
     * part of it when it has an entry in a pool's standings. The calculator
     * refuses, with an InvalidInputException, a result that names a
     * participant with no such entry and two results for one event object.
     *
     * An adjustment a pool's table shows is kept: the combined entry of the
     * participant carries the adjustment figure of its pool entry, and is
     * ordered by what it earned plus that figure. Of a participant with an
     * entry in two pools, the first pool's figure is taken, as its
     * participant is.
     *
     * A decision a pool's table carries (Standings::withDecisions()) stays
     * with that table, where a per-group or a best-of-rank selection reads
     * it. The combined table carries none: its tied sets are not the
     * pools' (entrants of other pools can be level with a decided set),
     * so a tie in it is settled by a decision of its own, given to the
     * selector that reads it or to the table of an outcome built by hand.
     *
     * The combined table belongs to no single entrant list unless one is
     * given: without it, entries that are level across the pools are in
     * the calculator's own fallback order (seed, then participant ID).
     * Give the list the pools were dealt from to have position in it
     * decide between them.
     *
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label
     * @param StandingsCalculator $calculator Orders the combined table
     * @param array<Participant> $entrants The entrant list of the whole pooled stage, if the
     *                                     combined table is to follow it where entries are level
     *
     * @throws InvalidConfigurationException Not thrown by the library's calculator: the combined
     *                                       table is given no bye, which is all it refuses
     */
    public static function combining(
        array $pools,
        StandingsCalculator $calculator = new StandingsCalculator(),
        array $entrants = []
    ): self {
        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        $results = [];
        $byes = [];
        $adjustments = [];

        foreach ($pools as $poolOutcome) {
            foreach ($poolOutcome->getStandings()->getEntries() as $entry) {
                $id = $entry->getParticipant()->getId();
                if (!isset($participantsById[$id]) && $entry->getAdjustment() !== 0.0) {
                    $adjustments[] = new Adjustment($id, $entry->getAdjustment());
                }
                $participantsById[$id] ??= $entry->getParticipant();
            }
            foreach ($poolOutcome->getResults() as $result) {
                $results[] = $result;
            }
            foreach ($poolOutcome->getByes() as $participantId => $count) {
                $byes[$participantId] = ($byes[$participantId] ?? 0) + $count;
            }
        }

        return new self(
            $calculator->calculateWith(array_values($participantsById), $results, [], $adjustments),
            $results,
            $byes,
            null,
            $pools,
            $entrants,
            $calculator
        );
    }

    /**
     * The calculator the outcome's table was calculated with, when the
     * outcome was given it: combining() passes on the one that ordered the
     * combined table. Null for an outcome built without one, an engine's
     * outcome included.
     *
     * A selector reads it when it has to calculate a table again, as
     * BestOfRankSelector does to compare pools of unequal size without the
     * results against the lowest-placed entrants of the larger ones
     * (PoolComparison::DiscardLowest).
     */
    public function getCalculator(): ?StandingsCalculator
    {
        return $this->calculator;
    }

    /**
     * The final table of the stage, rank 1 first: every participant's
     * record, in the order the class docblock describes. Entries that no
     * result separates are reported by Standings::getTiedSets(); they are
     * in entrant-list order when the outcome has an entrant list.
     */
    public function getStandings(): Standings
    {
        return $this->standings;
    }

    /**
     * The entrant list of the stage, position 1 first, or an empty list
     * when the outcome was built without one. For an engine's outcome it is
     * every participant the stage has seen: the active list in its order,
     * then those withdrawn, who have no position any more, in the order of
     * their IDs.
     *
     * @return list<Participant>
     */
    public function getEntrants(): array
    {
        return $this->entrants;
    }

    /**
     * The recorded results of the whole stage, in the order they were
     * recorded; for a pooled outcome, pool by pool in pool order.
     *
     * @return array<Result>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Bye counts keyed by participant ID. A participant that had no bye
     * has no entry.
     *
     * @return array<string, int>
     */
    public function getByes(): array
    {
        return $this->byes;
    }

    /**
     * The stage's last round, structurally — what outcome-based selectors
     * read winners and losers from. Null when the stage completed without
     * playing any round (or is a pooled combination).
     *
     * For a bracket this is the round that decided the title: the final,
     * or in double elimination the grand final or its reset. In a single
     * elimination bracket with a third-place tie the round holds that tie
     * as well, after the final (see BracketTie). It is the
     * last round recorded on the state, so a round recorded after the
     * final takes its place.
     */
    public function getFinalRound(): ?RoundPairing
    {
        return $this->finalRound;
    }

    /**
     * Per-pool outcomes keyed by pool label, in the order they were
     * combined; empty for a stage without pools.
     *
     * @return array<string, StageOutcome>
     */
    public function getPools(): array
    {
        return $this->pools;
    }

    /**
     * Whether this is a pooled outcome, which per-group selections need.
     */
    public function hasPools(): bool
    {
        return $this->pools !== [];
    }

    /**
     * The outcome of one pool, or null when no pool has that label.
     */
    public function getPool(string $label): ?StageOutcome
    {
        return $this->pools[$label] ?? null;
    }
}
