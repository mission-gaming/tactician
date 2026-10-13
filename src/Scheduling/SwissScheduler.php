<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Engine\Xoshiro256StarStar;
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
 * The pairing is random only with a source of draws, and only in a field
 * of even size is it random throughout: there everyone stays level at
 * zero, so the whole field is shuffled each round. In a field of odd size
 * a bye is credited as a win in the pairing order, so the participants who
 * have had a bye are placed above the rest and are paired with each other
 * first. Without a source of draws nothing is drawn: the first round pairs
 * the participants as listed (position 1 with 2, 3 with 4; no seed
 * attribute or label is read), and the same input gives the same schedule.
 *
 * The source of draws is the seed of the options or the Randomizer given
 * here, and not both. With SwissOptions(seed: ...) the same participants,
 * rounds and seed give the same schedule on every call, also on one
 * scheduler asked twice. A Randomizer has state, so a scheduler that holds
 * one continues its sequence from call to call.
 *
 * Every round count the scheduler accepts is generated when there are no
 * constraints. A round is paired without a look at the rounds to come, so
 * a draw can reach a round that has no pairing without a repeat; the
 * scheduler then draws again (up to DRAWS draws in all, each continuing
 * the same sequence, so the result is still a function of the seed), and
 * when that is not enough, or there is nothing to draw from, it builds the
 * rounds by rotation (drawByRotation()). A schedule that needs none of
 * this is the one the first draw gives. With constraints a schedule may
 * not exist, and the failure of the last draw is reported.
 *
 * @experimental
 */
class SwissScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * How many times a schedule is drawn through the engine before the
     * scheduler stops drawing. At the largest round counts about one draw
     * in five reaches a round with no pairing, so twenty draws all doing so
     * is not expected in practice; the number bounds the work, and the
     * rotation is what makes the result certain.
     */
    private const int DRAWS = 20;

    /**
     * @param ConstraintSet|null $constraints Asked about every candidate pairing; a pairing one of
     *                                        them rejects is not made
     * @param Randomizer|null $randomizer Shuffles the pairing order of each round (see the class
     *                                    docblock). It is drawn from on every call, so one
     *                                    scheduler asked twice gives two different schedules;
     *                                    for a schedule that repeats, leave it out and give
     *                                    the options a seed
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
     *                                       than 2 participants, two share an ID, there are more
     *                                       rounds than participants minus 1, or the options have
     *                                       a seed and the scheduler holds a randomizer
     * @throws IncompleteScheduleException When the scheduler has constraints and no draw found a
     *                                     complete pairing for some round. The
     *                                     NoValidPairingException of that round, in the last
     *                                     draw, is the previous exception. Not thrown by a
     *                                     scheduler without constraints
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

        $randomizer = $this->randomizerFor($options);
        $participants = array_values($participants);
        $plan = new SwissPlan($participants, $rounds);

        // A round is paired with no look at the rounds to come, so a draw
        // can leave a later round without a pairing. That says nothing
        // about the configuration: another draw of the same field goes
        // through. A draw that needs no redraw is not touched by this.
        $draws = $randomizer === null ? 1 : self::DRAWS;
        $drawn = ['events' => [], 'byes' => [], 'failure' => null];
        for ($draw = 1; $draw <= $draws; ++$draw) {
            $drawn = $this->drawWithEngine($participants, $rounds, $randomizer);
            if ($drawn['failure'] === null) {
                break;
            }
        }

        if ($drawn['failure'] !== null) {
            if ($this->constraints !== null) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($drawn['events']),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete Swiss schedule for round {$drawn['failure']->getRoundNumber()}.",
                    0,
                    $drawn['failure']
                );
            }

            // Without constraints every accepted round count has a
            // schedule, and the rotation builds one outright.
            $drawn = $this->drawByRotation($participants, $rounds, $randomizer);
        }

        $events = $drawn['events'];
        $byes = $drawn['byes'];

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
     * The source of the draws of one schedule() call: a randomizer built
     * from the seed of the options, the one the scheduler holds, or none.
     *
     * A randomizer built from a seed is on the Xoshiro256StarStar engine,
     * which is therefore part of the output for a seed, and is built anew
     * on every call: that is what makes the call repeatable.
     *
     * @throws InvalidConfigurationException When the options have a seed and the scheduler holds
     *                                       a randomizer
     */
    private function randomizerFor(SwissOptions $options): ?Randomizer
    {
        if ($options->seed === null) {
            return $this->randomizer;
        }

        if ($this->randomizer !== null) {
            throw new InvalidConfigurationException(
                'A Swiss schedule is drawn from the seed of its options or from the randomizer of the scheduler, not both',
                ['seed' => $options->seed],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        return new Randomizer(new Xoshiro256StarStar($options->seed));
    }

    /**
     * Draw the rounds by driving the Swiss engine with no results.
     *
     * @param list<Participant> $participants
     * @return array{events: list<Event>, byes: array<int, string>, failure: NoValidPairingException|null}
     *                                                                                                    The failure is the round that could not
     *                                                                                                    be paired; the events and byes are then
     *                                                                                                    those of the rounds before it
     *
     * @throws InvalidConfigurationException When the engine refuses the state (not reached: the
     *                                       inputs are validated before the draw)
     */
    private function drawWithEngine(array $participants, int $rounds, ?Randomizer $randomizer): array
    {
        $engine = new SwissPairingEngine(
            constraints: $this->constraints,
            plannedRounds: $rounds,
            randomizer: $randomizer
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
            return ['events' => $events, 'byes' => $byes, 'failure' => $exception];
        }

        return ['events' => $events, 'byes' => $byes, 'failure' => null];
    }

    /**
     * Build the rounds outright, by the rotation that lays out a round
     * robin: the first rounds of a schedule in which everyone meets
     * everyone once. Any number of rounds up to the number of participants
     * minus one is a prefix of it, so this cannot fail, and no pair meets
     * twice.
     *
     * One participant keeps its seat and the others rotate around it, a
     * seat on one side meeting the seat opposite. A field of odd size has
     * an empty seat, and the participant opposite it has the bye of the
     * round; nobody has a second one. The first-named participant of an
     * event is the one who has been first-named less often so far.
     *
     * Without a randomizer the participants are seated so that the first
     * round is the list as given, as the engine draws it: position 1 with
     * 2 and 3 with 4, the later position first-named, and the bye of a
     * field of odd size at the last position (seatsInListOrder()). The
     * rounds follow in the order of the rotation. This is reached: the one
     * draw without a randomizer leaves a round without a pairing at 26
     * participants from 24 rounds on, for example. With a randomizer the
     * participants take their seats in a drawn order and the rounds are
     * taken in a drawn order.
     *
     * @param list<Participant> $participants
     * @return array{events: list<Event>, byes: array<int, string>, failure: null}
     */
    private function drawByRotation(array $participants, int $rounds, ?Randomizer $randomizer): array
    {
        if ($randomizer === null) {
            [$seats, $fixed] = self::seatsInListOrder($participants);
        } else {
            $seats = array_values($randomizer->shuffleArray($participants));
            // A field of odd size has an empty seat, and it is the fixed one
            $fixed = count($seats) % 2 === 1 ? null : array_pop($seats);
        }

        $rotating = count($seats);
        $steps = range(0, $rotating - 1);
        if ($randomizer !== null) {
            $steps = array_values($randomizer->shuffleArray($steps));
        }

        $events = [];
        $byes = [];
        $firstNamed = [];

        for ($number = 1; $number <= $rounds; ++$number) {
            $step = $steps[$number - 1];
            $round = new Round($number);

            $pairs = [[$seats[$step], $fixed]];
            for ($offset = 1; $offset <= intdiv($rotating - 1, 2); ++$offset) {
                $pairs[] = [
                    $seats[($step + $offset) % $rotating],
                    $seats[($step - $offset + $rotating) % $rotating],
                ];
            }

            foreach ($pairs as [$one, $other]) {
                if ($one === null || $other === null) {
                    $sitsOut = $one ?? $other;
                    if ($sitsOut !== null) {
                        $byes[$number] = $sitsOut->getId();
                    }
                    continue;
                }

                if (($firstNamed[$other->getId()] ?? 0) < ($firstNamed[$one->getId()] ?? 0)) {
                    [$one, $other] = [$other, $one];
                }
                $firstNamed[$one->getId()] = ($firstNamed[$one->getId()] ?? 0) + 1;
                $events[] = new Event([$one, $other], $round);
            }
        }

        return ['events' => $events, 'byes' => $byes, 'failure' => null];
    }

    /**
     * The seats of a rotation whose first round is the list as given.
     *
     * The first round of a rotation pairs the first rotating seat with the
     * fixed seat, the second with the last, the third with the last but
     * one, and so on. So the pairs of the list (positions 1 and 2, 3 and
     * 4, ...) are put on those seats, the later position of each pair on
     * the seat that is named first. In a field of odd size the fixed seat
     * is empty and the last position sits opposite it, where the bye is.
     *
     * @param list<Participant> $participants At least two
     * @return array{list<Participant>, Participant|null} The rotating seats in order, and the
     *                                                    occupant of the fixed seat
     */
    private static function seatsInListOrder(array $participants): array
    {
        $count = count($participants);
        $odd = $count % 2 === 1;

        $fixed = $odd ? null : $participants[0];
        $seats = [0 => $odd ? $participants[$count - 1] : $participants[1]];
        $rotating = $odd ? $count : $count - 1;

        $others = $odd ? array_slice($participants, 0, $count - 1) : array_slice($participants, 2);
        foreach (array_chunk($others, 2) as $index => $pair) {
            $seats[$index + 1] = $pair[1];
            $seats[$rotating - $index - 1] = $pair[0];
        }
        ksort($seats);

        return [array_values($seats), $fixed];
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
