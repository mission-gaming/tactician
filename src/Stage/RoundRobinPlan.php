<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Shape declaration for a round-robin stage.
 *
 * Every pair of participants meets exactly once per leg, so the plan knows
 * everything up front: rounds per leg (n-1 for even fields, n for odd
 * fields, whose bye adds a round to the rotation), total rounds, expected
 * event counts, and pairwise meeting multiplicities. Validation and
 * diagnostics read these facts from here. Generation reads the legs and the
 * rounds per leg from here, for the round numbers of later legs, but lays
 * out the rounds of a leg from the size of the field itself: nothing makes
 * the two agree by construction, and it is the validation of the finished
 * schedule against the plan that catches a difference.
 *
 * @experimental
 */
final readonly class RoundRobinPlan implements PairwisePlan
{
    /** @var array<Participant> */
    private array $participants;

    /** @var array<string, true> Participant IDs for membership checks */
    private array $participantIds;

    /**
     * The scheduler builds the plan from its options and the leg
     * strategy's contribution; build one by hand to validate a schedule
     * made elsewhere. The last three arguments are facts carried for the
     * plan's readers and change none of its arithmetic.
     *
     * @param array<Participant> $participants The field; IDs are not checked for uniqueness here
     * @param int $legs How many times each participant meets each other participant
     * @param bool $rolesMirrorAcrossLegs Whether the leg strategy reverses event roles in later legs
     * @param bool $requiresRandomization Whether the leg strategy needs a randomizer during generation
     * @param array<string> $warnings Non-fatal notes from plan construction
     * @throws InvalidConfigurationException When legs is below 1 (reason `InvalidLegCount`) or
     *                                       there are fewer than 2 participants
     *                                       (`TooFewParticipants`)
     */
    public function __construct(
        array $participants,
        private int $legs,
        private bool $rolesMirrorAcrossLegs = false,
        private bool $requiresRandomization = false,
        private array $warnings = []
    ) {
        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::InvalidLegCount,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $this->participants = array_values($participants);
        $ids = [];
        foreach ($this->participants as $participant) {
            $ids[$participant->getId()] = true;
        }
        $this->participantIds = $ids;
    }

    /**
     * Always 'round-robin'.
     */
    #[Override]
    public function getAlgorithm(): string
    {
        return 'round-robin';
    }

    /**
     * Rounds per leg × legs. Never null: a round robin knows its length.
     * Round numbers run from 1 to this across the legs.
     */
    #[Override]
    public function getTotalRounds(): int
    {
        return $this->getRoundsPerLeg() * $this->legs;
    }

    /**
     * The number of legs, at least 1. Never null.
     */
    #[Override]
    public function getLegs(): int
    {
        return $this->legs;
    }

    /**
     * Rounds in one leg: n-1 for a field of even size n, n for a field of
     * odd size, where one participant sits out each round. Never null.
     */
    #[Override]
    public function getRoundsPerLeg(): int
    {
        $participantCount = count($this->participants);

        return $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
    }

    /**
     * Events in one leg: every pair meets once, n(n-1)/2.
     */
    public function getEventsPerLeg(): int
    {
        $participantCount = count($this->participants);

        return intdiv($participantCount * ($participantCount - 1), 2);
    }

    /**
     * Events per leg × legs. Never null.
     */
    #[Override]
    public function getExpectedEventCount(): int
    {
        return $this->getEventsPerLeg() * $this->legs;
    }

    /**
     * The number of legs for two different participants of the field; 0
     * when either is not in it or the two have the same ID. Participants
     * are compared by ID.
     */
    #[Override]
    public function getExpectedMeetings(Participant $a, Participant $b): int
    {
        if ($a->getId() === $b->getId()) {
            return 0;
        }

        if (!isset($this->participantIds[$a->getId()]) || !isset($this->participantIds[$b->getId()])) {
            return 0;
        }

        return $this->legs;
    }

    /**
     * The field as a list, in the order given.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * Whether the leg strategy says it reverses event roles in later
     * legs. The plan carries the strategy's statement as given; it does
     * not derive or check it.
     */
    public function rolesMirrorAcrossLegs(): bool
    {
        return $this->rolesMirrorAcrossLegs;
    }

    /**
     * Whether the leg strategy says it needs a randomizer during
     * generation. Carried as given, like rolesMirrorAcrossLegs().
     */
    public function requiresRandomization(): bool
    {
        return $this->requiresRandomization;
    }

    /**
     * The non-fatal notes the plan was constructed with, in the order
     * given; empty when there are none. They are sentences for a person
     * to read.
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Pairings that have no recorded result yet.
     *
     * Progressing from a partial table silently promotes the wrong
     * participants, so check this is empty before selecting qualifiers
     * from a round-robin stage's standings.
     *
     * A pairing counts as played once one result names its two
     * participants, in either order. With two or more legs an empty list
     * therefore does not mean that every leg has been played: a pairing
     * with one result of two is not reported. Results of events that do
     * not have exactly 2 participants are ignored. The list is in field
     * order (the first participant against each later one, then the
     * second, and so on).
     *
     * @param array<\MissionGaming\Tactician\DTO\Result> $results
     * @return array<string> Human-readable 'A vs B' descriptions
     */
    public function findUnplayedPairings(array $results): array
    {
        $playedPairings = [];
        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();
            if (count($eventParticipants) === 2) {
                $playedPairings[$this->pairingKey(
                    $eventParticipants[0]->getId(),
                    $eventParticipants[1]->getId()
                )] = true;
            }
        }

        $unplayed = [];
        $participantCount = count($this->participants);
        for ($i = 0; $i < $participantCount - 1; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                $key = $this->pairingKey($this->participants[$i]->getId(), $this->participants[$j]->getId());
                if (!isset($playedPairings[$key])) {
                    $unplayed[] = $this->participants[$i]->getLabel() . ' vs ' . $this->participants[$j]->getLabel();
                }
            }
        }

        return $unplayed;
    }

    /**
     * Checks that every event has exactly 2 different participants of
     * the field, and that every pairing of the field appears exactly
     * `legs` times, whichever participant is named first. Event
     * violations come first, in schedule order with events numbered from
     * 1; pairing counts follow, in field order.
     *
     * It does not look at rounds: a participant in two events of one
     * round, a round number out of range and the roles within an event
     * are not reported.
     *
     * @return array<string>
     */
    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];
        $participantLabels = [];
        foreach ($this->participants as $participant) {
            $participantLabels[$participant->getId()] = $participant->getLabel();
        }

        $expectedPairings = $this->buildExpectedPairings();
        $actualPairings = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; round robin events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            $firstId = $eventParticipants[0]->getId();
            $secondId = $eventParticipants[1]->getId();

            if ($firstId === $secondId) {
                $violations[] = sprintf('Event %d contains participant %s twice.', $index + 1, $firstId);
                continue;
            }

            if (!isset($participantLabels[$firstId]) || !isset($participantLabels[$secondId])) {
                $violations[] = sprintf(
                    'Event %d contains a participant that is not in the tournament.',
                    $index + 1
                );
                continue;
            }

            $pairingKey = $this->pairingKey($firstId, $secondId);
            $actualPairings[$pairingKey] = ($actualPairings[$pairingKey] ?? 0) + 1;
        }

        foreach ($expectedPairings as $pairingKey => [$firstId, $secondId]) {
            $actualCount = $actualPairings[$pairingKey] ?? 0;
            $expectedCount = $this->legs;
            if ($actualCount !== $expectedCount) {
                $violations[] = sprintf(
                    'Pairing %s vs %s appears %d time(s), expected %d.',
                    $participantLabels[$firstId],
                    $participantLabels[$secondId],
                    $actualCount,
                    $expectedCount
                );
            }
        }

        return $violations;
    }

    /**
     * Every pairing the plan expects, each meeting `legs` times: the two
     * ids in key order, under the pairing's key. The ids are kept beside
     * the key because a key is not meant to be read back.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function buildExpectedPairings(): array
    {
        $expectedPairings = [];
        $participantCount = count($this->participants);

        for ($i = 0; $i < $participantCount - 1; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                [$firstId, $secondId] = PairKey::order([
                    $this->participants[$i]->getId(),
                    $this->participants[$j]->getId(),
                ]);
                $expectedPairings[PairKey::join([$firstId, $secondId])] = [$firstId, $secondId];
            }
        }

        return $expectedPairings;
    }

    private function pairingKey(string $firstId, string $secondId): string
    {
        return PairKey::of($firstId, $secondId);
    }
}
