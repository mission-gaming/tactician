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
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
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
 *   meet as late as possible. A bye counts in that ranking as the tie it
 *   stands for, won in every leg, and survivors who are level are in the
 *   order of the participant list: in a field of five, positions 1 and 2
 *   are in opposite halves of the semifinals.
 *
 * Ties are played over one event or two mirrored legs (legsPerTie). A tie
 * that finishes level - a drawn single event, or two legs that do not
 * decide - is the application's to decide and is recorded as a tie
 * decision on the result (see TieDecision); a level tie without one is
 * refused by every call.
 *
 * An awarded result (DTO\Result::isAwarded()) is the result of its event:
 * the winner it names advances from a single-leg tie, and in a two-legged
 * tie it is a leg won, so a tie that is not played at all is settled by
 * an awarded result for each leg. One that names no winner leaves the tie
 * level, like a draw, and the tie decision is then needed. Adjustments
 * recorded on the state (StageState::withAdjustment()) are in the ranking
 * values of every table the engine builds: they order the entrants a round
 * put out together and the survivors of a re-seeded round, and never
 * decide who advances.
 *
 * There is deliberately no champion accessor. The participant who
 * advances from the final is rank 1 of the outcome's standings, which are
 * in bracket placement order (see getOutcome()), and is what
 * MatchOutcomeSelector::winners() returns for the outcome's final round.
 *
 * @experimental
 */
