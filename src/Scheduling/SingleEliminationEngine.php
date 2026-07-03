<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Override;

/**
 * Single elimination preset: a canned composition of single-round
 * knockout stages behind the standard stage driver loop.
 *
 * Entry pairing folds by list position (in an eight-slot bracket:
 * 1 vs 8, 4 vs 5, 2 vs 7, 3 vs 6), placing positions 1 and 2 in opposite
 * halves so the top entrants can only meet in the latest possible round;
 * fields that are not a power of two give byes to the top positions.
 * Position is authoritative - feed the entrant list in seeding order
 * (progression selectors already do).
 *
 * Two path behaviours (EliminationOptions):
 * - Fixed bracket path (default): survivors keep their bracket slots, so
 *   the draw made at entry decides every route to the final.
 * - Re-seeded (reseedEachRound): survivors are re-ranked by standings
 *   after every round and re-folded, so the strongest remaining records
 *   meet as late as possible.
 *
 * Ties are played over one event or two mirrored legs (legsPerTie); the
 * aggregate of a level two-legged tie is the application's to decide and
 * is recorded as a tie decision (see TieDecision).
 *
 * There is deliberately no champion accessor: rank 1 of the outcome's
 * standings, or MatchOutcomeSelector::winners() over the final round, is
 * the consumer's derivation.
 */
final readonly class SingleEliminationEngine implements StageEngineInterface
{
    use EliminationBracketSupport;

    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
    }

    /**
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'single-elimination',
            $this->options->legsPerTie
        );
    }

    /**
     * Pair the next unresolved round of the bracket.
     *
     * @throws InvalidConfigurationException When inputs are malformed, a round is partially
     *                                       resolved (record the missing results via
     *                                       StageState::withAdditionalResults()), a tie is
     *                                       undecided, or the bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $resolution = $this->resolveBracket($state);

        if ($resolution['pending'] === null) {
            throw new InvalidConfigurationException(
                'Bracket is complete; no further rounds exist',
                []
            );
        }

        return $resolution['pending'];
    }

    /**
     * @throws InvalidConfigurationException When the recorded state is malformed
     *                                       (partially resolved rounds, undecided ties)
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveBracket($state)['pending'] === null;
    }

    /**
     * The uniform completion product; null while the bracket is unfinished.
     *
     * The win/loss standings reproduce conventional bracket placement with
     * no special cases: in an 8-entrant knockout where favourites hold,
     * the final's winner finishes 3-0, its loser 2-1, the semifinal losers
     * 1-1, and the quarter-final losers 0-1 - 1st, 2nd, joint 3rd, joint
     * 5th, including the genuine ties.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        if (!$this->isComplete($state)) {
            return null;
        }

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $state->getResults()
        );

        return new StageOutcome(
            $standings,
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound()
        );
    }

    /**
     * Replay the bracket from the entry fold through the recorded results.
     *
     * @return array{pending: RoundPairing|null}
     *
     * @throws InvalidConfigurationException When a round is partially resolved or a tie is broken
     */
    private function resolveBracket(StageState $state): array
    {
        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);

        $resultIndex = $this->indexResults($state->getResults());
        $totalRounds = (int) log($this->bracketSize(count($participants)), 2);

        $slots = $this->buildInitialSlots($participants);

        for ($round = 1; $round <= $totalRounds; ++$round) {
            // Re-seeded path: after the entry round, survivors are
            // re-ranked by standings and re-folded each round. Only
            // results from earlier rounds may inform the ranking - the
            // replay must reproduce the same pairing before and after the
            // round's own results are recorded.
            if ($this->options->reseedEachRound && $round > 1) {
                $survivors = array_values(array_filter($slots, fn (?Participant $slot) => $slot !== null));
                $slots = $this->buildInitialSlots($this->rankByStandings($survivors, $state, $round));
            }

            $pairs = array_chunk($slots, 2);
            $playable = [];
            $resolved = 0;

            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $playable[] = $pair;
                    if ($this->lookupAdvancer($resultIndex, $round, $pair[0], $pair[1], $this->options->legsPerTie) !== null) {
                        ++$resolved;
                    }
                }
            }

            if ($resolved < count($playable)) {
                if ($resolved > 0) {
                    throw new InvalidConfigurationException(
                        "Round {$round} is partially resolved: {$resolved} of " . count($playable)
                            . ' ties have complete results. Record the remaining results before pairing the next round.',
                        ['round' => $round, 'resolved' => $resolved, 'playable' => count($playable)]
                    );
                }

                return ['pending' => $this->buildRoundPairing($round, $totalRounds, $pairs)];
            }

            $nextSlots = [];
            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $nextSlots[] = $this->lookupAdvancer($resultIndex, $round, $pair[0], $pair[1], $this->options->legsPerTie);
                } else {
                    $nextSlots[] = $pair[0] ?? $pair[1] ?? null;
                }
            }

            $slots = $nextSlots;
        }

        return ['pending' => null];
    }

    /**
     * @param array<array<Participant|null>> $pairs
     */
    private function buildRoundPairing(int $roundNumber, int $totalRounds, array $pairs): RoundPairing
    {
        $label = $this->roundLabel($roundNumber, $totalRounds);
        $round = new Round($roundNumber, ['label' => $label]);

        $events = [];
        $byes = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                foreach ($this->buildTieEvents($first, $second, $round, $this->options->legsPerTie) as $event) {
                    $events[] = $event;
                }
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null) {
                $byes[] = $advancer;
            }
        }

        return new RoundPairing($roundNumber, $label, $events, $byes);
    }

    /**
     * Order survivors by their standings rank as of the given round:
     * only results from earlier rounds count, so the ranking is stable
     * across replays regardless of what has been recorded since.
     *
     * @param array<Participant> $survivors
     * @return array<Participant>
     */
    private function rankByStandings(array $survivors, StageState $state, int $beforeRound): array
    {
        $priorResults = array_values(array_filter(
            $state->getResults(),
            fn ($result) => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $beforeRound
        ));

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $priorResults
        );

        $survivorIds = [];
        foreach ($survivors as $survivor) {
            $survivorIds[$survivor->getId()] = true;
        }

        $ranked = [];
        foreach ($standings->getEntries() as $entry) {
            if (isset($survivorIds[$entry->getParticipant()->getId()])) {
                $ranked[] = $entry->getParticipant();
            }
        }

        return $ranked;
    }

    private function roundLabel(int $roundNumber, int $totalRounds): string
    {
        return match ($totalRounds - $roundNumber) {
            0 => 'final',
            1 => 'semifinal',
            2 => 'quarterfinal',
            default => 'round of ' . 2 ** ($totalRounds - $roundNumber + 1),
        };
    }
}
