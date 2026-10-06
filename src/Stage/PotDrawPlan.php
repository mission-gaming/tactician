<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Shape declaration for a pot draw.
 *
 * The entrants are cut into pots of equal size in list order: the first
 * block is pot 1, the next pot 2, and so on. List position is the seeding;
 * no seed attribute is read. Every entrant meets the same number of
 * opponents from every pot, its own included, never the same opponent
 * twice, and is in every round. So the shape is known before anything is
 * drawn: pots × opponents per pot rounds, each with half the field in
 * events.
 *
 * Which opponents an entrant draws depends on the seed, so the plan cannot
 * say how often two given entrants meet (it is zero or one), and it is
 * deliberately not a {@see PairwisePlan}. The format is pairwise all the
 * same: validateIntegrity() reports an event that does not have exactly two
 * participants as a violation.
 *
 * A configuration is feasible only if all four hold, and the constructor
 * refuses anything else with a reason of its own:
 *
 * 1. the number of entrants is even (every entrant is in every round, and
 *    no bye is issued);
 * 2. the entrants divide into pots of equal size;
 * 3. opponents per pot is at most pot size minus one;
 * 4. pot size × opponents per pot is even (the events inside one pot
 *    number half of that product).
 *
 * The plan describes every feasible configuration, including those
 * `Scheduling\PotDrawScheduler` cannot draw yet, so a schedule made
 * elsewhere can be validated against it.
 *
 * @experimental
 */