final readonly class SingleEliminationEngine implements StageEngineInterface, FingerprintedEngine
{
    use EliminationBracketSupport;

    /**
     * Every option is accepted; grandFinalReset is not read.
     *
     * @param StandingsCalculator $standingsCalculator Computes the records of the outcome's standings
     *                                                 and orders the entrants who went out in the
     *                                                 same round; in a re-seeded bracket it also
     *                                                 orders the survivors before every round after
     *                                                 the first. On a fixed path it pairs nothing
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
     * apart from another of your own), the tiebreakers by name and in
     * order, and each choice of the awarded result policy that is off, or,
     * in their place, that the calculator is a subclass of
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
     *                                       is level without a usable tie decision, a recorded
     *                                       round pairs other participants than the bracket has
     *                                       in it (reason `RecordedRoundMismatch`), a re-seeded
     *                                       bracket has a bye and a ranking strategy that is not
     *                                       a WinDrawLossRanking (`IncompatibleOptions`), or the
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
     * The standings are in bracket placement order, whatever the field
     * size, the byes and the legs per tie: the entrant who advanced from
     * the final is rank 1, the other finalist rank 2, then the two who
     * went out in the semifinals, then the four of the quarter-finals, and
     * so on back to the first round. The bracket decides those tiers
     * (Standings::getTiers()), and no record moves an entrant from one to
     * another: a finalist who had a bye, or who advanced from a two-legged
     * final on a tie decision, is still above the entrant it beat.
     *
     * Entrants who went out in the same round are ordered by the standings
     * calculator from their records (ranking value, tiebreakers, score
     * difference, scores-for), and where that leaves them level, by their
     * position in the participant list. Standings::getTiedSets() reports
     * those. The records are win/draw/loss records over the bracket's
     * results: a bye is no win in them, the legs of a two-legged tie count
     * as recorded, and a single-leg event that finished level and was
     * decided by its tie decision counts as a win for the participant who
     * advanced. The outcome's results are the results as recorded, the
     * draw included.
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

        $entrants = $state->getAllSeenParticipants();

        return new StageOutcome(
            $this->placementStandings(
                $this->standingsCalculator,
                $entrants,
                $this->resultsForStandings($state->getResults(), $resolution['levelAdvancers']),
                $resolution['placement'],
                $state->getAdjustments()
            ),
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound(),
            [],
            $entrants
        );
    }

    /**
     * Replay the bracket from the entry fold through the recorded results.
     *
     * @return array{pending: RoundPairing|null, levelAdvancers: array<string, Participant>, placement: array<int|string, int>}
     *                                                                                                                         The placement is the tier of every
     *                                                                                                                         entrant of a finished bracket, 0 for
     *                                                                                                                         the one who advanced from the final
     *
     * @throws InvalidConfigurationException When a round is partially resolved, a tie is broken, a
     *                                       recorded round is not the round the bracket has there,
     *                                       a bye cannot be credited, or the state is stamped with
     *                                       another engine's fingerprint
     */
    private function resolveBracket(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);
        $this->requireByesToBeCreditable(count($participants));

        $resultIndex = $this->indexResults($state->getResults());
        $totalRounds = (int) log($this->bracketSize(count($participants)), 2);

        $slots = $this->buildInitialSlots($participants);
        $levelAdvancers = [];
        $byes = [];
        $wentOutIn = [];

        for ($round = 1; $round <= $totalRounds; ++$round) {
            // Re-seeded path: after the entry round, survivors are
            // re-ranked by standings and re-folded each round. Only
            // results from earlier rounds may inform the ranking - the
            // replay must reproduce the same pairing before and after the
            // round's own results are recorded.
            if ($this->options->reseedEachRound && $round > 1) {
                $survivors = array_values(array_filter($slots, fn(?Participant $slot) => $slot !== null));
                $slots = $this->buildInitialSlots($this->rankByStandings($survivors, $state, $round, $levelAdvancers, $byes));
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

            $this->requireRecordedRoundToMatch($state, $round, $playable);

            if ($resolved < count($playable)) {
                if ($resolved > 0) {
                    throw new InvalidConfigurationException(
                        "Round {$round} is partially resolved: {$resolved} of " . count($playable)
                            . ' ties have complete results. Record the remaining results before pairing the next round.',
                        ['round' => $round, 'resolved' => $resolved, 'playable' => count($playable)],
                        reason: InvalidConfigurationReason::RoundPartiallyResolved
                    );
                }

                return [
                    'pending' => $this->buildRoundPairing($round, $totalRounds, $pairs),
                    'levelAdvancers' => $levelAdvancers,
                    'placement' => [],
                ];
            }

            $nextSlots = [];
            foreach ($pairs as $pair) {
                [$first, $second] = [$pair[0], $pair[1] ?? null];
                if ($first !== null && $second !== null) {
                    $advancer = $this->lookupAdvancer($resultIndex, $round, $first, $second, $this->options->legsPerTie);
                    $this->noteLevelAdvancer($levelAdvancers, $resultIndex, $round, $first, $second, $this->options->legsPerTie, $advancer);
                    $nextSlots[] = $advancer;
                    $wentOutIn[$advancer?->getId() === $first->getId() ? $second->getId() : $first->getId()] = $round;
                } else {
                    $sitsOut = $first ?? $second;
                    $nextSlots[] = $sitsOut;
                    if ($sitsOut !== null) {
                        $byes[$sitsOut->getId()] = ($byes[$sitsOut->getId()] ?? 0) + 1;
                    }
                }
            }

            $slots = $nextSlots;
        }

        // The entrant who advanced from the final is in the first tier,
        // and everyone else in the tier of the round they went out in:
        // the final's is the second, the semifinals' the third.
        $placement = [];
        foreach ($wentOutIn as $id => $round) {
            $placement[$id] = $totalRounds - $round + 1;
        }
        foreach ($slots as $slot) {
            if ($slot !== null) {
                $placement[$slot->getId()] = 0;
            }
        }

        return ['pending' => null, 'levelAdvancers' => $levelAdvancers, 'placement' => $placement];
    }

    /**
     * Refuse a re-seeded bracket that will have a bye it cannot credit.
     *
     * A field that is not a power of two has byes in its first round, and
     * the ranking before the second round credits each as a tie won, which
     * needs the value of a win (StandingsCalculator::withByesCredited()).
     * The entrant list says all of that before a round is paired, so the
     * bracket is refused then, on every call, and not after its first
     * round has been played.
     *
     * @throws InvalidConfigurationException When the bracket is re-seeded, the field is not a
     *                                       power of two and the ranking strategy is not a
     *                                       WinDrawLossRanking
     */
    private function requireByesToBeCreditable(int $participantCount): void
    {
        if (!$this->options->reseedEachRound || $participantCount === $this->bracketSize($participantCount)) {
            return;
        }

        $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
        if ($rankingStrategy instanceof WinDrawLossRanking) {
            return;
        }

        throw new InvalidConfigurationException(
            "A re-seeded bracket of {$participantCount} entrants has byes, and crediting a bye requires a win/draw/loss"
                . ' ranking strategy: counting a bye as a tie won is undefined under other ranking scales.'
                . ' Use a WinDrawLossRanking, a field that is a power of two, or a bracket on a fixed path',
            ['ranking_strategy' => $rankingStrategy::class, 'participant_count' => $participantCount],
            reason: InvalidConfigurationReason::IncompatibleOptions
        );
    }

    /**
     * Refuse a state whose recorded round is not the round the bracket has
     * at that number: the recorded results would be read against ties that
     * were never played. A state is in that condition when its rounds were
     * paired under other rules than this engine's (another option, or the
     * re-seeding order of an earlier release) or when its participant list
     * changed after a round was recorded. An adjustment recorded after a
     * re-seeded round was paired can do it too, by changing the ranking that
     * round is folded from (see rankByStandings()).
     *
     * @param list<array<Participant|null>> $playable The ties the bracket has in the round
     *
     * @throws InvalidConfigurationException When the recorded round pairs other participants
     */
    private function requireRecordedRoundToMatch(StageState $state, int $round, array $playable): void
    {
        $expected = [];
        foreach ($playable as $pair) {
            if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                $expected[PairKey::of($pair[0]->getId(), $pair[1]->getId())] = true;
            }
        }

        foreach ($state->getRoundsPlayed() as $recorded) {
            if ($recorded->getRoundNumber() !== $round) {
                continue;
            }

            $recordedPairs = [];
            foreach ($recorded->getEvents() as $event) {
                $recordedPairs[PairKey::of(...array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    array_values($event->getParticipants())
                ))] = true;
            }

            // A recorded round may hold more than the bracket's ties (an
            // event the application added is read by nothing); it may not
            // lack one of them.
            if (array_diff_key($expected, $recordedPairs) !== []) {
                throw new InvalidConfigurationException(
                    "The recorded round {$round} is not the round {$round} this bracket has: it pairs other participants."
                        . ' The state was paired under other rules than this engine applies (other options, or the'
                        . ' re-seeding order of an earlier release), or its participant list changed after the round'
                        . ' was recorded. Rebuild the state from the rounds that stand.'
                        . ($this->options->reseedEachRound && $state->getAdjustments() !== []
                            ? ' The state carries adjustments, and a re-seeded bracket ranks its survivors with every'
                                . ' adjustment recorded: one recorded after a round was paired can change that round.'
                            : ''),
                    ['round' => $round, 'recorded' => array_keys($recordedPairs), 'expected' => array_keys($expected)],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }
        }
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
     * A bye is an advance like a tie won, so it is credited as one: a win
     * for each leg of a tie. Without that an entrant who sat out the first
     * round would rank below everyone who played it, and the top of the
     * entrant list would be folded as the bottom of the round. Survivors
     * the table leaves level are in the order of the participant list:
     * no seed attribute, label or ID is read.
     *
     * Every adjustment recorded on the state is in the ranking, whenever
     * it was recorded: an adjustment belongs to no round, so there is no
     * "earlier" to hold it to. Recording one after a round was paired
     * changes how that round is replayed, and the engine then refuses the
     * state because the recorded round is no longer the round it has
     * (`RecordedRoundMismatch`). In a re-seeded bracket, record an
     * adjustment before the round it is to count for is paired.
     *
     * @param array<Participant> $survivors
     * @param array<string, Participant> $levelAdvancers
     * @param array<int|string, int> $byes Byes of the earlier rounds, by participant ID
     * @return array<Participant>
     *
     * @throws InvalidConfigurationException When a survivor had a bye and the ranking strategy is
     *                                       not a WinDrawLossRanking
     */
    private function rankByStandings(
        array $survivors,
        StageState $state,
        int $beforeRound,
        array $levelAdvancers,
        array $byes
    ): array {
        $priorResults = array_values(array_filter(
            $state->getResults(),
            fn($result) => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $beforeRound
        ));

        $entrants = $state->getAllSeenParticipants();
        $standings = $this->standingsCalculator
            ->withAdjustments(
                $this->standingsCalculator->withByesCredited(
                    $this->standingsCalculator->calculate($entrants, $this->resultsForStandings($priorResults, $levelAdvancers)),
                    $byes,
                    $this->options->legsPerTie
                ),
                $state->getAdjustments()
            )
            ->withTiedSetsOrderedBy($entrants);

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
