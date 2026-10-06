<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
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
 * Ties are played over one event or two mirrored legs (legsPerTie). A tie
 * that finishes level - a drawn single event, or two legs that do not
 * decide - is the application's to decide and is recorded as a tie
 * decision on the result (see TieDecision); a level tie without one is
 * refused by every call.
 *
 * There is deliberately no champion accessor. The champion is the
 * participant who advances from the final:
 * MatchOutcomeSelector::winners() over the outcome's final round. Rank 1
 * of the outcome's standings is not that derivation. The standings are a
 * win/draw/loss table over the bracket's results, in the order of the
 * standings calculator, and the participant who lost the final can be
 * first in it: after a bye, and after a two-legged final that its tie
 * decision settled (see getOutcome()).
 *
 * @experimental
 */
final readonly class SingleEliminationEngine implements StageEngineInterface, FingerprintedEngine
{
    use EliminationBracketSupport;

    /**
     * Every option is accepted; grandFinalReset is not read.
     *
     * @param StandingsCalculator $standingsCalculator Orders the outcome's standings and, in a
     *                                                 re-seeded bracket, the survivors before every
     *                                                 round after the first. On a fixed path it
     *                                                 pairs nothing
     */
    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {}

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With default options it names the format and nothing else. Part of
     * it when not at the default: legsPerTie (default 1) and
     * reseedEachRound (default false). A re-seeded bracket is paired from
     * the table after every round, so there the rules of the standings
     * calculator are part of it as well, when they are not the default:
     * the ranking scale (the three values of a WinDrawLossRanking, default
     * 3/1/0; any other RankingStrategy is stated as custom and not told
     * apart from another of your own) and the tiebreakers by name and in
     * order, or, in their place, that the calculator is a subclass of
     * StandingsCalculator (stated as custom; nothing is read from it, and
     * two subclasses are not told apart).
     *
     * Not part of it: grandFinalReset, which this engine does not read,
     * and the standings calculator of a bracket on a fixed path, which
     * orders the outcome and pairs nothing.
     */
    #[Override]
    public function getFingerprint(): string
    {
        $fingerprint = EngineFingerprint::of('single-elimination')
            ->with('legs-per-tie', $this->options->legsPerTie, 1)
            ->with('reseed-each-round', $this->options->reseedEachRound, false);

        if ($this->options->reseedEachRound) {
            $fingerprint = $fingerprint->withStandingsRules($this->standingsCalculator);
        }

        return $fingerprint->toString();
    }

    /**
     * The shape of the bracket for every participant the state has seen:
     * the bracket size, the number of rounds and the events to expect.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'single-elimination',
            $this->options->legsPerTie
        );
    }

    /**
     * Pair the next unresolved round of the bracket.
     *
     * The bracket is replayed from the state's participant list and its
     * results on every call, so the same state gives the same pairing, and
     * a state that has the round's pairing recorded but none of its results
     * gives that round again. The call does not record anything: pass the
     * pairing and its results to StageState::withRoundPlayed().
     *
     * Round numbers are 1-based, and the round carries its label ('round
     * of 16', 'quarterfinal', 'semifinal', 'final'). The events are in
     * bracket order, top of the bracket first; a two-legged tie is two
     * consecutive events with the roles reversed, marked 'tie_leg' 1 and 2
     * in their metadata. The byes are the participants who advance without
     * playing, which happens in the first round only.
     *
     * @throws InvalidConfigurationException When the state has fewer than 2 participants or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a round is partially
     *                                       resolved (record the missing results via
     *                                       StageState::withAdditionalResults()), a completed tie
     *                                       is level without a usable tie decision, or the
     *                                       bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $resolution = $this->resolveBracket($state);

        if ($resolution['pending'] === null) {
            throw new InvalidConfigurationException(
                'Bracket is complete; no further rounds exist',
                [],
                reason: InvalidConfigurationReason::BracketComplete
            );
        }

        return $resolution['pending'];
    }

    /**
     * Whether the final has been decided: every round of the bracket has a
     * result for each of its ties.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed: every case
     *                                       pairNextRound() names but the complete bracket
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveBracket($state)['pending'] === null;
    }

    /**
     * The uniform completion product; null while the bracket is unfinished.
     *
     * The standings are a win/draw/loss table over the bracket's results,
     * in the order of the standings calculator. In a single-leg bracket
     * with no byes the records follow conventional bracket placement: in
     * an 8-entrant knockout the final's winner finishes 3-0, its loser 2-1,
     * the semifinal losers 1-1, and the quarter-final losers 0-1. The
     * table still lists the participants one after another: those with the
     * same record (the two semifinal losers) are separated by the
     * calculator's tiebreakers and then by its final ordering, not by the
     * bracket. A single-leg event that finished level and was decided by
     * its tie decision counts in the table as a win for the participant
     * who advanced, so those records hold; the outcome's results are the
     * results as recorded, the draw included.
     *
     * The table is not a placement in two cases. A participant with a bye
     * has played one event fewer, so the winner of a final can have the
     * same points as its loser (one win against one win and one loss). And
     * the legs of a two-legged tie are counted as recorded, whoever
     * advanced: a final level over two legs leaves the finalists level in
     * the table. In both cases the calculator's later criteria (score
     * difference, the seed attribute, the label) decide which finalist is
     * first. For the champion use MatchOutcomeSelector::winners() over the
     * outcome's final round.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        $resolution = $this->resolveBracket($state);
        if ($resolution['pending'] !== null) {
            return null;
        }

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $this->resultsForStandings($state->getResults(), $resolution['levelAdvancers'])
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
     * @return array{pending: RoundPairing|null, levelAdvancers: array<string, Participant>}
     *
     * @throws InvalidConfigurationException When a round is partially resolved, a tie is broken, or
     *                                       the state is stamped with another engine's fingerprint
     */
    private function resolveBracket(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);

        $resultIndex = $this->indexResults($state->getResults());
        $totalRounds = (int) log($this->bracketSize(count($participants)), 2);

        $slots = $this->buildInitialSlots($participants);
        $levelAdvancers = [];

        for ($round = 1; $round <= $totalRounds; ++$round) {
            // Re-seeded path: after the entry round, survivors are
            // re-ranked by standings and re-folded each round. Only
            // results from earlier rounds may inform the ranking - the
            // replay must reproduce the same pairing before and after the
            // round's own results are recorded.
            if ($this->options->reseedEachRound && $round > 1) {
                $survivors = array_values(array_filter($slots, fn(?Participant $slot) => $slot !== null));
                $slots = $this->buildInitialSlots($this->rankByStandings($survivors, $state, $round, $levelAdvancers));
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
                        ['round' => $round, 'resolved' => $resolved, 'playable' => count($playable)],
                        reason: InvalidConfigurationReason::RoundPartiallyResolved
                    );
                }

                return ['pending' => $this->buildRoundPairing($round, $totalRounds, $pairs), 'levelAdvancers' => $levelAdvancers];
            }

            $nextSlots = [];
            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $advancer = $this->lookupAdvancer($resultIndex, $round, $pair[0], $pair[1], $this->options->legsPerTie);
                    $this->noteLevelAdvancer($levelAdvancers, $resultIndex, $round, $pair[0], $pair[1], $this->options->legsPerTie, $advancer);
                    $nextSlots[] = $advancer;
                } else {
                    $nextSlots[] = $pair[0] ?? $pair[1] ?? null;
                }
            }

            $slots = $nextSlots;
        }

        return ['pending' => null, 'levelAdvancers' => $levelAdvancers];
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
     * across replays regardless of what has been recorded since. A level
     * event of an earlier round counts as a win for whoever advanced from
     * it (see resultsForStandings()).
     *
     * @param array<Participant> $survivors
     * @param array<string, Participant> $levelAdvancers
     * @return array<Participant>
     */
    private function rankByStandings(array $survivors, StageState $state, int $beforeRound, array $levelAdvancers): array
    {
        $priorResults = array_values(array_filter(
            $state->getResults(),
            fn($result) => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $beforeRound
        ));

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $this->resultsForStandings($priorResults, $levelAdvancers)
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
