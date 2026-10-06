<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Override;
use Random\Randomizer;

/**
 * Pairs Swiss rounds incrementally from the recorded stage state.
 *
 * Unlike whole-schedule generators, Swiss pairings depend on results: each
 * round is paired from the current standings. This engine implements
 * Monrad-style pairing - participants ordered by standings and paired
 * adjacently (leader vs runner-up, and so on) - with backtracking to avoid
 * repeat pairings and satisfy constraints. Byes rotate to the lowest-placed
 * participant who has had the fewest, and home/away roles go to whichever
 * participant has had fewer home assignments.
 *
 * Byes recorded on the state are credited as wins when computing the
 * pairing order (the Swiss convention), so a bye recipient is paired among
 * the winners in the following round even though no Result exists for the
 * bye.
 *
 * Withdrawals are supported via StageState::withoutParticipant(): the
 * participant leaves the active list, and their recorded pairings and
 * results still count toward the remaining participants' standings.
 *
 * Repeat avoidance reads the pairings recorded on the state, not the
 * results - so driving the engine while recording no results produces
 * random non-repeat pairing (with a Randomizer), which is the
 * whole-schedule Swiss preset SwissScheduler wraps.
 *
 * Constraints that reason about the tournament length (e.g.
 * SeedProtectionConstraint) need to know the planned number of rounds;
 * provide it via the plannedRounds constructor argument.
 */
