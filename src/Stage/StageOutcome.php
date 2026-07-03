<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\Standings;

/**
 * The uniform completion product of a results-driven stage.
 *
 * Every format finishes as "an outcome you can select from": standings,
 * recorded results, bye counts, and the structural final round. This is
 * purely descriptive — there is deliberately no champion or winner
 * vocabulary, because "the champion" is a consumer's interpretation of a
 * derivation (rank 1 of the standings, or the winners of the final round),
 * not a scheduling concept.
 */
final readonly class StageOutcome
{
    /**
     * @param array<Result> $results
     * @param array<string, int> $byes Bye counts keyed by participant ID
     */
    public function __construct(
        private Standings $standings,
        private array $results,
        private array $byes = [],
        private ?RoundPairing $finalRound = null
    ) {
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
     * playing any round.
     */
    public function getFinalRound(): ?RoundPairing
    {
        return $this->finalRound;
    }
}