final readonly class PotDrawPlan implements StagePlan
{
    /** @var list<Participant> */
    private array $participants;

    /** @var array<string, int> Participant ID to 1-based pot number */
    private array $potByParticipantId;

    private int $potSize;

    /**
     * @param array<Participant> $participants The entrants in seeding order
     * @param int $pots How many pots the entrants are cut into
     * @param int $opponentsPerPot How many opponents every entrant meets from each pot, its own included
     * @throws InvalidConfigurationException When the numbers cannot form a pot draw; the reason
     *                                       says which rule failed and the context carries the numbers
     */
    public function __construct(
        array $participants,
        private int $pots,
        private int $opponentsPerPot
    ) {
        $participants = array_values($participants);
        $count = count($participants);

        if ($pots < 1) {
            throw new InvalidConfigurationException(
                'Pots must be a positive integer',
                ['pots' => $pots, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($opponentsPerPot < 1) {
            throw new InvalidConfigurationException(
                'Opponents per pot must be a positive integer',
                ['opponents_per_pot' => $opponentsPerPot, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($count < 2) {
            throw new InvalidConfigurationException(
                'A pot draw requires at least 2 participants',
                ['participant_count' => $count, 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }

        $ids = array_map(fn(Participant $participant): string => $participant->getId(), $participants);
        $uniqueIds = count(array_unique($ids));
        if ($uniqueIds !== $count) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => $count, 'unique_ids' => $uniqueIds],
                reason: InvalidConfigurationReason::DuplicateParticipantIds
            );
        }

        if ($count % 2 !== 0) {
            throw new InvalidConfigurationException(
                'A pot draw has every participant in every round and issues no bye, so it needs an even number of participants',
                ['participant_count' => $count],
                reason: InvalidConfigurationReason::OddParticipantCount
            );
        }

        if ($count % $pots !== 0) {
            throw new InvalidConfigurationException(
                'The participants do not divide into pots of equal size',
                ['participant_count' => $count, 'pots' => $pots, 'remainder' => $count % $pots],
                reason: InvalidConfigurationReason::UnequalPots
            );
        }

        $potSize = intdiv($count, $pots);

        if ($opponentsPerPot > $potSize - 1) {
            throw new InvalidConfigurationException(
                'A participant cannot meet more opponents from a pot than its own pot has other members',
                [
                    'opponents_per_pot' => $opponentsPerPot,
                    'pot_size' => $potSize,
                    'maximum_opponents_per_pot' => $potSize - 1,
                ],
                reason: InvalidConfigurationReason::TooManyOpponentsPerPot
            );
        }

        if (($potSize * $opponentsPerPot) % 2 !== 0) {
            throw new InvalidConfigurationException(
                'A pot of odd size cannot hold an odd number of events per member inside itself',
                [
                    'pot_size' => $potSize,
                    'opponents_per_pot' => $opponentsPerPot,
                    'participant_slots_inside_one_pot' => $potSize * $opponentsPerPot,
                ],
                reason: InvalidConfigurationReason::OddPotWithOddOpponents
            );
        }

        $potByParticipantId = [];
        foreach ($ids as $position => $id) {
            $potByParticipantId[$id] = intdiv($position, $potSize) + 1;
        }

        $this->participants = $participants;
        $this->potByParticipantId = $potByParticipantId;
        $this->potSize = $potSize;
    }

    /**
     * Always 'pot-draw'.
     */
    #[Override]
    public function getAlgorithm(): string
    {
        return 'pot-draw';
    }

    /**
     * Pots × opponents per pot: every entrant has one event in every round,
     * and that many events in all.
     */
    #[Override]
    public function getTotalRounds(): int
    {
        return $this->pots * $this->opponentsPerPot;
    }

    /**
     * A pot draw has no legs concept; always null.
     */
    #[Override]
    public function getLegs(): ?int
    {
        return null;
    }

    /**
     * A pot draw has no legs concept; always null.
     */
    #[Override]
    public function getRoundsPerLeg(): ?int
    {
        return null;
    }

    /**
     * Events in one round: half the field, because every entrant is in
     * every round.
     */
    public function getEventsPerRound(): int
    {
        return intdiv(count($this->participants), 2);
    }

    /**
     * Events per round × total rounds. Never null: nothing about the
     * shape depends on the draw.
     */
    #[Override]
    public function getExpectedEventCount(): int
    {
        return $this->getEventsPerRound() * $this->getTotalRounds();
    }

    /**
     * Events every entrant has across the stage: one per round.
     */
    public function getEventsPerParticipant(): int
    {
        return $this->getTotalRounds();
    }

    /**
     * How many pots the entrants are cut into, at least 1.
     */
    public function getPots(): int
    {
        return $this->pots;
    }

    /**
     * How many entrants each pot holds: the same for every pot.
     */
    public function getPotSize(): int
    {
        return $this->potSize;
    }

    /**
     * How many opponents every entrant meets from each pot, its own
     * included: at least 1 and at most the pot size minus one.
     */
    public function getOpponentsPerPot(): int
    {
        return $this->opponentsPerPot;
    }

    /**
     * @return list<Participant> The entrants in seeding order
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * The members of one pot, in seeding order.
     *
     * @param int $pot 1-based pot number
     * @return list<Participant> Empty when the stage has no such pot
     */
    public function getPotMembers(int $pot): array
    {
        if ($pot < 1 || $pot > $this->pots) {
            return [];
        }

        return array_slice($this->participants, ($pot - 1) * $this->potSize, $this->potSize);
    }

    /**
     * The pot an entrant is in.
     *
     * @return int|null 1-based pot number, or null when the participant is not part of the stage
     */
    public function getPotOf(Participant $participant): ?int
    {
        return $this->potByParticipantId[$participant->getId()] ?? null;
    }

    /**
     * Checks a complete schedule against the format, not only its counts:
     * every event has two entrants of the stage and a round of the stage;
     * every entrant is in every round exactly once; no pairing repeats;
     * every entrant has exactly the configured number of opponents from
     * every pot, its own included; an entrant's two role counts differ by
     * at most one, and with an even number of opponents per pot they are
     * equal against every pot.
     *
     * @return array<string> Event violations in schedule order, with events numbered from 1,
     *                       then rounds, then entrants in seeding order
     */
    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];
        $totalRounds = $this->getTotalRounds();
        $roundParticipantIds = [];
        $pairingSeen = [];
        $opponentsByPot = [];
        $roleBalanceByPot = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = array_values($event->getParticipants());
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; pot draw events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            $roundNumber = $event->getRound()?->getNumber();
            if ($roundNumber === null || $roundNumber < 1 || $roundNumber > $totalRounds) {
                $violations[] = sprintf('Event %d has an invalid round number.', $index + 1);
                continue;
            }

            $firstId = $eventParticipants[0]->getId();
            $secondId = $eventParticipants[1]->getId();

            if ($firstId === $secondId) {
                $violations[] = sprintf('Event %d contains participant %s twice.', $index + 1, $firstId);
                continue;
            }

            if (!isset($this->potByParticipantId[$firstId]) || !isset($this->potByParticipantId[$secondId])) {
                $violations[] = sprintf('Event %d contains a participant that is not in the stage.', $index + 1);
                continue;
            }

            foreach ([$firstId, $secondId] as $participantId) {
                if (isset($roundParticipantIds[$roundNumber][$participantId])) {
                    $violations[] = sprintf(
                        'Participant %s appears more than once in round %d.',
                        $participantId,
                        $roundNumber
                    );
                }

                $roundParticipantIds[$roundNumber][$participantId] = true;
            }

            if (isset($pairingSeen[$firstId][$secondId])) {
                $violations[] = sprintf(
                    'Pairing %s vs %s appears more than once; pot draw pairings may not repeat.',
                    $firstId,
                    $secondId
                );
            }
            $pairingSeen[$firstId][$secondId] = true;
            $pairingSeen[$secondId][$firstId] = true;

            $firstPot = $this->potByParticipantId[$firstId];
            $secondPot = $this->potByParticipantId[$secondId];
            $opponentsByPot[$firstId][$secondPot] = ($opponentsByPot[$firstId][$secondPot] ?? 0) + 1;
            $opponentsByPot[$secondId][$firstPot] = ($opponentsByPot[$secondId][$firstPot] ?? 0) + 1;
            $roleBalanceByPot[$firstId][$secondPot] = ($roleBalanceByPot[$firstId][$secondPot] ?? 0) + 1;
            $roleBalanceByPot[$secondId][$firstPot] = ($roleBalanceByPot[$secondId][$firstPot] ?? 0) - 1;
        }

        $participantCount = count($this->participants);
        for ($round = 1; $round <= $totalRounds; ++$round) {
            $actualParticipants = count($roundParticipantIds[$round] ?? []);
            if ($actualParticipants !== $participantCount) {
                $violations[] = sprintf(
                    'Round %d has %d of the %d participants.',
                    $round,
                    $actualParticipants,
                    $participantCount
                );
            }
        }

        $perPotRolesMustBeEqual = $this->opponentsPerPot % 2 === 0;
        foreach (array_keys($this->potByParticipantId) as $participantId) {
            $participantId = (string) $participantId;
            $overallBalance = 0;

            for ($pot = 1; $pot <= $this->pots; ++$pot) {
                $opponents = $opponentsByPot[$participantId][$pot] ?? 0;
                if ($opponents !== $this->opponentsPerPot) {
                    $violations[] = sprintf(
                        'Participant %s has %d opponent(s) from pot %d, expected %d.',
                        $participantId,
                        $opponents,
                        $pot,
                        $this->opponentsPerPot
                    );
                }

                $balance = $roleBalanceByPot[$participantId][$pot] ?? 0;
                $overallBalance += $balance;
                if ($perPotRolesMustBeEqual && $balance !== 0) {
                    $violations[] = sprintf(
                        'Participant %s is not in each role equally often against pot %d.',
                        $participantId,
                        $pot
                    );
                }
            }

            if (abs($overallBalance) > 1) {
                $violations[] = sprintf(
                    'The two role counts of participant %s differ by %d; they may differ by at most 1.',
                    $participantId,
                    abs($overallBalance)
                );
            }
        }

        return $violations;
    }
}
