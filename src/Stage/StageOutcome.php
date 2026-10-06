<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
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
 * The standings are a table of the recorded results and nothing more. For
 * a league or a Swiss stage rank 1 of that table is the usual reading of
 * who won. For an elimination stage it is not: the table counts wins and
 * losses, and the participant who took the title can rank below one it
 * beat (in double elimination, a participant who wins the losers bracket
 * and the first grand final and loses the reset has more wins than the
 * title holder; a two-legged final that a tie decision settles adds the
 * same to both finalists' records, so the table does not say which of
 * them it sent on). Read who won a bracket from its final round, with
 * MatchOutcomeSelector::winners().
 *
 * Pooled stages combine into one outcome optionally carrying the pool
 * structure, so intra-pool slices (top 2 per pool) and cross-pool queries
 * (best 8 overall) share one input type.
 *
 * @experimental
 */
final readonly class StageOutcome
{
    /**
     * Nothing is checked or derived: the outcome holds what it is given.
     * The engines build it from a stage state; build one by hand for a
     * stage the application ran itself (a round-robin pool, for example)
     * so that selectors can read it.
     *
     * @param Standings $standings The final table; rank selections read it
     * @param array<Result> $results The recorded results; outcome selections read them
     * @param array<string, int> $byes Bye counts keyed by participant ID
     * @param RoundPairing|null $finalRound The last round played; outcome selections need it
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label, for pooled stages
     */
    public function __construct(
        private Standings $standings,
        private array $results,
        private array $byes = [],
        private ?RoundPairing $finalRound = null,
        private array $pools = []
    ) {}

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
     * part of it when it has an entry in a pool's standings.
     *
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label
     * @param StandingsCalculator $calculator Orders the combined table
     */
    public static function combining(
        array $pools,
        StandingsCalculator $calculator = new StandingsCalculator()
    ): self {
        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        $results = [];
        $byes = [];

        foreach ($pools as $poolOutcome) {
            foreach ($poolOutcome->getStandings()->getEntries() as $entry) {
                $participantsById[$entry->getParticipant()->getId()] ??= $entry->getParticipant();
            }
            foreach ($poolOutcome->getResults() as $result) {
                $results[] = $result;
            }
            foreach ($poolOutcome->getByes() as $participantId => $count) {
                $byes[$participantId] = ($byes[$participantId] ?? 0) + $count;
            }
        }

        return new self(
            $calculator->calculate(array_values($participantsById), $results),
            $results,
            $byes,
            null,
            $pools
        );
    }

    /**
     * The final table: every participant's record over the stage, in the
     * order of the standings calculator that built it (rank 1 first).
     *
     * For an elimination stage it is the win/loss record and not the
     * bracket placement: a bye is not a win, each leg of a two-legged tie
     * counts on its own, and participants who went out in the same round
     * are separated by their records and the calculator's tiebreakers. In
     * particular rank 1 need not be the participant who took the title
     * (see the class docblock).
     */
    public function getStandings(): Standings
    {
        return $this->standings;
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
     * or in double elimination the grand final or its reset.
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
