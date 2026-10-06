<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

/**
 * Whole-schedule Swiss preset: non-repeat pairing over N rounds, drawn
 * before any event is played.
 *
 * A canned composition, not a second mechanism - this drives
 * SwissPairingEngine through the standard stage driver loop while
 * recording no results, which reduces standings-aware Monrad pairing to
 * non-repeat pairing of the field in the order it stands. Use the engine
 * directly when rounds should be paired from actual results.
 *
 * The pairing is random only with a Randomizer, and only in a field of
 * even size is it random throughout: there everyone stays level at zero,
 * so the randomizer shuffles the whole field each round. In a field of
 * odd size a bye is credited as a win in the pairing order, so the
 * participants who have had a bye are placed above the rest and are
 * paired with each other first. Without a Randomizer nothing is drawn:
 * the first round pairs the participants as listed (1 with 2, 3 with 4),
 * and the same input gives the same schedule.
 *
 * @experimental
 */
class SwissScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * @param ConstraintSet|null $constraints Asked about every candidate pairing; a pairing one of
     *                                        them rejects is not made
     * @param Randomizer|null $randomizer Shuffles the pairing order of each round (see the class
     *                                    docblock). It is drawn from on every call, so one
     *                                    scheduler asked twice gives two different schedules;
     *                                    seed a new one for a repeatable schedule
     */
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
        $this->initializeValidation();
    }

    /**
     * Generate a Swiss schedule: every round pairs each participant once,
     * and no two participants meet twice.
     *
     * The events are in round order, rounds numbered from 1. In a field of
     * odd size one participant sits out each round (SwissPairingEngine says
     * which); the schedule's 'byes' metadata maps the round number to that
     * participant's ID. The other metadata keys are 'algorithm',
     * 'participant_count', 'rounds', 'total_rounds' and
     * 'expected_event_count'.
     *
     * @param array<Participant> $participants At least 2, with unique IDs
     * @param SchedulerOptions|null $options SwissOptions, or null for 3 rounds. The rounds may not
     *                                       exceed the number of participants minus 1: beyond that
     *                                       a repeat pairing cannot be avoided
     *
     * @throws InvalidConfigurationException When the options are not SwissOptions, there are fewer
     *                                       than 2 participants, two share an ID, or there are
     *                                       more rounds than participants minus 1
     * @throws IncompleteScheduleException When no complete pairing exists for some round: the
     *                                     constraints reject too much, or the earlier rounds left
     *                                     no pairing without a repeat. The NoValidPairingException
     *                                     of that round is the previous exception
     */
    #[Override]
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        $options = $this->resolveOptions($options);
        $rounds = $options->rounds;
        $this->validateInputs($participants, $rounds);
        $this->clearViolations();

        $plan = new SwissPlan($participants, $rounds);
        $engine = new SwissPairingEngine(
            constraints: $this->constraints,
            plannedRounds: $rounds,
            randomizer: $this->randomizer
        );

        $state = StageState::start($participants);
        $events = [];
        $byes = [];

        try {
            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                foreach ($pairing->getEvents() as $event) {
                    $events[] = $event;
                }
                foreach ($pairing->getByes() as $bye) {
                    $byes[$pairing->getRoundNumber()] = $bye->getId();
                }

                // Recording no results is the point: pairings still count
                // as played, so repeats stay excluded while standings stay
                // level and the randomizer keeps the pairing random.
                $state = $state->withRoundPlayed($pairing, []);
            }
        } catch (NoValidPairingException $exception) {
            throw new IncompleteScheduleException(
                $plan->getExpectedEventCount(),
                count($events),
                $this->violationCollector,
                $plan,
                $participants,
                "Failed to generate complete Swiss schedule for round {$exception->getRoundNumber()}.",
                0,
                $exception
            );
        }

        $schedule = new Schedule($events, [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($participants),
            'rounds' => $plan->getTotalRounds(),
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
            'byes' => $byes,
        ]);

        $this->validateGeneratedSchedule($schedule, $participants, $plan);

        return $schedule;
    }

    /**
     * Build the Swiss stage plan for the given configuration: the rounds
     * and the events schedule() would produce, with nothing paired.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options SwissOptions, or null for 3 rounds
     * @throws InvalidConfigurationException For the configurations schedule() refuses
     */
    #[Override]
    public function getPlan(
        array $participants,
        ?SchedulerOptions $options = null
    ): SwissPlan {
        $options = $this->resolveOptions($options);
        $this->validateInputs($participants, $options->rounds);

        return new SwissPlan($participants, $options->rounds);
    }

    /**
     * Default and type-check the options: this scheduler accepts only
     * SwissOptions.
     *
     * @throws InvalidConfigurationException When another algorithm's options are passed
     */
    private function resolveOptions(?SchedulerOptions $options): SwissOptions
    {
        if ($options === null) {
            return new SwissOptions();
        }

        if (!$options instanceof SwissOptions) {
            throw new InvalidConfigurationException(
                'Swiss scheduling requires SwissOptions',
                ['options' => $options::class],
                reason: InvalidConfigurationReason::UnsupportedOptions
            );
        }

        return $options;
    }

    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateInputs(array $participants, int $rounds): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Swiss scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }

        if ($rounds > count($participants) - 1) {
            throw new InvalidConfigurationException(
                'Swiss scheduling cannot avoid repeat opponents for more than participant_count - 1 rounds',
                ['rounds' => $rounds, 'maximum_without_repeats' => count($participants) - 1],
                reason: InvalidConfigurationReason::InvalidRoundCount
            );
        }

        $ids = array_map(fn(Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))],
                reason: InvalidConfigurationReason::DuplicateParticipantIds
            );
        }
    }
}
