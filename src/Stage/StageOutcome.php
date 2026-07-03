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
 * derivation (rank 1 of the standings, or the winners of the final round),
 * not a scheduling concept.
 *
 * Pooled stages combine into one outcome optionally carrying the pool
 * structure, so intra-pool slices (top 2 per pool) and cross-pool queries
 * (best 8 overall) share one input type.
 */
final readonly class StageOutcome
{
    /**
     * @param array<Result> $results
     * @param array<string, int> $byes Bye counts keyed by participant ID
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label, for pooled stages
     */
    public function __construct(
        private Standings $standings,
        private array $results,
        private array $byes = [],
        private ?RoundPairing $finalRound = null,
        private array $pools = []
    ) {
    }

    /**
     * Combine per-pool outcomes into one pooled outcome.
     *
     * The combined standings rank every pool's participants in one table
     * (the substrate for cross-pool selections); results and bye counts
     * merge; there is no single final round across pools. Pool insertion
     * order is preserved — selectors iterate pools in this order.
     *
     * @param array<string, StageOutcome> $pools Per-pool outcomes keyed by pool label
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
     * The final table, meaningful for every format — elimination stages
     * rank by win/loss record, which reproduces conventional bracket
     * placement including its genuine ties.
     */
    public function getStandings(): Standings
    {
        return $this->standings;
    }

    /**
     * @return array<Result>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Bye counts keyed by participant ID.
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
     */
    public function getFinalRound(): ?RoundPairing
    {
        return $this->finalRound;
    }

    /**
     * Per-pool outcomes keyed by pool label, for pooled stages.
     *
     * @return array<string, StageOutcome>
     */
    public function getPools(): array
    {
        return $this->pools;
    }

    public function hasPools(): bool
    {
        return $this->pools !== [];
    }

    public function getPool(string $label): ?StageOutcome
    {
        return $this->pools[$label] ?? null;
    }
}
