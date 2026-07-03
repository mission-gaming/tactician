<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Standings\StandingsCalculator;

/**
 * Pairs Swiss rounds incrementally from recorded results.
 *
 * Unlike whole-schedule generators, Swiss pairings depend on results: each
 * round is paired from the current standings. This engine implements
 * Monrad-style pairing - participants ordered by standings and paired
 * adjacently (leader vs runner-up, and so on) - with backtracking to avoid
 * repeat pairings and satisfy constraints. Byes rotate to the lowest-placed
 * participant who has had the fewest, and home/away roles go to whichever
 * participant has had fewer home assignments.
 *
 * Byes recorded in previousByeIds are credited as wins when computing the
 * pairing order (the Swiss convention), so a bye recipient is paired among
 * the winners in the following round even though no Result exists for the
 * bye.
 */
readonly class SwissPairingEngine
{
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
    }

    /**
     * Pair the next round from the given results.
     *
     * @param array<Participant> $participants Active participants to pair
     * @param array<Result> $results Results of every round played so far
     * @param array<string> $previousByeIds Participant IDs that already received a bye (repeat an ID for multiple byes)
     * @param int|null $roundNumber The round to pair; derived from the results when null
     *
     * @throws InvalidConfigurationException When the inputs are malformed
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    public function pairNextRound(
        array $participants,
        array $results,
        array $previousByeIds = [],
        ?int $roundNumber = null
    ): SwissRoundPairing {
        $participants = array_values($participants);
        $this->validateParticipants($participants);

        $roundNumber ??= $this->deriveNextRoundNumber($results);

        $standings = $this->standingsCalculator->calculate($participants, $results);
        $orderedParticipants = $this->orderForPairing($standings->getEntries(), $previousByeIds);

        $playedPairings = $this->collectPlayedPairings($results);
        $homeCounts = $this->collectHomeCounts($results);
        $priorEvents = array_map(fn (Result $result) => $result->getEvent(), $results);
        $context = new SchedulingContext($participants, $priorEvents, 1, 1, 2, ['algorithm' => 'swiss']);

        if (count($orderedParticipants) % 2 === 0) {
            $events = $this->pairOrderedParticipants(
                $orderedParticipants,
                $playedPairings,
                $homeCounts,
                $context,
                $roundNumber
            );

            if ($events !== null) {
                return new SwissRoundPairing($roundNumber, $events);
            }

            throw new NoValidPairingException($roundNumber, $participants);
        }

        foreach ($this->orderByeCandidates($orderedParticipants, $previousByeIds) as $byeCandidate) {
            $remaining = array_values(array_filter(
                $orderedParticipants,
                fn (Participant $participant) => $participant->getId() !== $byeCandidate->getId()
            ));

            $events = $this->pairOrderedParticipants(
                $remaining,
                $playedPairings,
                $homeCounts,
                $context,
                $roundNumber
            );

            if ($events !== null) {
                return new SwissRoundPairing($roundNumber, $events, $byeCandidate);
            }
        }

        throw new NoValidPairingException($roundNumber, $participants);
    }

    /**
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

        $ids = array_map(fn (Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
            );
        }
    }

    /**
     * @param array<Result> $results
     */
    private function deriveNextRoundNumber(array $results): int
    {
        $maxRound = 0;
        foreach ($results as $result) {
            $round = $result->getEvent()->getRound()?->getNumber() ?? 0;
            $maxRound = max($maxRound, $round);
        }

        return $maxRound + 1;
    }

    /**
     * @param array<Result> $results
     * @return array<string, bool>
     */
    private function collectPlayedPairings(array $results): array
    {
        $playedPairings = [];
        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();
            if (count($eventParticipants) === 2) {
                $playedPairings[$this->pairingKey($eventParticipants[0], $eventParticipants[1])] = true;
            }
        }

        return $playedPairings;
    }

    /**
     * @param array<Result> $results
     * @return array<string, int>
     */
    private function collectHomeCounts(array $results): array
    {
        $homeCounts = [];
        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();
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
     * the winners.
     *
     * @param array<\MissionGaming\Tactician\Standings\StandingEntry> $entries Standings entries, best first
     * @param array<string> $previousByeIds
     * @return array<Participant>
     */
    private function orderForPairing(array $entries, array $previousByeIds): array
    {
        $byeCounts = array_count_values($previousByeIds);
        if ($byeCounts === []) {
            return array_map(fn ($entry) => $entry->getParticipant(), $entries);
        }

        $winPoints = $this->standingsCalculator->getPointsSystem()->getWinPoints();

        $indexed = [];
        foreach ($entries as $index => $entry) {
            $byeCount = $byeCounts[$entry->getParticipant()->getId()] ?? 0;
            $indexed[] = [
                'participant' => $entry->getParticipant(),
                'points' => $entry->getPoints() + $byeCount * $winPoints,
                'index' => $index,
            ];
        }

        usort(
            $indexed,
            fn (array $first, array $second): int => ($second['points'] <=> $first['points'])
                ?: ($first['index'] <=> $second['index'])
        );

        return array_map(fn (array $entry) => $entry['participant'], $indexed);
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
            fn (Participant $first, Participant $second): int => (($byeCounts[$first->getId()] ?? 0) <=> ($byeCounts[$second->getId()] ?? 0))
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
        $ids = [$firstParticipant->getId(), $secondParticipant->getId()];
        sort($ids);

        return implode('|', $ids);
    }
}
