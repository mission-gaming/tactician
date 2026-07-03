<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

class SimpleSwissScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
        $this->initializeValidation();
    }

    /**
     * Generate a simple Swiss schedule by randomly selecting a subset of non-repeat opponents.
     *
     * Swiss has no legs concept, so the interface's $legs parameter is
     * interpreted here as the number of rounds to generate.
     *
     * @param array<Participant> $participants
     * @param int $rounds Number of Swiss rounds to generate
     *
     * @throws InvalidConfigurationException
     * @throws IncompleteScheduleException
     */
    #[Override]
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $rounds = 3,
        mixed $options = null
    ): Schedule {
        $this->validateInputs($participants, $participantsPerEvent, $rounds, $options);
        $this->clearViolations();

        $plan = new SwissPlan($participants, $rounds);
        $context = new SchedulingContext($participants, $plan, [], 1, $participantsPerEvent);
        $events = [];
        $playedPairings = [];
        $byeCounts = array_fill_keys(array_map(fn (Participant $participant) => $participant->getId(), $participants), 0);

        for ($round = 1; $round <= $rounds; ++$round) {
            $roundEvents = $this->generateRound($participants, $round, $playedPairings, $byeCounts, $context);

            if (count($roundEvents) !== $plan->getEventsPerRound()) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($events) + count($roundEvents),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete simple Swiss schedule for round {$round}."
                );
            }

            foreach ($roundEvents as $event) {
                $events[] = $event;
                $eventParticipants = $event->getParticipants();
                $playedPairings[$this->pairingKey($eventParticipants[0], $eventParticipants[1])] = true;
            }

            $context = new SchedulingContext($participants, $plan, $events, 1, $participantsPerEvent);
        }

        $schedule = new Schedule($events, [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($participants),
            'participants_per_event' => $participantsPerEvent,
            'rounds' => $plan->getTotalRounds(),
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
        ]);

        $this->validateGeneratedSchedule($schedule, $participants, $plan);

        return $schedule;
    }

    /**
     * Build the Swiss stage plan for the given configuration.
     *
     * @param array<Participant> $participants
     * @param int $rounds Number of Swiss rounds (the interface's $legs parameter)
     * @throws InvalidConfigurationException
     */
    #[Override]
    public function getPlan(
        array $participants,
        int $rounds,
        int $participantsPerEvent = 2
    ): SwissPlan {
        $this->validateInputs($participants, $participantsPerEvent, $rounds, null);

        return new SwissPlan($participants, $rounds);
    }

    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateInputs(array $participants, int $participantsPerEvent, int $rounds, mixed $options): void
    {
        if ($options !== null) {
            throw new InvalidConfigurationException(
                'Simple Swiss scheduling does not currently accept algorithm-specific options',
                ['options' => is_object($options) ? $options::class : gettype($options)]
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Simple Swiss scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        if ($participantsPerEvent !== 2) {
            throw new InvalidConfigurationException(
                'Simple Swiss scheduling currently only supports 2 participants per event',
                ['participants_per_event' => $participantsPerEvent, 'supported' => 2]
            );
        }

        if ($rounds < 1) {
            throw new InvalidConfigurationException(
                'Rounds must be a positive integer',
                ['rounds' => $rounds, 'minimum_required' => 1]
            );
        }

        if ($rounds > count($participants) - 1) {
            throw new InvalidConfigurationException(
                'Simple Swiss scheduling cannot avoid repeat opponents for more than participant_count - 1 rounds',
                ['rounds' => $rounds, 'maximum_without_repeats' => count($participants) - 1]
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
     * @param array<Participant> $participants
     * @param array<string, bool> $playedPairings
     * @param array<string, int> $byeCounts
     * @return array<Event>
     */
    private function generateRound(
        array $participants,
        int $round,
        array $playedPairings,
        array &$byeCounts,
        SchedulingContext $context
    ): array {
        if (count($participants) % 2 === 0) {
            return $this->findRoundPairings($this->shuffleParticipants($participants), $round, $playedPairings, $context) ?? [];
        }

        foreach ($this->getByeCandidates($participants, $byeCounts) as $byeParticipant) {
            $remainingParticipants = array_values(array_filter(
                $participants,
                fn (Participant $participant) => $participant->getId() !== $byeParticipant->getId()
            ));

            $roundEvents = $this->findRoundPairings(
                $this->shuffleParticipants($remainingParticipants),
                $round,
                $playedPairings,
                $context
            );

            if ($roundEvents !== null) {
                ++$byeCounts[$byeParticipant->getId()];

                return $roundEvents;
            }
        }

        return [];
    }

    /**
     * @param array<Participant> $remainingParticipants
     * @param array<string, bool> $playedPairings
     * @param array<Event> $roundEvents
     * @return array<Event>|null
     */
    private function findRoundPairings(
        array $remainingParticipants,
        int $round,
        array $playedPairings,
        SchedulingContext $context,
        array $roundEvents = []
    ): ?array {
        if ($remainingParticipants === []) {
            return $roundEvents;
        }

        $participant = array_shift($remainingParticipants);
        if (!$participant instanceof Participant) {
            return null;
        }

        foreach ($this->candidateIndexes($remainingParticipants) as $candidateIndex) {
            $opponent = $remainingParticipants[$candidateIndex] ?? null;
            if (!$opponent instanceof Participant) {
                continue;
            }

            if (isset($playedPairings[$this->pairingKey($participant, $opponent)])) {
                continue;
            }

            $event = new Event([$participant, $opponent], new Round($round));
            $eventContext = $context->withEvents($roundEvents);
            if (!$this->constraintsSatisfied($event, $eventContext)) {
                $this->recordConstraintViolations($event, $eventContext);
                continue;
            }

            $nextRemaining = $remainingParticipants;
            unset($nextRemaining[$candidateIndex]);
            $nextRemaining = array_values($nextRemaining);

            $result = $this->findRoundPairings(
                $nextRemaining,
                $round,
                $playedPairings,
                $context,
                [...$roundEvents, $event]
            );

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function constraintsSatisfied(Event $event, SchedulingContext $context): bool
    {
        return $this->constraints === null || $this->constraints->isSatisfied($event, $context);
    }

    private function recordConstraintViolations(Event $event, SchedulingContext $context): void
    {
        if ($this->constraints === null) {
            return;
        }

        foreach ($this->constraints->getConstraints() as $constraint) {
            if ($constraint instanceof ConstraintInterface && !$constraint->isSatisfied($event, $context)) {
                $this->recordViolation(new ConstraintViolation(
                    $constraint,
                    $event,
                    'Event rejected by constraint during simple Swiss scheduling',
                    $event->getParticipants(),
                    $event->getRound()?->getNumber()
                ));
            }
        }
    }

    /**
     * @param array<Participant> $participants
     * @param array<string, int> $byeCounts
     * @return array<Participant>
     */
    private function getByeCandidates(array $participants, array $byeCounts): array
    {
        $shuffledParticipants = $this->shuffleParticipants($participants);
        $orderedParticipants = [];
        foreach ($shuffledParticipants as $index => $participant) {
            $orderedParticipants[] = [
                'participant' => $participant,
                'bye_count' => $byeCounts[$participant->getId()] ?? 0,
                'index' => $index,
            ];
        }

        usort(
            $orderedParticipants,
            fn (array $first, array $second) => $first['bye_count'] <=> $second['bye_count']
                ?: $first['index'] <=> $second['index']
        );

        return array_map(fn (array $entry) => $entry['participant'], $orderedParticipants);
    }

    /**
     * @param array<Participant> $participants
     * @return array<int>
     */
    private function candidateIndexes(array $participants): array
    {
        $indexes = array_keys($participants);

        return $this->randomizer?->shuffleArray($indexes) ?? $indexes;
    }

    /**
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function shuffleParticipants(array $participants): array
    {
        return $this->randomizer?->shuffleArray($participants) ?? $participants;
    }

    private function pairingKey(Participant $firstParticipant, Participant $secondParticipant): string
    {
        $ids = [$firstParticipant->getId(), $secondParticipant->getId()];
        sort($ids);

        return implode('|', $ids);
    }

    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    #[Override]
    public function validateConstraints(array $participants, int $rounds): void
    {
        $this->validateInputs($participants, 2, $rounds, null);
    }
}
