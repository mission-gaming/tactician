<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

/**
 * Whole-schedule Swiss preset: random non-repeat pairing over N rounds.
 *
 * A canned composition, not a second mechanism - this drives
 * SwissPairingEngine through the standard stage driver loop while
 * recording no results, which reduces standings-aware Monrad pairing to
 * random non-repeat pairing (everyone stays tied at zero, so the
 * randomizer shuffles the whole field each round). Use the engine
 * directly when rounds should be paired from actual results.
 */
class SwissScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
        $this->initializeValidation();
    }

    /**
     * Generate a Swiss schedule by randomly pairing non-repeat opponents
     * each round.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options SwissOptions, or null for 3 rounds
     *
     * @throws InvalidConfigurationException
     * @throws IncompleteScheduleException When no complete pairing exists for some round
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
     * Build the Swiss stage plan for the given configuration.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options SwissOptions, or null for 3 rounds
     * @throws InvalidConfigurationException
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
                ['options' => $options::class]
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
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        if ($rounds > count($participants) - 1) {
            throw new InvalidConfigurationException(
                'Swiss scheduling cannot avoid repeat opponents for more than participant_count - 1 rounds',
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
}
