<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
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
     * How far apart two ranking values may be and still be level: a
     * billionth of the larger value, and never less than a billionth.
     * Far above the rounding error of a float sum, far below any step a
     * scoring system awards.
     */
    private const float LEVEL_TOLERANCE = 1.0e-9;

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
     * The shape declaration for this stage: rounds when planned, no legs.
     *
     * The plan covers every participant the stage has seen - active ones
     * plus withdrawn participants still referenced by recorded rounds.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen
     */
    #[Override]
    public function getPlan(StageState $state): SwissPlan
    {
        return new SwissPlan($state->getAllSeenParticipants(), $this->plannedRounds);
    }

    /**
     * Pair the next round from the recorded state.
     *
     * @throws InvalidConfigurationException When fewer than 2 active participants remain
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
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
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
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
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen
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
                ['participant_count' => count($participants), 'minimum_required' => 2]
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
                    ['ranking_strategy' => $rankingStrategy::class]
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

        $ordered = [];
        foreach ($this->groupLevelRankings($indexed) as $group) {
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
     * Split entries into score groups: runs of participants who are level.
     *
     * A ranking value is a float sum, and the sum of values a float cannot
     * hold exactly depends on the order of its terms: at 1 for a win and
     * 0.1 for a draw, win-draw-draw is 1.2000000000000002 and
     * draw-draw-win is 1.2. Two values are therefore level when they
     * differ by no more than LEVEL_TOLERANCE, relative to the larger of
     * them and never less than absolutely. A group is measured from its
     * highest value, so a chain of near-equal values cannot stretch it.
     *
     * Scoring that floats hold exactly (3/1/0, 1/0.5/0) is unaffected:
     * there, level means equal. With no recorded rounds every participant
     * ties at zero, so a randomizer shuffles the whole field - which is
     * what makes results-free driving produce random non-repeat pairings.
     *
     * @param array<array{participant: Participant, ranking_value: float, index: int}> $indexed Ordered best first
     * @return list<list<array{participant: Participant, ranking_value: float, index: int}>>
     */
    private function groupLevelRankings(array $indexed): array
    {
        $groups = [];
        $group = [];
        $groupValue = 0.0;

        foreach ($indexed as $entry) {
            if ($group !== [] && !$this->isLevel($groupValue, $entry['ranking_value'])) {
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

    private function isLevel(float $first, float $second): bool
    {
        if ($first === $second) {
            return true;
        }

        return abs($first - $second) <= self::LEVEL_TOLERANCE * max(1.0, abs($first), abs($second));
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