readonly class SwissPairingEngine implements StageEngineInterface
{
    /**
     * @param int|null $plannedRounds Total rounds the tournament will run, exposed to
     *                                constraints via the stage plan on the scheduling context;
     *                                null leaves the stage open-ended (isComplete() only
     *                                becomes true when too few participants remain)
     * @param Randomizer|null $randomizer Shuffles pairing order within equal-ranking groups;
     *                                    with no recorded rounds the whole field ties at zero,
     *                                    so the entire pairing order is shuffled
     * @throws InvalidInputException When the planned rounds are below 1
     */
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private StandingsCalculator $standingsCalculator = new StandingsCalculator(),
        private ?int $plannedRounds = null,
        private ?Randomizer $randomizer = null
    ) {
        if ($plannedRounds !== null && $plannedRounds < 1) {
            throw new InvalidInputException('Planned rounds must be at least 1');
        }
    }

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp: the format and its planned rounds, as
     * `swiss:planned-rounds=5`, or `swiss:planned-rounds=none` for an
     * open-ended stage.
     *
     * Compare the string; do not parse it. The constraints, the standings
     * calculator and the randomizer are objects the engine cannot name
     * and are not part of it. See StageState::withEngineFingerprint().
     */
    public function getFingerprint(): string
    {
        return 'swiss:planned-rounds=' . ($this->plannedRounds ?? 'none');
    }

    /**
     * The shape declaration for this stage: rounds when planned, no legs.
     *
     * The plan covers every participant the stage has seen - active ones
     * plus withdrawn participants still referenced by recorded rounds.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): SwissPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new SwissPlan($state->getAllSeenParticipants(), $this->plannedRounds);
    }

    /**
     * Pair the next round from the recorded state.
     *
     * @throws InvalidConfigurationException When fewer than 2 active participants remain, or
     *                                       the state is stamped with another engine's fingerprint
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);

        $roundNumber = $state->getNextRoundNumber();
        $results = $state->getResults();

        // Include withdrawn participants referenced by results so their games
        // still count toward standings, then keep only active participants
        // for pairing.
        $activeIds = [];
        foreach ($participants as $participant) {
            $activeIds[$participant->getId()] = true;
        }

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $results
        );
        $activeEntries = array_values(array_filter(
            $standings->getEntries(),
            fn($entry) => isset($activeIds[$entry->getParticipant()->getId()])
        ));
        $orderedParticipants = $this->orderForPairing($activeEntries, $state->getByeIds());

        // Repeat avoidance and home balancing read the recorded pairings,
        // not the results, so rounds recorded without results still count.
        $playedEvents = $state->getPlayedEvents();
        $playedPairings = $this->collectPlayedPairings($playedEvents);
        $homeCounts = $this->collectHomeCounts($playedEvents);

        $plan = new SwissPlan($state->getAllSeenParticipants(), $this->plannedRounds);
        $context = new SchedulingContext($participants, $plan, $playedEvents);

        if (count($orderedParticipants) % 2 === 0) {
            $events = $this->pairOrderedParticipants(
                $orderedParticipants,
                $playedPairings,
                $homeCounts,
                $context,
                $roundNumber
            );

            if ($events !== null) {
                return new RoundPairing($roundNumber, null, $events);
            }

            throw new NoValidPairingException($roundNumber, $participants);
        }

        foreach ($this->orderByeCandidates($orderedParticipants, $state->getByeIds()) as $byeCandidate) {
            $remaining = array_values(array_filter(
                $orderedParticipants,
                fn(Participant $participant) => $participant->getId() !== $byeCandidate->getId()
            ));

            $events = $this->pairOrderedParticipants(
                $remaining,
                $playedPairings,
                $homeCounts,
                $context,
                $roundNumber
            );

            if ($events !== null) {
                return new RoundPairing($roundNumber, null, $events, [$byeCandidate]);
            }
        }

        throw new NoValidPairingException($roundNumber, $participants);
    }

    /**
     * A Swiss stage is complete when its planned rounds have all been
     * played, or when too few active participants remain to pair another
     * round. An open-ended stage (no planned rounds) with enough
     * participants never reports complete - the application decides when
     * to stop, or pairNextRound() throws when no valid pairing remains.
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        if (count($state->getParticipants()) < 2) {
            return true;
        }

        return $this->plannedRounds !== null
            && $state->getNextRoundNumber() > $this->plannedRounds;
    }

    /**
     * The uniform completion product; null while the stage is unfinished.
     *
     * Standings cover every participant the stage has seen, including
     * withdrawn ones - their played games remain part of the record.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       the state is stamped with another engine's fingerprint
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
     * ID uniqueness is StageState's invariant (start() validates it and
     * every transition preserves it), so only the size needs checking.
     *
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Swiss pairing requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }
    }

    /**
     * @param array<Event> $playedEvents
     * @return array<string, bool>
     */
    private function collectPlayedPairings(array $playedEvents): array
    {
        $playedPairings = [];
        foreach ($playedEvents as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) === 2) {
                $playedPairings[$this->pairingKey($eventParticipants[0], $eventParticipants[1])] = true;
            }
        }

        return $playedPairings;
    }

    /**
     * @param array<Event> $playedEvents
     * @return array<string, int>
     */
    private function collectHomeCounts(array $playedEvents): array
    {
        $homeCounts = [];
        foreach ($playedEvents as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) === 2) {
                $homeId = $eventParticipants[0]->getId();
                $homeCounts[$homeId] = ($homeCounts[$homeId] ?? 0) + 1;
            }
        }

        return $homeCounts;
    }

    /**
     * Order participants for pairing: standings order, with previous byes
     * credited as wins (the Swiss convention) so bye recipients pair among
     * the winners, and - when a randomizer is configured - shuffled within
     * each score group (see groupLevelRankings() for what level means).
     *
     * Crediting a bye "as a win" is only meaningful under a win/draw/loss
     * ranking, so byes require the standings calculator to use a
     * WinDrawLossRanking; anything else fails loudly rather than guessing
     * what a win is worth under an unknown ranking scale.
     *
     * @param array<\MissionGaming\Tactician\Standings\StandingEntry> $entries Standings entries, best first
     * @param array<string> $previousByeIds
     * @return array<Participant>
     * @throws InvalidConfigurationException When byes were awarded but the ranking strategy is not a WinDrawLossRanking
     */
    private function orderForPairing(array $entries, array $previousByeIds): array
    {
        $byeCounts = array_count_values($previousByeIds);

        $winValue = 0.0;
        if ($byeCounts !== []) {
            $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
            if (!$rankingStrategy instanceof WinDrawLossRanking) {
                throw new InvalidConfigurationException(
                    'Swiss bye crediting requires a win/draw/loss ranking strategy; the Swiss convention of counting a bye as a win is undefined under other ranking scales',
                    ['ranking_strategy' => $rankingStrategy::class],
                    reason: InvalidConfigurationReason::IncompatibleOptions
                );
            }

            $winValue = $rankingStrategy->getWinValue();
        }

        $indexed = [];
        foreach ($entries as $index => $entry) {
            $byeCount = $byeCounts[$entry->getParticipant()->getId()] ?? 0;
            $indexed[] = [
                'participant' => $entry->getParticipant(),
                'ranking_value' => $entry->getRankingValue() + $byeCount * $winValue,
                'index' => $index,
            ];
        }

        usort(
            $indexed,
            fn(array $first, array $second): int => ($second['ranking_value'] <=> $first['ranking_value'])
                ?: ($first['index'] <=> $second['index'])
        );

        $tolerance = $this->levelTolerance($entries, $byeCounts);

        $ordered = [];
        foreach ($this->groupLevelRankings($indexed, $tolerance) as $group) {
            // Within a group the table decides, not the last bits of a sum.
            usort($group, fn(array $first, array $second): int => $first['index'] <=> $second['index']);

            if ($this->randomizer !== null) {
                $group = $this->randomizer->shuffleArray($group);
            }

            foreach ($group as $entry) {
                $ordered[] = $entry['participant'];
            }
        }

        return $ordered;
    }

    /**
     * How far apart two ranking values may be and still be level: the most
     * that the rounding of float sums can put between two totals that are
     * the same total. Zero means level is equal.
     *
     * Only a WinDrawLossRanking gets a tolerance, because only there does
     * the engine know how a value was made: a sum of one configured value
     * per result (the class is final, so there is no subclass to consider).
     * Any other RankingStrategy is compared exactly: its values are its
     * own, and two that differ are two different scores however close.
     *
     * The bound. Let M be the largest magnitude of the win, draw and loss
     * values, n the most terms any participant's total has (results played
     * plus byes credited), and u = PHP_FLOAT_EPSILON / 2 the unit roundoff.
     *
     * - Adding k floats in sequence is off by at most (k - 1) * u * S,
     *   where S is the sum of their magnitudes and S <= k * M (the
     *   standard bound for recursive summation, to first order in u).
     * - Crediting b byes multiplies once (off by at most u * b * M) and
     *   adds once (off by at most u * (k + b) * M).
     * - Together, with n = k + b: (k - 1) * k + b + n <= n * n, so one
     *   total is off by at most n * n * u * M.
     * - Two totals of the same results are therefore at most
     *   2 * n * n * u * M apart. The tolerance is twice that,
     *   2 * n * n * PHP_FLOAT_EPSILON * M: the other half covers the
     *   terms of higher order in u and the rounding of the configured
     *   values themselves (0.1 is not a tenth), so three draws at 0.1
     *   are level with one win at 0.3.
     *
     * For 3/1/0 over 11 rounds that is 1.6e-13: sums of whole and half
     * points are exact, so nothing but equal values is ever inside it.
     *
     * The cap. A total that differs from another by a real result is one
     * step away: a result more (a win, draw or loss value that is not
     * zero) or a result changed (the difference of two of them). The
     * tolerance never exceeds a quarter of the smallest such step, so on a
     * scale so stretched that the bound above would reach a step (a win
     * worth 1e15 draws), totals a real result apart still fall in
     * different groups, exactly as an exact comparison has them.
     *
     * @param array<\MissionGaming\Tactician\Standings\StandingEntry> $entries
     * @param array<int|string, int> $byeCounts Byes so far, keyed by participant ID
     */
    private function levelTolerance(array $entries, array $byeCounts): float
    {
        $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
        if (!$rankingStrategy instanceof WinDrawLossRanking) {
            return 0.0;
        }

        $terms = 0;
        foreach ($entries as $entry) {
            $terms = max($terms, $entry->getPlayed() + ($byeCounts[$entry->getParticipant()->getId()] ?? 0));
        }

        $win = $rankingStrategy->getWinValue();
        $draw = $rankingStrategy->getDrawValue();
        $loss = $rankingStrategy->getLossValue();

        $tolerance = 2.0 * $terms * $terms * PHP_FLOAT_EPSILON * max(abs($win), abs($draw), abs($loss));

        foreach ([$win, $draw, $loss, $win - $draw, $draw - $loss, $win - $loss] as $step) {
            if ($step !== 0.0) {
                $tolerance = min($tolerance, abs($step) / 4.0);
            }
        }

        // A value that is not a finite number has no rounding to allow for.
        return is_finite($tolerance) ? $tolerance : 0.0;
    }

    /**
     * Split entries into score groups: runs of participants who are level.
     *
     * A win/draw/loss ranking value is a float sum, and the sum of values a
     * float cannot hold exactly depends on the order of its terms: at 1 for
     * a win and 0.1 for a draw, win-draw-draw is 1.2000000000000002 and
     * draw-draw-win is 1.2. Two values are therefore level when they are
     * equal or no further apart than the tolerance (see levelTolerance();
     * zero for every ranking strategy but WinDrawLossRanking). A group is
     * measured from its highest value, so a chain of near-equal values
     * cannot stretch it.
     *
     * Scoring that floats hold exactly (3/1/0, 1/0.5/0) is unaffected:
     * there, level means equal. With no recorded rounds every participant
     * ties at zero, so a randomizer shuffles the whole field - which is
     * what makes results-free driving produce random non-repeat pairings.
     *
     * @param array<array{participant: Participant, ranking_value: float, index: int}> $indexed Ordered best first
     * @return list<list<array{participant: Participant, ranking_value: float, index: int}>>
     */
    private function groupLevelRankings(array $indexed, float $tolerance): array
    {
        $groups = [];
        $group = [];
        $groupValue = 0.0;

        foreach ($indexed as $entry) {
            // INF and NAN are never within a tolerance of anything: the
            // difference is INF or NAN, and neither is <= a finite number.
            $level = $entry['ranking_value'] === $groupValue
                || abs($groupValue - $entry['ranking_value']) <= $tolerance;

            if ($group !== [] && !$level) {
                $groups[] = $group;
                $group = [];
            }
            if ($group === []) {
                $groupValue = $entry['ranking_value'];
            }
            $group[] = $entry;
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Order bye candidates by fewest previous byes, then lowest standing.
     *
     * @param array<Participant> $orderedParticipants Participants in standings order
     * @param array<string> $previousByeIds
     * @return array<Participant>
     */
    private function orderByeCandidates(array $orderedParticipants, array $previousByeIds): array
    {
        $byeCounts = array_count_values($previousByeIds);

        $candidates = array_reverse($orderedParticipants); // lowest standing first
        $positions = [];
        foreach ($candidates as $index => $candidate) {
            $positions[$candidate->getId()] = $index;
        }

        usort(
            $candidates,
            fn(Participant $first, Participant $second): int => (($byeCounts[$first->getId()] ?? 0) <=> ($byeCounts[$second->getId()] ?? 0))
                ?: ($positions[$first->getId()] <=> $positions[$second->getId()])
        );

        return $candidates;
    }

    /**
     * Pair participants adjacently in standings order, backtracking past
     * repeat pairings and constraint rejections.
     *
     * @param array<Participant> $orderedParticipants
     * @param array<string, bool> $playedPairings
     * @param array<string, int> $homeCounts
     * @param array<Event> $roundEvents
     * @return array<Event>|null Complete pairings for the round, or null if none exist
     */
    private function pairOrderedParticipants(
        array $orderedParticipants,
        array $playedPairings,
        array $homeCounts,
        SchedulingContext $context,
        int $roundNumber,
        array $roundEvents = []
    ): ?array {
        if ($orderedParticipants === []) {
            return $roundEvents;
        }

        $participant = array_shift($orderedParticipants);

        foreach (array_keys($orderedParticipants) as $candidateIndex) {
            $opponent = $orderedParticipants[$candidateIndex];

            if (isset($playedPairings[$this->pairingKey($participant, $opponent)])) {
                continue;
            }

            $event = $this->createEvent($participant, $opponent, $homeCounts, $roundNumber);
            $eventContext = $context->withEvents($roundEvents);
            if ($this->constraints !== null && !$this->constraints->isSatisfied($event, $eventContext)) {
                continue;
            }

            $remaining = $orderedParticipants;
            unset($remaining[$candidateIndex]);

            $pairings = $this->pairOrderedParticipants(
                array_values($remaining),
                $playedPairings,
                $homeCounts,
                $context,
                $roundNumber,
                [...$roundEvents, $event]
            );

            if ($pairings !== null) {
                return $pairings;
            }
        }

        return null;
    }

    /**
     * Create the event with home going to whichever participant has had
     * fewer home assignments (the lower-placed participant on a tie).
     *
     * @param array<string, int> $homeCounts
     */
    private function createEvent(
        Participant $higherPlaced,
        Participant $lowerPlaced,
        array $homeCounts,
        int $roundNumber
    ): Event {
        $higherHomes = $homeCounts[$higherPlaced->getId()] ?? 0;
        $lowerHomes = $homeCounts[$lowerPlaced->getId()] ?? 0;

        $participants = $higherHomes < $lowerHomes
            ? [$higherPlaced, $lowerPlaced]
            : [$lowerPlaced, $higherPlaced];

        return new Event($participants, new Round($roundNumber));
    }

    private function pairingKey(Participant $firstParticipant, Participant $secondParticipant): string
    {
        return PairKey::of($firstParticipant->getId(), $secondParticipant->getId());
    }
}
