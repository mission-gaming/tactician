<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

/**
 * Generates a complete round-robin schedule up front: every participant
 * meets every other participant once per leg, under the given constraints.
 *
 * The first leg is laid out with the circle method from the order of the
 * participant list, and each later leg is the same layout with its events
 * derived by the leg strategy. Constraints are hard filters on that
 * layout: an event one of them rejects is left out, no other pairing is
 * tried in its place, and a leg that is short of an event is a failure.
 * With constraints, a failed layout is tried again from the participant
 * list rotated one place further each time, up to as many layouts as
 * there are participants and never more than 25. RoundRobinOptions(backtracking:
 * true) then searches the round decompositions that no rotation reaches,
 * within a fixed number of pairing attempts.
 *
 * Without a Randomizer the output is a function of the input: the same
 * participants in the same order, options and constraints give the same
 * schedule. With one, the participant list is shuffled before the first
 * leg is laid out (and before a backtracking search), so:
 *
 * - the schedule is repeatable only from a randomizer seeded the same; the
 *   scheduler draws from it on every call, so one scheduler asked twice
 *   gives two different schedules;
 * - only the first leg is shuffled. The later legs are laid out from the
 *   participant list as given, so they are not the first leg mirrored or
 *   repeated pairing by pairing: a pairing can fall in another round of
 *   its leg, and under MirroredLegStrategy it can have the same roles in
 *   two legs. Every pair still meets once in every leg.
 *
 * @api
 */
class RoundRobinScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * Upper bound on rotated-ordering attempts when constraints reject the
     * pairings implied by a given participant order.
     */
    private const int MAX_GENERATION_ATTEMPTS = 25;

    /** @var array<int, string> Participant IDs receiving a bye, keyed by round number */
    private array $roundByes = [];

    /**
     * True while generating a rotation attempt whose failure the retry loop
     * will discard, under constraints that cannot tell whether they were
     * asked (ConstraintPurity): such an attempt's exception is never seen by
     * a caller, so analyzeFailure() does not build the report it would carry.
     */
    private bool $failureWillBeDiscarded = false;

    /**
     * @param ConstraintSet|null $constraints Asked about every event before it is added, with a
     *                                        context of the events generated so far; null for
     *                                        no constraints, which cannot fail on a pairing
     * @param Randomizer|null $randomizer Shuffles the participant list before the first leg is
     *                                    laid out (see the class docblock for what that does to
     *                                    later legs); null for the list order as given. A leg
     *                                    strategy that draws has a randomizer of its own
     */
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
        $this->initializeValidation();
    }

    /**
     * Generate the round-robin schedule: every pair of participants meets
     * exactly once in every leg, and no participant has two events in a
     * round.
     *
     * The events are in round order. Round numbers are 1-based and
     * continuous across legs: with n participants a leg has n - 1 rounds
     * (n when n is odd), and leg 2 starts at the round after leg 1's last.
     * In a field of odd size one participant sits out each round; the
     * schedule's 'byes' metadata maps the round number to that
     * participant's ID. The other metadata keys are 'algorithm',
     * 'participant_count', 'legs', 'rounds_per_leg', 'total_rounds' and
     * 'expected_event_count'.
     *
     * All or nothing: the schedule is checked against the plan before it is
     * returned, and a failure never returns the events that were generated.
     * After a failure, the exception carries the diagnostics of the last
     * layout tried.
     *
     * @param array<Participant> $participants At least 2, with unique IDs. Their order decides the
     *                                         layout: position, not the seed attribute
     * @param SchedulerOptions|null $options RoundRobinOptions, or null for a single mirrored leg
     *
     * @throws InvalidConfigurationException When the options are not RoundRobinOptions, there are
     *                                       fewer than 2 participants, two share an ID, the leg
     *                                       strategy reports the configuration unsatisfiable, or
     *                                       the role assignment returns anything but the seatings
     *                                       it was given with roles changed
     * @throws IncompleteScheduleException When the constraints leave a leg short of an event in
     *                                     every layout tried and, with backtracking on, the
     *                                     search finds no first leg, or the constraints reject
     *                                     the roles the role assignment gives that leg or an
     *                                     event of a later leg derived from it
     */
    #[Override]
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        $options = $this->resolveOptions($options);
        $this->validateInputs($participants);

        $strategy = $options->strategy;
        $roleAssignment = $options->roleAssignment;

        // Build the plan first: the strategy contributes its facts, an
        // unsatisfiable configuration fails here with diagnostics, and
        // generation, validation, and diagnostics all read shape facts
        // from the resulting plan.
        $plan = $this->buildPlan($participants, $options->legs, $strategy);

        // Generate complete schedule using integrated approach, retrying with
        // rotated participant orderings when constraints reject the pairings
        // implied by a particular circle-method order. When the rotations are
        // exhausted and backtracking is enabled, search the decompositions the
        // circle method cannot reach before failing loudly.
        try {
            $allEvents = $this->generateScheduleWithRetries($participants, $strategy, $plan, $roleAssignment);
        } catch (IncompleteScheduleException $greedyFailure) {
            if (!$options->backtracking) {
                throw $greedyFailure;
            }
            $allEvents = $this->generateWithBacktracking($participants, $strategy, $plan, $greedyFailure, $roleAssignment);
        }
        ksort($this->roundByes);

        $schedule = new Schedule($allEvents, [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($participants),
            'legs' => $plan->getLegs(),
            'rounds_per_leg' => $plan->getRoundsPerLeg(),
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
            'byes' => $this->roundByes,
        ]);

        // Validate schedule completeness with all-or-nothing guarantee
        $this->validateGeneratedSchedule($schedule, $participants, $plan);

        return $schedule;
    }

    /**
     * Build the round-robin stage plan for the given configuration,
     * including the configured strategy's contribution facts: the rounds,
     * legs and events schedule() would produce, with nothing generated and
     * no constraint asked about an event.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options RoundRobinOptions, or null for a single mirrored leg
     * @throws InvalidConfigurationException When the options are not RoundRobinOptions, there are
     *                                       fewer than 2 participants, two share an ID, or the
     *                                       leg strategy reports the configuration unsatisfiable
     */
    #[Override]
    public function getPlan(
        array $participants,
        ?SchedulerOptions $options = null
    ): RoundRobinPlan {
        $options = $this->resolveOptions($options);
        $this->validateInputs($participants);

        return $this->buildPlan($participants, $options->legs, $options->strategy);
    }

    /**
     * Default and type-check the options: this scheduler accepts only
     * RoundRobinOptions.
     *
     * @throws InvalidConfigurationException When another algorithm's options are passed
     */
    private function resolveOptions(?SchedulerOptions $options): RoundRobinOptions
    {
        if ($options === null) {
            return new RoundRobinOptions();
        }

        if (!$options instanceof RoundRobinOptions) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires RoundRobinOptions',
                ['options' => $options::class],
                reason: InvalidConfigurationReason::UnsupportedOptions,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        return $options;
    }

    /**
     * Build the plan from the leg strategy's contribution, failing loudly
     * when the strategy reports the configuration unsatisfiable.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function buildPlan(
        array $participants,
        int $legs,
        LegStrategyInterface $strategy
    ): RoundRobinPlan {
        $contribution = $strategy->planLegs(
            $participants,
            $legs,
            $this->constraints ?? ConstraintSet::create()->build()
        );

        if ($contribution->unsatisfiableReasons !== []) {
            throw new InvalidConfigurationException(
                'Leg strategy cannot satisfy the requested configuration: '
                    . implode(' | ', $contribution->unsatisfiableReasons),
                [
                    'strategy' => $strategy::class,
                    'unsatisfiable_reasons' => $contribution->unsatisfiableReasons,
                    'warnings' => $contribution->warnings,
                ],
                reason: InvalidConfigurationReason::UnsatisfiableLegStrategy,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        return new RoundRobinPlan(
            $participants,
            $legs,
            $contribution->rolesMirrorAcrossLegs,
            $contribution->requiresRandomization,
            $contribution->warnings
        );
    }

    /**
     * Validate the participant list (leg validation lives on the options).
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function validateInputs(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        // Check for duplicate participant IDs
        $ids = array_map(fn(Participant $p) => $p->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))],
                reason: InvalidConfigurationReason::DuplicateParticipantIds,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }
    }

    /**
     * Generate the schedule, retrying with rotated participant orderings when
     * constraints reject the pairings implied by a particular order.
     *
     * The circle method fixes which pairings share a round purely by list
     * order, so a constraint can reject one ordering while another ordering
     * yields a complete schedule (e.g. seed protection failing only because
     * two seeds happen to meet in an early round). Attempts are deterministic
     * rotations of the input order and bounded, so genuinely unsatisfiable
     * configurations still fail with the diagnostics of the last attempt.
     * The failure analysis probes every missing pairing in every round and
     * costs far more than the attempt itself, so it is built once, for the
     * attempt whose exception is thrown, and not for the ones discarded here.
     *
     * That holds for a constraint set ConstraintPurity knows. The analysis
     * asks the constraints about pairings the attempt never tried, so a
     * constraint of the caller's that keeps state, or that throws for a
     * pairing it cannot judge, answers the next attempt differently once it
     * has been through an analysis. For such a set every attempt is still
     * analysed, as it always was, and the result is what it always was.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException When no ordering produces a complete schedule
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateScheduleWithRetries(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment
    ): array {
        $participants = array_values($participants);
        $maxAttempts = $this->constraints === null
            ? 1
            : min(count($participants), self::MAX_GENERATION_ATTEMPTS);

        $discardedFailuresNeedNoAnalysis = ConstraintPurity::isKnown($this->constraints);

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $ordered = $attempt === 0
                ? $participants
                : [...array_slice($participants, $attempt), ...array_slice($participants, 0, $attempt)];

            // Reset diagnostics so a successful retry does not report stale
            // violations, and a failure reports only the final attempt.
            $this->clearViolations();
            $this->failureWillBeDiscarded = $discardedFailuresNeedNoAnalysis && $attempt < $maxAttempts - 1;

            try {
                return $this->generateIntegratedSchedule($ordered, $strategy, $plan, $roleAssignment);
            } catch (IncompleteScheduleException $exception) {
                if ($attempt === $maxAttempts - 1) {
                    throw $exception;
                }
            } finally {
                $this->failureWillBeDiscarded = false;
            }
        }

        // Not reached: there is at least one attempt, and the last one
        // returns or rethrows
        throw new InvariantViolationException('Schedule generation loop must return or throw');
    }

    /**
     * Search for a schedule after the greedy rotations failed: leg 1 via
     * backtracking over round decompositions, later legs derived from
     * leg 1's actual rounds through the leg strategy. The search does not
     * cross leg boundaries (docs/design/backtracking-generation.md), so a
     * later leg rejected by constraints fails loudly.
     *
     * The search chooses the roles of leg 1 itself, to satisfy the
     * constraints. The role assignment is asked about the leg it found, and
     * roles it changes are checked against the constraints again.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateWithBacktracking(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        IncompleteScheduleException $greedyFailure,
        RoleAssignmentInterface $roleAssignment
    ): array {
        // The greedy final attempt's violations stay in the collector: if
        // the search also fails, callers still get constraint-level
        // diagnostics alongside the search-level message (the greedy
        // failure itself rides along as the previous exception).
        $this->roundByes = [];

        $field = array_values($participants);
        $field = $this->randomizer?->shuffleArray($field) ?? $field;

        $generator = new BacktrackingRoundRobinGenerator($this->constraints);
        $legOneEvents = $generator->generateFirstLeg($field, $plan);

        if ($legOneEvents === null) {
            throw new IncompleteScheduleException(
                $plan->getExpectedEventCount(),
                0,
                $this->violationCollector,
                $plan,
                $participants,
                $generator->wasBudgetExhausted()
                    ? 'Backtracking search exhausted its step budget ('
                        . BacktrackingRoundRobinGenerator::STEP_BUDGET
                        . ' pairing attempts) without finding a complete schedule. The configuration may be unsatisfiable or may need a different participant order.'
                    : 'Backtracking search exhausted the search space: no round decomposition of the first leg satisfies the constraints, so the configuration is unsatisfiable.',
                0,
                $greedyFailure,
                $this->analyzeFailure($participants, $plan, [])
            );
        }

        $legOneEvents = $this->reviseSearchedRoles($legOneEvents, $roleAssignment, $field, $plan, $participants, $greedyFailure);

        $this->roundByes = $generator->getRoundByes();
        $allEvents = $legOneEvents;
        $roundsPerLeg = $plan->getRoundsPerLeg();

        for ($leg = 2; $leg <= $plan->getLegs(); ++$leg) {
            $legEvents = [];

            foreach ($legOneEvents as $legOneEvent) {
                $legRound = $legOneEvent->getRound()?->getNumber() ?? 0;
                $globalRound = (($leg - 1) * $roundsPerLeg) + $legRound;

                $context = new SchedulingContext($field, $plan, [...$allEvents, ...$legEvents], $leg);
                $event = $strategy->generateEventForLeg(
                    $legOneEvent->getParticipants(),
                    $leg,
                    $globalRound,
                    $context
                );

                if ($event !== null && !$this->shouldAddEventWithFullConstraints($event, $context)) {
                    $this->recordConstraintViolation($event, $context);
                    $event = null;
                }

                if ($event === null) {
                    throw new IncompleteScheduleException(
                        $plan->getExpectedEventCount(),
                        count($allEvents) + count($legEvents),
                        $this->violationCollector,
                        $plan,
                        $participants,
                        "Backtracking found a first leg, but constraints reject its leg {$leg} derivation and the search does not cross leg boundaries. Relax the constraints on later legs or change the leg strategy.",
                        0,
                        $greedyFailure,
                        $this->analyzeFailure($participants, $plan, [...$allEvents, ...$legEvents])
                    );
                }

                $legEvents[] = $event;
            }

            foreach ($generator->getRoundByes() as $legRound => $participantId) {
                $this->roundByes[(($leg - 1) * $roundsPerLeg) + $legRound] = $participantId;
            }

            $allEvents = [...$allEvents, ...$legEvents];
        }

        // The search succeeded: the greedy attempt's violations would be
        // stale diagnostics on a complete schedule.
        $this->clearViolations();

        return $allEvents;
    }

    /**
     * Build the deep failure analysis for a throw site: which constraint
     * blocks which missing pairing where, probed against the events that
     * were actually generated. Only meaningful with constraints
     * configured; unconstrained generation cannot fail on pairings.
     *
     * Null as well for a rotation attempt the retry loop goes on from, when
     * the constraints cannot tell (see generateScheduleWithRetries()): that
     * exception is caught and dropped, and nothing reads its report.
     *
     * @param array<Participant> $participants
     * @param array<Event> $partialEvents
     */
    private function analyzeFailure(array $participants, RoundRobinPlan $plan, array $partialEvents): ?DiagnosticReport
    {
        if ($this->constraints === null || $this->failureWillBeDiscarded) {
            return null;
        }

        return (new SchedulingDiagnostics())->analyzeSchedulingFailure(
            $participants,
            $this->constraints,
            $partialEvents,
            $plan
        );
    }

    /**
     * Generate complete schedule using integrated multi-leg approach.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateIntegratedSchedule(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment
    ): array {
        $allEvents = [];
        $this->roundByes = [];
        $legs = $plan->getLegs();
        $context = new SchedulingContext($participants, $plan, [], 1);

        $expectedEventsPerLeg = $plan->getEventsPerLeg();

        for ($leg = 1; $leg <= $legs; ++$leg) {
            $legEvents = $this->generateLegWithFullContext($participants, $leg, $strategy, $plan, $context, $roleAssignment);

            // Check if we generated the expected number of events for this leg
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($allEvents) + count($legEvents),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation.",
                    0,
                    null,
                    $this->analyzeFailure($participants, $plan, [...$allEvents, ...$legEvents])
                );
            }

            $allEvents = [...$allEvents, ...$legEvents];
            $context = new SchedulingContext($participants, $plan, $allEvents, $leg + 1);
        }

        return $allEvents;
    }

    /**
     * Generate events for a specific leg with full tournament context.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateLegWithFullContext(
        array $participants,
        int $leg,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        SchedulingContext $context,
        RoleAssignmentInterface $roleAssignment
    ): array {
        if ($leg === 1) {
            // For the first leg, use traditional round-robin generation but validate completeness
            $legEvents = $this->generateRoundRobinEvents($participants, $context, $roleAssignment);

            // Validate that we got the expected number of events for the first leg
            $expectedEventsPerLeg = $plan->getEventsPerLeg();
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($legEvents), // Events generated for first leg only
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation.",
                    0,
                    null,
                    $this->analyzeFailure($participants, $plan, $legEvents)
                );
            }

            return $legEvents;
        }

        // For subsequent legs, use the strategy to generate events
        $legEvents = [];
        $roundOffset = ($leg - 1) * $plan->getRoundsPerLeg();

        $participantList = array_values($participants);
        if (count($participantList) % 2 !== 0) {
            $participantList[] = null; // null represents "bye"
        }

        // The role assignment decides the leg's roles as it does for the
        // first leg; the leg strategy then derives this leg's roles from them.
        $rounds = $this->assignedRoles($roleAssignment, $this->circleLayout($participantList));

        foreach ($rounds as $index => $seatings) {
            $globalRound = $index + 1 + $roundOffset;

            $roundEvents = [];
            foreach ($seatings as [$participant1, $participant2]) {
                // A seating with a "bye" (null) produces no event: record who sits out
                if ($participant1 === null || $participant2 === null) {
                    $sittingOut = $participant1 ?? $participant2;
                    if ($sittingOut !== null) {
                        $this->roundByes[$globalRound] = $sittingOut->getId();
                    }
                    continue;
                }

                $event = $strategy->generateEventForLeg([$participant1, $participant2], $leg, $globalRound, $context);

                if ($event !== null) {
                    // Check constraints with full tournament context (all previous events)
                    if ($this->shouldAddEventWithFullConstraints($event, $context)) {
                        $legEvents[] = $event;
                        $roundEvents[] = $event;
                    } else {
                        // If constraint fails, record violation for diagnostics
                        $this->recordConstraintViolation($event, $context);
                    }
                }
            }

            // Update context once per round (matching first-leg behaviour) rather
            // than per event, which would copy the full event list every event.
            if ($roundEvents !== []) {
                $context = $context->withEvents($roundEvents);
            }
        }

        return $legEvents;
    }

    /**
     * Lay one leg out with the circle method: seat i meets seat
     * (count - 1 - i), and after every round all seats but the first rotate.
     *
     * The roles proposed alternate with the (leg-local) round parity. Without
     * that the circle method keeps the fixed seat first-named all leg and
     * gives rotating participants same-role streaks of half the field size;
     * with it the running imbalance of a participant within the leg is
     * bounded at 3 (4 in a field of odd size). That is a bound for one leg:
     * over several legs the imbalances can add up, as they do under the
     * repeated leg strategy. The role assignment decides whether the
     * proposal stands.
     *
     * @param array<int, Participant|null> $participantList Participants in circle order, including any "bye" (null)
     * @return list<list<array{0: Participant|null, 1: Participant|null}>> Rounds of seatings, the bye seating included
     */
    private function circleLayout(array $participantList): array
    {
        $participantList = array_values($participantList);
        $seatCount = count($participantList);
        $pairingsPerRound = intdiv($seatCount, 2);
        $rounds = [];

        for ($round = 1; $round < $seatCount; ++$round) {
            $seatings = [];
            for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
                $participant1 = $participantList[$pair];
                $participant2 = $participantList[$seatCount - 1 - $pair];

                $seatings[] = $round % 2 === 0
                    ? [$participant2, $participant1]
                    : [$participant1, $participant2];
            }
            $rounds[] = $seatings;

            // Rotate participants for next round (keep first participant fixed)
            $this->rotateParticipants($participantList);
        }

        return $rounds;
    }

    /**
     * Ask the role assignment for the roles of one leg and check that it
     * changed nothing else: the same rounds, the same seatings in the same
     * order, each one as given or reversed.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     * @throws InvalidConfigurationException When the answer differs in more than roles
     */
    private function assignedRoles(RoleAssignmentInterface $roleAssignment, array $rounds): array
    {
        $assigned = $roleAssignment->assignRoles($rounds);

        if (!$this->differsInRolesOnly($assigned, $rounds)) {
            throw new InvalidConfigurationException(
                'Role assignment must return the seatings it was given, each one unchanged or reversed',
                ['role_assignment' => $roleAssignment::class],
                reason: InvalidConfigurationReason::InvalidRoleAssignment,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        return $assigned;
    }

    /**
     * Whether an answer is the rounds that were handed over with nothing but
     * roles changed. The answer comes from code outside the library, so
     * nothing about its shape is taken on trust: a round or a seating that
     * is not an array is a wrong answer, not a type error.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     */
    private function differsInRolesOnly(mixed $answer, array $rounds): bool
    {
        if (!is_array($answer) || array_keys($answer) !== array_keys($rounds)) {
            return false;
        }

        foreach ($rounds as $index => $seatings) {
            $answeredSeatings = $answer[$index];
            if (!is_array($answeredSeatings) || array_keys($answeredSeatings) !== array_keys($seatings)) {
                return false;
            }

            foreach ($seatings as $position => $seating) {
                $answered = $answeredSeatings[$position];
                if ($answered !== $seating && $answered !== [$seating[1], $seating[0]]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Ask the role assignment about a first leg the backtracking search
     * found, and check the roles it changes against the constraints.
     *
     * The search chose its roles to satisfy the constraints, so a leg the
     * role assignment leaves alone needs no second check. A leg it changes
     * is replayed event by event; a rejected event fails loudly, because
     * keeping the search's roles instead would silently drop what the role
     * assignment promises.
     *
     * @param array<Event> $legOneEvents The leg's events in round order
     * @param array<Participant> $field The field in the order that seeded the search
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException When the constraints reject a role the assignment changed
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function reviseSearchedRoles(
        array $legOneEvents,
        RoleAssignmentInterface $roleAssignment,
        array $field,
        RoundRobinPlan $plan,
        array $participants,
        IncompleteScheduleException $greedyFailure
    ): array {
        $eventsByRound = [];
        foreach ($legOneEvents as $event) {
            $eventsByRound[$event->getRound()?->getNumber() ?? 0][] = $event;
        }
        $eventsByRound = array_values($eventsByRound);

        $rounds = [];
        foreach ($eventsByRound as $roundEvents) {
            $seatings = [];
            foreach ($roundEvents as $event) {
                $pair = $event->getParticipants();
                $seatings[] = [$pair[0], $pair[1]];
            }
            $rounds[] = $seatings;
        }

        $assigned = $this->assignedRoles($roleAssignment, $rounds);

        $revised = [];
        $changed = false;
        foreach ($eventsByRound as $index => $roundEvents) {
            foreach ($roundEvents as $position => $event) {
                $pair = $event->getParticipants();
                if ($assigned[$index][$position][0] === $pair[0]) {
                    $revised[] = $event;
                    continue;
                }

                $revised[] = new Event([$pair[1], $pair[0]], $event->getRound(), $event->getMetadata());
                $changed = true;
            }
        }

        if (!$changed) {
            return $legOneEvents;
        }

        $accepted = [];
        foreach ($revised as $event) {
            $context = new SchedulingContext($field, $plan, $accepted, 1);

            if (!$this->shouldAddEventWithFullConstraints($event, $context)) {
                // The greedy attempt's violations are stale here: the search
                // succeeded, and this failure is about one event.
                $this->clearViolations();
                $this->recordConstraintViolation($event, $context);

                $pair = $event->getParticipants();

                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($accepted),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    sprintf(
                        'Backtracking found a first leg, but the constraints reject the roles that %s gives it: %s vs %s in round %d. The search chooses roles that satisfy the constraints and the role assignment changes them afterwards. Relax the role constraints or use the round-parity role assignment.',
                        $roleAssignment::class,
                        $pair[0]->getId(),
                        $pair[1]->getId(),
                        $event->getRound()?->getNumber() ?? 0
                    ),
                    0,
                    $greedyFailure,
                    $this->analyzeFailure($participants, $plan, $accepted)
                );
            }

            $accepted[] = $event;
        }

        return $revised;
    }

    /**
     * Generate all round-robin events using circle method.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateRoundRobinEvents(
        array $participants,
        SchedulingContext $baseContext,
        RoleAssignmentInterface $roleAssignment
    ): array {
        $participantList = array_values($participants);
        $events = [];

        // Handle odd number of participants by adding a "bye"
        if (count($participantList) % 2 !== 0) {
            $participantList[] = null; // null represents "bye"
        }

        // Randomize initial order if randomizer is provided
        if ($this->randomizer !== null) {
            $participantList = $this->shuffleParticipants($participantList);
        }

        // Lay the leg out with the circle method and let the role assignment
        // decide the roles before any constraint sees an event
        $rounds = $this->assignedRoles($roleAssignment, $this->circleLayout($participantList));

        foreach ($rounds as $index => $seatings) {
            $round = $index + 1;
            $context = new SchedulingContext(
                $participants,
                $baseContext->getPlan(),
                $events,
                $baseContext->getCurrentLeg(),
                $baseContext->getParticipantsPerEvent()
            );

            foreach ($seatings as [$participant1, $participant2]) {
                // Skip if one participant is "bye" (null), recording who sits out
                if ($participant1 === null || $participant2 === null) {
                    $sittingOut = $participant1 ?? $participant2;
                    if ($sittingOut !== null) {
                        $this->roundByes[$round] = $sittingOut->getId();
                    }
                    continue;
                }

                $roundObject = new Round($round);
                $event = new Event([$participant1, $participant2], $roundObject);

                // Check constraints if provided
                if ($this->constraints === null) {
                    $events[] = $event;
                } else {
                    if ($this->constraints->isSatisfied($event, $context)) {
                        $events[] = $event;
                    } else {
                        // Record constraint violations for diagnostic reporting
                        foreach ($this->constraints->getConstraints() as $constraint) {
                            if (!$constraint->isSatisfied($event, $context)) {
                                $violation = new ConstraintViolation(
                                    $constraint,
                                    $event,
                                    sprintf(
                                        'Event %s vs %s rejected by constraint',
                                        $participant1->getId(),
                                        $participant2->getId()
                                    ),
                                    [$participant1, $participant2],
                                    $round
                                );
                                $this->recordViolation($violation);
                            }
                        }
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Check if an event should be added based on constraints with full tournament context.
     *
     * @param Event $event The event to validate
     * @param SchedulingContext $context Full tournament context
     *
     * @return bool True if the event should be added
     */
    private function shouldAddEventWithFullConstraints(Event $event, SchedulingContext $context): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        return $this->constraints->isSatisfied($event, $context);
    }

    /**
     * Record a constraint violation for diagnostic purposes.
     *
     * @param Event $event The event that failed constraint validation
     * @param SchedulingContext $context The context when validation failed
     */
    private function recordConstraintViolation(Event $event, SchedulingContext $context): void
    {
        if ($this->constraints === null) {
            // Not reached: this is only called after a constraint has
            // rejected an event
            return;
        }

        // Record violations for each failing constraint
        foreach ($this->constraints->getConstraints() as $constraint) {
            if (!$constraint->isSatisfied($event, $context)) {
                $participants = $event->getParticipants();
                $violation = new ConstraintViolation(
                    $constraint,
                    $event,
                    sprintf(
                        'Event %s vs %s rejected by constraint in leg %d',
                        $participants[0]->getId(),
                        $participants[1]->getId(),
                        $context->getCurrentLeg()
                    ),
                    $participants,
                    $event->getRound()?->getNumber() ?? 0
                );
                $this->recordViolation($violation);
            }
        }
    }

    /**
     * Shuffle participants using the provided randomizer.
     *
     * @param array<Participant|null> $participants
     * @return array<Participant|null>
     */
    private function shuffleParticipants(array $participants): array
    {
        // Keep the null/"bye" participant at the end if it exists
        $hasBye = end($participants) === null;
        if ($hasBye) {
            array_pop($participants);
        }

        $participants = $this->randomizer?->shuffleArray($participants) ?? $participants;

        if ($hasBye) {
            $participants[] = null;
        }

        return $participants;
    }

    /**
     * Rotate participants for circle method (keep first fixed, rotate others).
     *
     * @param array<Participant|null> $participants
     */
    private function rotateParticipants(array &$participants): void
    {
        // Circle method: fix position 0, rotate positions 1 to n-1
        if (count($participants) <= 2) {
            return;
        }

        $temp = $participants[1];
        for ($i = 1; $i < count($participants) - 1; ++$i) {
            $participants[$i] = $participants[$i + 1];
        }
        $participants[count($participants) - 1] = $temp;
    }
}
