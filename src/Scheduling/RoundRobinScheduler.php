<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
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
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Stage\PairKey;
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
 * Roles are searched in three places, and nowhere else:
 *
 * - The roles of the later legs, when the leg strategy states them as
 *   preferences (ShuffledLegStrategy does; mirrored and repeated legs are
 *   rules). An event the constraints reject is given the other role, and
 *   when they reject both, earlier events of the later legs are given
 *   theirs, until every event is accepted or every choice was tried. This
 *   runs in every layout, without backtracking being asked for, and costs
 *   nothing where no event is rejected. It is bounded at
 *   LegRoleSearch::REJECTION_LIMIT rejected roles for each layout.
 * - The roles of the first leg, within the rule the role assignment
 *   states (RoleAssignmentInterface::rule()), where it states one: the
 *   two balanced role assignments do (the strict one and the default,
 *   which is balanced where possible), and round parity does not. When
 *   every layout has failed with the roles the role assignment
 *   gave it, the layouts are gone through again, and in each the scheduler
 *   looks for other roles of the first leg that the role assignment keeps
 *   and the constraints accept in every leg: first by fixing the seatings
 *   the constraints rejected and asking the role assignment for roles
 *   around them, then by a search of the roles within the rule. A
 *   schedule found this way has the pairings and rounds of the circle
 *   method and a first leg that is the role assignment's own: a balanced
 *   one, for those two. The searches of all the layouts together are
 *   bounded at LegRoleSearch::REJECTION_LIMIT rejected roles and at as
 *   many events put to the constraints as a backtracking search has steps.
 *   Without a rule the first leg has the roles the role assignment
 *   decided, whatever the later legs make of them.
 * - The roles of the first leg of every layout a backtracking search
 *   finds, with RoundRobinOptions(backtracking: true). The search holds
 *   the pairings and rounds of each first leg it finds and searches the
 *   roles of all legs together, under the limit of rejected roles for each
 *   layout and the step budget of the search: within the rule of the role
 *   assignment where it states one, and otherwise freely, returning a leg
 *   only when the role assignment keeps it. Without a rule it does so for
 *   the circle layout too, once.
 *
 * A failure says which of these happened: that every choice was tried, or
 * that a bound stopped a search, and what was not searched at all. Only
 * an exhausted backtracking search calls a configuration unsatisfiable.
 *
 * The default of the options (BalancedWherePossibleRoleAssignment) does
 * not fail where balanced roles are not found. It then generates the
 * schedule with the roles the generator proposes, exactly as under
 * RoundParityRoleAssignment, and marks it: the metadata of the schedule
 * says why its roles are not balanced (RoleFallback). So a call under the
 * default fails only where RoundParityRoleAssignment fails, and with its
 * failure. Under constraints the search of roles cannot reason about
 * exactly (anything but the library's own classes in a ConstraintSet) the
 * roles the generator proposes are tried before that search, and with
 * their schedule in hand the search is held to 4,000 events put to the
 * constraints, so that the fall-back does not wait for a search that can
 * take seconds; generateBalancedWherePossible() has the order of the
 * attempts. BalancedRoleAssignment, named, is the strict choice: balanced
 * roles within the bounds above, or a failure.
 *
 * Without a Randomizer the output is a function of the input: the same
 * participants in the same order, options and constraints give the same
 * schedule, as long as the leg strategy holds no randomizer (a
 * ShuffledLegStrategy built from a seed does not: its draws start again
 * from the seed on every call) and the constraints answer from the event
 * and the context alone. With one, the participant list is shuffled before the first
 * leg is laid out (and before a backtracking search), so:
 *
 * - the schedule is repeatable only from a randomizer seeded the same; the
 *   scheduler draws from it on every call, so one scheduler asked twice
 *   gives two different schedules;
 * - every leg follows from the shuffled order. The first leg is its circle
 *   layout, and each later leg has the pairings of the first leg in the
 *   same rounds of the leg, in the roles the leg strategy derives from the
 *   first leg's, as in a schedule without a randomizer. (Up to 0.2.x the
 *   later legs were laid out from the list as given, so that a pairing
 *   could fall in another round of its leg, have the same roles in two
 *   mirrored legs, and be played in the last round of one leg and the
 *   first round of the next.)
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

    /**
     * Events the searches of first-leg roles may put to the constraints in
     * one schedule() call without backtracking, over all the layouts
     * together: as many as a backtracking search has steps.
     */
    private const int ROLE_SEARCH_ATTEMPTS = BacktrackingRoundRobinGenerator::STEP_BUDGET;

    /**
     * Events the search for balanced roles may put to the constraints
     * under the default of the options (BalancedWherePossibleRoleAssignment)
     * once a schedule with the generator's roles is in hand, where the
     * constraints are not ones the search reasons about exactly
     * (ConstraintPurity::readsOwnEventsOnly()). It is checked before every
     * rotated order, so the last one tried can pass it by the events of
     * its first leg, and of later legs whose roles are a rule. The search
     * of the later legs whose roles are preferences
     * (generateLaterLegsWithRoleSearch()) has what is left of it as its
     * attempt limit, as the searches of first-leg roles have, and so does
     * not pass it. A backtracking search has what is left of the count as
     * its step budget, and is made only where the schedule in hand came
     * from a backtracking search itself. Nothing else is left out for it:
     * within the bound the search is the one BalancedRoleAssignment has
     * when it is named, so a schedule it finds is that one's. A search
     * this stops is reported as RoleFallback::BalancedRolesRejected.
     *
     * The events the balanced roles as given put to the constraints in
     * the first layout, before the schedule was in hand, count against it
     * where that layout is not laid out again (generateBalancedWherePossible()).
     *
     * A count and not a time: what it costs grows with the field, since a
     * constraint that reads a participant's events reads more of them.
     * docs/design/role-assignment.md has what was measured.
     */
    private const int ROLE_SEARCH_ATTEMPTS_WITH_A_SCHEDULE_IN_HAND = 4_000;

    /** @var array<int, string> Participant IDs receiving a bye, keyed by round number */
    private array $roundByes = [];

    /**
     * The shuffles the randomizer has given this schedule() call, each as
     * the order it puts the positions of a list in. Kept so that a second
     * run of generation in the call reads the shuffles the first one read
     * (shuffled()).
     *
     * @var array<int, list<int>>
     */
    private array $draws = [];

    /**
     * The index in $draws of the shuffle the next layout is laid out from.
     */
    private int $drawCursor = 0;

    /**
     * True while the default of the options generates with balanced roles:
     * a failure there is followed by the roles the generator proposes and
     * never reaches the caller. So no failure is analysed, and a rotated
     * order stops at the first event the constraints reject.
     */
    private bool $failureIsNeverShown = false;

    /**
     * Events the generation under way may put to the constraints, or null
     * for the bounds it always has. Set while the default of the options
     * looks for balanced roles with a schedule in hand.
     */
    private ?int $attemptsAllowed = null;

    /**
     * Events put to the constraints since $attemptsAllowed was set.
     */
    private int $attemptsSpent = 0;

    /**
     * Whether $attemptsAllowed stopped the generation under way, or kept a
     * part of it from running.
     */
    private bool $searchWasCutShort = false;

    /**
     * The first rotated order of the balanced roles as step 1 of the
     * default left it, under constraints the search of roles does not
     * reason about exactly (generateBalancedWherePossible()): its failure,
     * the events it put to the constraints, the cursor of the shuffles
     * after it, and whether a constraint that reads no position rejected
     * its first rejected event. Set for step 3, whose first rotated order
     * is that layout, and taken by the rotated orders of step 3
     * (generateScheduleWithRetries()), which then begin at the second.
     * Null otherwise.
     *
     * @var array{failure: IncompleteScheduleException, spent: int, drawCursor: int, withoutRoles: bool}|null
     */
    private ?array $firstLayoutAlreadyTried = null;

    /**
     * Whether the generation under way began a backtracking search.
     */
    private bool $aBacktrackingSearchRan = false;

    /**
     * Whether the schedule in hand under the default of the options came
     * from a backtracking search and not from a rotated participant order.
     */
    private bool $scheduleInHandWasSearchedFor = false;

    /**
     * Whether the last search for roles of the generation under way (of
     * the first leg within a rule, or the backtracking search after it)
     * stopped at one of its own bounds.
     */
    private bool $searchStoppedAtABound = false;

    /**
     * True while generating a rotation attempt whose failure the retry loop
     * will discard, under constraints that cannot tell whether they were
     * asked (ConstraintPurity): such an attempt's exception is never seen by
     * a caller, so analyzeFailure() does not build the report it would carry.
     */
    private bool $failureWillBeDiscarded = false;

    /**
     * What the last analysis that was not built would have been built from:
     * the participants, the plan and the events of the attempt. A failure
     * that is followed by a search of roles is analysed only when the
     * search fails too, and then from this.
     *
     * @var array{array<Participant>, RoundRobinPlan, array<Event>}|null
     */
    private ?array $unanalysedFailure = null;

    /**
     * Whether the rotations of this schedule() call are followed, when they
     * all fail, by a search of the roles of the first leg: the role
     * assignment states a rule. The failures of the rotations then leave
     * what they say about the first leg to the failure of that search.
     */
    private bool $aRoleSearchFollows = false;

    /**
     * Whether the first event the constraints rejected in the rotation
     * being generated was rejected by a constraint that reads no position
     * of an event (aConstraintThatIgnoresRolesRejects()); null until an
     * event is rejected. Every event before the first rejected one is in
     * place, so such a rejection is one that no choice of roles lifts: the
     * layout has no roles to search for.
     */
    private ?bool $firstRejectionIgnoresRoles = null;

    /**
     * The rotations of this schedule() call, by their number, whose layout
     * a search of roles need not be made for (see
     * $firstRejectionIgnoresRoles).
     *
     * @var array<int, true>
     */
    private array $rotationsWithoutRoles = [];

    /**
     * The rotations, by their number, that the round-parity run of the
     * default need not lay out: the balanced run of the same schedule()
     * call found that a constraint which reads no position rejects an
     * event in that layout whatever the roles ($rotationsWithoutRoles of
     * that run). Empty outside that run.
     *
     * @var array<int, true>
     */
    private array $rotationsThatFailWhateverTheRoles = [];

    /**
     * Whether the round-parity run of the default, under constraints that
     * read the events of a candidate's own participants only
     * (ConstraintPurity::readsOwnEventsOnly()), builds no failure analysis
     * for a rotated order it goes on from. The failure it reports is that of
     * the last order, analysed once, as under RoundParityRoleAssignment.
     */
    private bool $rotatedOrdersAreAnalysedOnce = false;

    /**
     * How many layouts of the search of first-leg roles under way were
     * left unsearched because a constraint that reads no position rejects
     * one of their events. When that is all of them, the failure says so
     * and does not speak of a search.
     */
    private int $layoutsRejectedWhateverTheRoles = 0;

    /**
     * How many pairings of the leg being generated the leg strategy
     * returned no event for. Counted so that a leg left short by its
     * strategy is not reported as one left short by constraints.
     */
    private int $pairingsWithoutEvent = 0;

    /**
     * The first leg of the layout being generated, as the circle method
     * laid it out and before any role assignment: the rounds of seatings,
     * the bye seating included. Every later leg of the layout is derived
     * from it, so that a leg laid out from a shuffled field is followed by
     * legs of the same pairings in the same rounds.
     *
     * @var list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private array $firstLegLayout = [];

    /**
     * @param ConstraintSet|null $constraints Asked about every event before it is added, with a
     *                                        context of the events generated so far; null for
     *                                        no constraints, which cannot fail on a pairing
     * @param Randomizer|null $randomizer Shuffles the participant list before the first leg is
     *                                    laid out, and every later leg derives from that first
     *                                    leg; null for the list order as given. It is
     *                                    not passed on: a leg strategy that draws has a seed
     *                                    or a randomizer of its own
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
     * 'expected_event_count', and on a schedule of the default role
     * assignment whose roles are not balanced, 'role_fallback'
     * (RoleFallback::METADATA_KEY): the reason, as the backing string of a
     * RoleFallback case. No other schedule has that key.
     * The schedule's entrants (Schedule::getEntrants()) are the participants
     * as given, in their order.
     *
     * All or nothing: the schedule is checked against the plan before it is
     * returned, and a failure never returns the events that were generated.
     * After a failure, the exception carries the diagnostics of the last
     * layout tried.
     *
     * @param array<Participant> $participants At least 2, with unique IDs. Their order decides the
     *                                         layout: position, not the seed attribute
     * @param SchedulerOptions|null $options RoundRobinOptions, or null for a single mirrored leg
     *                                       with roles that are balanced where possible
     *
     * @throws InvalidConfigurationException When the options are not RoundRobinOptions, there are
     *                                       fewer than 2 participants, two share an ID, the leg
     *                                       strategy reports the configuration unsatisfiable, or
     *                                       the role assignment returns anything but the seatings
     *                                       it was given with roles changed
     * @throws IncompleteScheduleException When the constraints leave a leg short of an event in
     *                                     every layout tried, or accept no roles for the later
     *                                     legs of a strategy of preferences within the bound of
     *                                     the role search, and, where the role assignment states
     *                                     a rule, no other roles it keeps for the first leg are
     *                                     found in those layouts within the bounds of that
     *                                     search; and, with backtracking on, the search finds no
     *                                     first leg whose roles and later legs the constraints
     *                                     accept, within its step budget, or the constraints
     *                                     reject the roles a role assignment without a rule gives
     *                                     a first leg it found; when the leg strategy returns no
     *                                     event for a pairing; or when the finished schedule
     *                                     fails the plan's integrity checks. Under the default
     *                                     role assignment, only when all of that is so for the
     *                                     roles the generator proposes: the failure is the one
     *                                     RoundParityRoleAssignment gives
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
        $contribution = $this->legContribution($participants, $options->legs, $strategy);
        $plan = $this->buildPlan($participants, $options->legs, $contribution);
        $rolesArePreferences = $contribution->rolesArePreferences;

        // Generate complete schedule using integrated approach, retrying with
        // rotated participant orderings when constraints reject the pairings
        // implied by a particular circle-method order. When the rotations are
        // exhausted and backtracking is enabled, search the decompositions the
        // circle method cannot reach before failing loudly.
        $this->draws = [];
        $this->drawCursor = 0;
        $this->resetTheSearchForBalancedRoles();
        $fallback = null;

        if ($roleAssignment instanceof BalancedWherePossibleRoleAssignment) {
            try {
                [$allEvents, $fallback] = $this->generateBalancedWherePossible($participants, $options, $plan, $roleAssignment, $contribution);
            } finally {
                $this->resetTheSearchForBalancedRoles();
            }
        } else {
            $allEvents = $this->generateWithRoleAssignment($participants, $strategy, $plan, $roleAssignment, $rolesArePreferences, $options->backtracking);
        }
        ksort($this->roundByes);

        $metadata = [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($participants),
            'legs' => $plan->getLegs(),
            'rounds_per_leg' => $plan->getRoundsPerLeg(),
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
            'byes' => $this->roundByes,
        ];
        if ($fallback instanceof RoleFallback) {
            // Written only for a schedule that fell back, so that one with
            // balanced roles is the schedule it is without this key
            $metadata[RoleFallback::METADATA_KEY] = $fallback->value;
        }

        $schedule = new Schedule($allEvents, $metadata, array_values($participants));

        // Validate schedule completeness with all-or-nothing guarantee
        $this->validateGeneratedSchedule($schedule, $participants, $plan);

        return $schedule;
    }

    /**
     * Generate the events under one role assignment, held to it: the
     * rotated orders with the roles it gives, then, where it states a rule,
     * the roles of the first leg searched within that rule in the same
     * layouts, then the backtracking search where that is asked for.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException When none of them completes the schedule
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateWithRoleAssignment(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment,
        bool $rolesArePreferences,
        bool $backtracking
    ): array {
        $rule = $this->constraints instanceof ConstraintSet ? $roleAssignment->rule() : null;
        $this->searchStoppedAtABound = false;

        try {
            return $this->generateScheduleWithRetries(
                $participants,
                $strategy,
                $plan,
                $roleAssignment,
                $rolesArePreferences,
                $rule instanceof ConstraintInterface,
                self::MAX_GENERATION_ATTEMPTS,
                $this->firstLayoutAlreadyTried['failure'] ?? null
            );
        } catch (IncompleteScheduleException $greedyFailure) {
            // The roles the role assignment gave were rejected in every
            // layout. Where it states a rule, other roles within that rule
            // are looked for in the same layouts before anything else is.
            $failure = $greedyFailure;

            if ($rule instanceof ConstraintInterface) {
                try {
                    return $this->generateWithRolesSearched($participants, $strategy, $plan, $roleAssignment, $rule, $rolesArePreferences, $greedyFailure);
                } catch (IncompleteScheduleException $roleFailure) {
                    $failure = $roleFailure;
                }
            }

            if (!$backtracking) {
                throw $failure;
            }

            $stepBudget = BacktrackingRoundRobinGenerator::STEP_BUDGET;
            if ($this->attemptsAllowed !== null) {
                // A schedule is in hand. Where a rotated order gave it, no
                // backtracking search is made for balanced roles: the
                // schedule needed none. Where a backtracking search gave
                // it, another is made for a balanced first leg, with what
                // is left of the events that may still be put to the
                // constraints as its step budget.
                $stepBudget = min($stepBudget, $this->attemptsAllowed - $this->attemptsSpent);
                if ($stepBudget <= 0 || !$this->scheduleInHandWasSearchedFor) {
                    $this->searchWasCutShort = true;

                    throw $failure;
                }
            }

            $this->aBacktrackingSearchRan = true;

            return $this->generateWithBacktracking($participants, $strategy, $plan, $failure, $roleAssignment, $rolesArePreferences, $rule, $stepBudget);
        }
    }

    /**
     * Generate the events under the default of the options
     * (BalancedWherePossibleRoleAssignment): with balanced roles where a
     * schedule with them is found, and otherwise with the roles the
     * generator proposes, as RoundParityRoleAssignment gives them.
     *
     * The order of the attempts depends on what the search of roles can
     * know about the constraints (ConstraintPurity::readsOwnEventsOnly()).
     *
     * Constraints it reasons about exactly (the library's own classes in a
     * ConstraintSet, or none):
     *
     * 1. Everything BalancedRoleAssignment does when it is named: the
     *    balanced roles as given in every rotated order, the roles of the
     *    first leg searched within the balance in those layouts, and the
     *    backtracking search where it is asked for. A schedule found here
     *    is returned, and is the one BalancedRoleAssignment gives.
     * 2. Everything RoundParityRoleAssignment does when it is named. A
     *    schedule found here is returned with the reason step 1 ended on;
     *    a failure here is the failure of the call. One thing is left out
     *    that changes neither: a rotated order that step 1 found to fail
     *    for who meets whom and when, whatever the roles, is not laid out
     *    again, unless it is the last one, whose failure is reported.
     *    Under a leg strategy that draws nothing is left out: a layout
     *    takes draws for its later legs, and the layouts after one that
     *    was passed over would have other draws than they have under
     *    RoundParityRoleAssignment.
     *
     * Any other constraints (a closure, a metadata rule, a class of the
     * caller's, a subclass, a ConstraintSet subclass). The search goes back
     * one event at a time for these, and can spend its whole allowance
     * without a result, so the roles the generator proposes are tried
     * before it:
     *
     * 1. The balanced roles as given, in the first layout only. A schedule
     *    is returned.
     * 2. Everything RoundParityRoleAssignment does when it is named. Its
     *    schedule, or its failure, is kept.
     * 3. Everything BalancedRoleAssignment does when it is named, from the
     *    first layout again. With no schedule in hand it has the bounds it
     *    always has. With one in hand it is held to 4,000 events put to the
     *    constraints (ROLE_SEARCH_ATTEMPTS_WITH_A_SCHEDULE_IN_HAND), the
     *    search of later legs whose roles are preferences included, of
     *    which a backtracking search has what is left as its step budget,
     *    and is made only where the schedule in hand came from a
     *    backtracking search too. The first layout is the one of step 1,
     *    and it is not laid out again where it would end as it did there:
     *    under a leg strategy that draws nothing, or a ShuffledLegStrategy
     *    built from a seed, whose draws for that layout are kept while
     *    step 2 draws from a clone. Its failure and the events it put to
     *    the constraints then stand for it, and step 3 begins at the second
     *    rotated order, with the shuffle and the draws that order has under
     *    BalancedRoleAssignment, named. Where the first layout spent more
     *    than the 4,000, step 3 therefore asks nothing. Nothing else is
     *    left out, so what the
     *    search does within the bound is what it does under
     *    BalancedRoleAssignment: a rule that fixes roles is solved, and a
     *    limit on what a participant did before is searched for one event
     *    at a time as far as the bound goes. A schedule found here is
     *    returned, and is the one BalancedRoleAssignment gives.
     * 4. The schedule of step 2 with the reason step 3 ended on, or the
     *    failure of step 2.
     *
     * So the call fails only where RoundParityRoleAssignment fails, with
     * its failure. No failure of the balanced attempts reaches the caller,
     * so none is analysed, and a rotated order is left at the first event
     * the constraints reject.
     *
     * Every run starts where a call starts: the shuffles of a randomizer
     * are those the call drew first (shuffled()), and a leg strategy that
     * draws is asked for its plan again, which starts the draws of a seed
     * again. One that holds a randomizer of its own cannot be started
     * again, and draws on; nor can a class of the caller's be cloned, so
     * under either the first layout is laid out again in step 3. Step 3,
     * where it goes on from the first layout of step 1, goes on from where
     * that layout left the shuffles and the draws.
     *
     * @param array<Participant> $participants
     * @return array{array<Event>, RoleFallback|null} The events, and why their roles are not balanced
     * @throws IncompleteScheduleException When the roles the generator proposes do not complete the schedule
     *                                     either: the failure RoundParityRoleAssignment gives
     * @throws InvalidConfigurationException When the leg strategy reports the configuration unsatisfiable
     *                                       when asked for its plan again
     */
    private function generateBalancedWherePossible(
        array $participants,
        RoundRobinOptions $options,
        RoundRobinPlan $plan,
        BalancedWherePossibleRoleAssignment $balanced,
        LegPlanContribution $contribution
    ): array {
        $strategy = $options->strategy;
        $rolesArePreferences = $contribution->rolesArePreferences;
        $roundParity = new RoundParityRoleAssignment();

        if (ConstraintPurity::readsOwnEventsOnly($this->constraints)) {
            $this->failureIsNeverShown = true;
            try {
                return [$this->generateWithRoleAssignment($participants, $strategy, $plan, $balanced, $rolesArePreferences, $options->backtracking), null];
            } catch (IncompleteScheduleException) {
                $reason = $this->searchStoppedAtABound ? RoleFallback::SearchStoppedAtBound : RoleFallback::NoBalancedRoles;
            } finally {
                $this->failureIsNeverShown = false;
            }

            $this->startOver($participants, $options->legs, $strategy, $contribution);
            // A rotated order in which a constraint that reads no position
            // rejected the first event to be rejected fails with any roles:
            // the round-parity run has the same layouts, and is spared them.
            // Not under a leg strategy that draws: a layout that is not laid
            // out takes no draws for its later legs, and the layouts after
            // it would then have other draws than RoundParityRoleAssignment
            // gives them, named.
            $this->rotationsThatFailWhateverTheRoles = $contribution->requiresRandomization ? [] : $this->rotationsWithoutRoles;
            // The constraints read the events of a candidate's own participants
            // and nothing else, so a rotated order that is gone on from is not
            // analysed here either, as it is not while the balanced roles are
            // searched: the failure of the call carries the one analysis
            $this->rotatedOrdersAreAnalysedOnce = true;

            try {
                return [$this->generateWithRoleAssignment($participants, $strategy, $plan, $roundParity, $rolesArePreferences, $options->backtracking), $reason];
            } finally {
                $this->rotationsThatFailWhateverTheRoles = [];
                $this->rotatedOrdersAreAnalysedOnce = false;
            }
        }

        // Whether the draws of the leg strategy in the first layout can be
        // kept for step 3 while step 2 draws from the start: a strategy
        // that draws nothing, or a ShuffledLegStrategy built from a seed,
        // whose clone draws from the seed again (one that holds a
        // randomizer shares it with its clone, and a class of the caller's
        // is not cloned)
        $firstLayoutCanBeKept = !$contribution->requiresRandomization
            || ($strategy instanceof ShuffledLegStrategy && $strategy::class === ShuffledLegStrategy::class && $strategy->seed !== null);
        $firstLayout = null;
        $this->failureIsNeverShown = true;
        $this->attemptsSpent = 0;
        try {
            return [$this->generateScheduleWithRetries($participants, $strategy, $plan, $balanced, $rolesArePreferences, true, 1), null];
        } catch (IncompleteScheduleException $exception) {
            // The roles the generator proposes are tried next
            if ($firstLayoutCanBeKept) {
                $firstLayout = [
                    'failure' => $exception,
                    'spent' => $this->attemptsSpent,
                    'drawCursor' => $this->drawCursor,
                    'withoutRoles' => isset($this->rotationsWithoutRoles[0]),
                ];
            }
        } finally {
            $this->failureIsNeverShown = false;
        }

        // Step 2 draws what RoundParityRoleAssignment, named, draws. Where
        // the first layout is kept for step 3, a strategy that draws is
        // left where that layout left it, and step 2 draws from a clone
        $roundParityStrategy = $firstLayout !== null && $contribution->requiresRandomization ? clone $strategy : $strategy;
        $this->startOver($participants, $options->legs, $roundParityStrategy, $contribution);
        $inHand = null;
        $byesInHand = [];
        $roundParityFailure = null;
        $this->aBacktrackingSearchRan = false;
        try {
            $inHand = $this->generateWithRoleAssignment($participants, $roundParityStrategy, $plan, $roundParity, $rolesArePreferences, $options->backtracking);
            $byesInHand = $this->roundByes;
        } catch (IncompleteScheduleException $exception) {
            $roundParityFailure = $exception;
        }
        $this->scheduleInHandWasSearchedFor = $this->aBacktrackingSearchRan;

        $this->failureIsNeverShown = true;
        $this->attemptsAllowed = $inHand === null ? null : self::ROLE_SEARCH_ATTEMPTS_WITH_A_SCHEDULE_IN_HAND;
        if ($firstLayout === null) {
            $this->startOver($participants, $options->legs, $strategy, $contribution);
            $this->attemptsSpent = 0;
        } else {
            // The first rotated order of step 3 is the layout step 1 laid
            // out, and would end as it did: it is not laid out again. What
            // it put to the constraints counts against the allowance, as it
            // would if it were, and step 3 goes on from the second rotated
            // order with the shuffles and the draws that order has under
            // BalancedRoleAssignment, named.
            $this->drawCursor = $firstLayout['drawCursor'];
            $this->attemptsSpent = $firstLayout['spent'];
            $this->firstLayoutAlreadyTried = $firstLayout;
        }
        try {
            return [$this->generateWithRoleAssignment($participants, $strategy, $plan, $balanced, $rolesArePreferences, $options->backtracking), null];
        } catch (IncompleteScheduleException) {
            $reason = match (true) {
                $this->searchWasCutShort => RoleFallback::BalancedRolesRejected,
                $this->searchStoppedAtABound => RoleFallback::SearchStoppedAtBound,
                default => RoleFallback::NoBalancedRoles,
            };
        } finally {
            $this->failureIsNeverShown = false;
            $this->attemptsAllowed = null;
            $this->firstLayoutAlreadyTried = null;
        }

        if ($roundParityFailure instanceof IncompleteScheduleException) {
            // The failure of the call, with the rejections recorded on the
            // way to it and not those of the balanced attempts since
            $this->violationCollector = $roundParityFailure->getViolationCollector();

            throw $roundParityFailure;
        }

        // $inHand is a schedule here: the attempt returned one or threw.
        // The violations of the balanced attempts since would be stale
        // diagnostics on it.
        $this->roundByes = $byesInHand;
        $this->clearViolations();

        return [$inHand ?? [], $reason];
    }

    /**
     * Put what a run of generation reads back to where a schedule() call
     * starts, so that the run gives what a call with its role assignment
     * named gives: the shuffles of a randomizer are read from the first one
     * the call drew, and a leg strategy that draws is asked for its plan
     * again (a ShuffledLegStrategy built from a seed starts its draws again
     * there). The plan of the call stands.
     *
     * @param array<Participant> $participants
     * @param LegStrategyInterface $strategy The leg strategy of the run: the one of the options, or a clone of it
     * @throws InvalidConfigurationException When the leg strategy now reports the configuration unsatisfiable
     */
    private function startOver(array $participants, int $legs, LegStrategyInterface $strategy, LegPlanContribution $contribution): void
    {
        $this->drawCursor = 0;

        if ($contribution->requiresRandomization) {
            $this->legContribution($participants, $legs, $strategy);
        }
    }

    /**
     * Clear what a schedule() call keeps of its search for balanced roles
     * under the default.
     */
    private function resetTheSearchForBalancedRoles(): void
    {
        $this->failureIsNeverShown = false;
        $this->attemptsAllowed = null;
        $this->attemptsSpent = 0;
        $this->searchWasCutShort = false;
        $this->searchStoppedAtABound = false;
        $this->aBacktrackingSearchRan = false;
        $this->scheduleInHandWasSearchedFor = false;
        $this->rotationsThatFailWhateverTheRoles = [];
        $this->rotatedOrdersAreAnalysedOnce = false;
        $this->firstLayoutAlreadyTried = null;
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

        return $this->buildPlan(
            $participants,
            $options->legs,
            $this->legContribution($participants, $options->legs, $options->strategy)
        );
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
     * Ask the leg strategy for its contribution to the plan, failing
     * loudly when it reports the configuration unsatisfiable.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function legContribution(
        array $participants,
        int $legs,
        LegStrategyInterface $strategy
    ): LegPlanContribution {
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

        return $contribution;
    }

    /**
     * Build the plan from the leg strategy's contribution.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function buildPlan(
        array $participants,
        int $legs,
        LegPlanContribution $contribution
    ): RoundRobinPlan {
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
     * Where a search of roles follows the last attempt (the role assignment
     * states a rule), that attempt's failure is not what the caller sees
     * either, unless the search fails too. For a set whose constraints read
     * the events of a candidate's own participants and nothing else
     * (ConstraintPurity::readsOwnEventsOnly(), which adds the role limit
     * that holds a role extractor to the sets above), no attempt is then
     * analysed here: what the last one would have been analysed from is
     * kept (analysisOfTheLastAttempt()), and the analysis is built once, if
     * the search fails. The limits on roles are the constraints such a
     * search is for, and an analysis of every rotated order of a league
     * costs seconds that a schedule the search finds should not pay.
     *
     * @param array<Participant> $participants
     * @param bool $aRoleSearchFollows Whether a failure here is followed by a search of first-leg roles
     * @param IncompleteScheduleException|null $failureOfTheFirstOrder The failure of the first rotated order where it
     *                                                                 was laid out before, to the same end
     *                                                                 ($firstLayoutAlreadyTried): the rotated orders
     *                                                                 then begin at the second
     * @return array<Event>
     * @throws IncompleteScheduleException When no ordering produces a complete schedule
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateScheduleWithRetries(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment,
        bool $rolesArePreferences,
        bool $aRoleSearchFollows,
        int $attemptLimit = self::MAX_GENERATION_ATTEMPTS,
        ?IncompleteScheduleException $failureOfTheFirstOrder = null
    ): array {
        $participants = array_values($participants);
        $maxAttempts = $this->constraints === null
            ? 1
            : min(count($participants), self::MAX_GENERATION_ATTEMPTS, $attemptLimit);

        $discardedFailuresNeedNoAnalysis = ConstraintPurity::isKnown($this->constraints) || $this->rotatedOrdersAreAnalysedOnce;
        $analysisWaitsForTheSearch = $aRoleSearchFollows && ConstraintPurity::readsOwnEventsOnly($this->constraints);
        $this->unanalysedFailure = null;
        $this->aRoleSearchFollows = $aRoleSearchFollows;
        $this->rotationsWithoutRoles = [];
        $lastFailure = null;
        $firstAttempt = 0;

        if ($failureOfTheFirstOrder instanceof IncompleteScheduleException) {
            // The first rotated order was laid out before, to the same
            // end: its failure stands for it here
            $lastFailure = $failureOfTheFirstOrder;
            if ($this->firstLayoutAlreadyTried['withoutRoles'] ?? false) {
                $this->rotationsWithoutRoles[0] = true;
            }
            $firstAttempt = 1;
        }
        $this->firstLayoutAlreadyTried = null;

        for ($attempt = $firstAttempt; $attempt < $maxAttempts; ++$attempt) {
            if (
                $lastFailure instanceof IncompleteScheduleException
                && $this->attemptsAllowed !== null
                && $this->attemptsSpent >= $this->attemptsAllowed
            ) {
                // A schedule is in hand, and the events that may still be
                // put to the constraints for balanced roles are spent
                $this->searchWasCutShort = true;

                throw $lastFailure;
            }

            if (isset($this->rotationsThatFailWhateverTheRoles[$attempt]) && $attempt < $maxAttempts - 1) {
                // The balanced run of the default showed that this layout
                // fails for who meets whom and when. It is not laid out
                // again, unless it is the last: the failure of the last
                // rotated order is the failure of the call. Its shuffle is
                // passed over, so that the next layout has its own.
                if ($this->randomizer instanceof Randomizer) {
                    ++$this->drawCursor;
                }

                continue;
            }

            $ordered = $attempt === 0
                ? $participants
                : [...array_slice($participants, $attempt), ...array_slice($participants, 0, $attempt)];

            // Reset diagnostics so a successful retry does not report stale
            // violations, and a failure reports only the final attempt.
            $this->clearViolations();
            $this->failureWillBeDiscarded = $analysisWaitsForTheSearch
                || ($discardedFailuresNeedNoAnalysis && $attempt < $maxAttempts - 1);
            $this->firstRejectionIgnoresRoles = $aRoleSearchFollows ? null : false;

            try {
                return $this->generateIntegratedSchedule($ordered, $strategy, $plan, $roleAssignment, $rolesArePreferences);
            } catch (IncompleteScheduleException $exception) {
                if ($this->theFirstRejectionIgnoredRoles()) {
                    $this->rotationsWithoutRoles[$attempt] = true;
                }
                $lastFailure = $exception;
                if ($attempt === $maxAttempts - 1) {
                    throw $exception;
                }
            } finally {
                $this->failureWillBeDiscarded = false;
            }
        }

        // Not reached: there is at least one attempt, and the last one
        // returns or rethrows (where the first rotated order is taken as
        // tried there are two or more: constraints and two participants)
        throw new InvariantViolationException('Schedule generation loop must return or throw');
    }

    /**
     * Generate the schedule after every layout failed with the roles the
     * role assignment gave it, for a role assignment that states a rule:
     * the same layouts again, one rotated participant order after the
     * other, each with the roles of its first leg searched within that
     * rule (searchRolesWithinRule()).
     *
     * The pairings and their rounds stay those of the circle method. One
     * allowance of rejected roles (LegRoleSearch::REJECTION_LIMIT) serves
     * the searches of all the layouts together, so that a schedule that
     * cannot be completed pays for one search at most, and not for one in
     * every layout.
     *
     * With a randomizer every layout is laid out from a new shuffle, as
     * the layouts of the attempts before were.
     *
     * @param array<Participant> $participants
     * @return list<Event>
     * @throws IncompleteScheduleException When no layout has roles within the rule that the constraints
     *                                     accept, or the search stopped at its bound
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateWithRolesSearched(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment,
        ConstraintInterface $rule,
        bool $rolesArePreferences,
        IncompleteScheduleException $greedyFailure
    ): array {
        $participants = array_values($participants);
        $layouts = min(count($participants), self::MAX_GENERATION_ATTEMPTS);
        $roundsPerLeg = $plan->getRoundsPerLeg();
        $legs = $plan->getLegs();
        $allowance = ['rejections' => LegRoleSearch::REJECTION_LIMIT, 'attempts' => self::ROLE_SEARCH_ATTEMPTS];
        if ($this->attemptsAllowed !== null) {
            // A schedule is in hand: the search has what is left of the
            // events that may still be put to the constraints
            $allowance['attempts'] = min($allowance['attempts'], $this->attemptsAllowed - $this->attemptsSpent);
            if ($allowance['attempts'] <= 0) {
                $this->searchWasCutShort = true;

                throw $greedyFailure;
            }
        }
        $stoppedAtABound = false;
        $this->layoutsRejectedWhateverTheRoles = 0;

        for ($attempt = 0; $attempt < $layouts; ++$attempt) {
            if (!$this->randomizer instanceof Randomizer && isset($this->rotationsWithoutRoles[$attempt])) {
                // The same layout as the rotation of that number, which
                // failed for who meets whom and when: no roles change that.
                // With a randomizer the layout is a new shuffle, and is
                // looked at for itself.
                ++$this->layoutsRejectedWhateverTheRoles;
                continue;
            }

            $ordered = $attempt === 0
                ? $participants
                : [...array_slice($participants, $attempt), ...array_slice($participants, 0, $attempt)];

            $seats = $ordered;
            if (count($seats) % 2 !== 0) {
                $seats[] = null; // null represents "bye"
            }
            if ($this->randomizer instanceof Randomizer) {
                $seats = $this->shuffleParticipants($seats);
            }
            $layout = $this->circleLayout($seats);

            $outcome = $this->searchRolesWithinRule(
                $layout,
                $ordered,
                $strategy,
                $plan,
                $roleAssignment,
                $rule,
                $rolesArePreferences,
                false,
                PHP_INT_MAX,
                $allowance
            );

            if ($outcome['events'] !== null) {
                $this->roundByes = [];
                foreach ($layout as $index => $seatings) {
                    foreach ($seatings as [$first, $second]) {
                        $sittingOut = $first === null ? $second : ($second === null ? $first : null);
                        if ($sittingOut === null) {
                            continue;
                        }
                        for ($leg = 1; $leg <= $legs; ++$leg) {
                            $this->roundByes[(($leg - 1) * $roundsPerLeg) + $index + 1] = $sittingOut->getId();
                        }
                    }
                }

                // The schedule is complete: the violations of the attempts
                // before it would be stale diagnostics.
                $this->clearViolations();

                return $outcome['events'];
            }

            if ($outcome['slot'] !== null) {
                // The leg strategy returned no event for a pairing: other
                // roles do not give it one, and the failure that says so
                // is the one already at hand.
                throw new IncompleteScheduleException(
                    $greedyFailure->getExpectedEventCount(),
                    $greedyFailure->getActualEventCount(),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    $greedyFailure->getMessage(),
                    0,
                    $greedyFailure->getPrevious(),
                    $greedyFailure->getAnalysis() ?? $this->analysisOfTheLastAttempt()
                );
            }

            $stoppedAtABound = $stoppedAtABound || $outcome['stopped'];
        }

        $this->searchStoppedAtABound = $stoppedAtABound;
        if ($this->attemptsAllowed !== null) {
            $this->attemptsSpent = $this->attemptsAllowed - $allowance['attempts'];
            if ($stoppedAtABound) {
                $this->searchWasCutShort = true;
            }
        }

        // The message is the one of the last layout with the roles the role
        // assignment gave it, followed by what the search of roles came to.
        // The violations in the collector are those of that layout, and
        // they name the constraint that rejected its roles.
        throw new IncompleteScheduleException(
            $plan->getExpectedEventCount(),
            $greedyFailure->getActualEventCount(),
            $this->violationCollector,
            $plan,
            $participants,
            $greedyFailure->getMessage() . ' ' . (
                $this->layoutsRejectedWhateverTheRoles === $layouts
                    ? sprintf(
                        'No choice of roles for the first leg changes that: in %s the constraints reject an event for who meets whom and when, whichever participant is first-named. The pairings and their rounds were not searched: RoundRobinOptions(backtracking: true) searches them.',
                        $layouts === 1 ? 'the one layout tried' : "each of the {$layouts} layouts tried"
                    )
                    : ($stoppedAtABound
                    ? sprintf(
                        'The roles of the first leg were then searched within what %s keeps, and the search stopped at a bound (%d rejected roles, or %d events put to the constraints, over all the layouts). Roles that satisfy the constraints may exist beyond it; the pairings and their rounds were not searched, and RoundRobinOptions(backtracking: true) searches them. Roles that %s does not keep were not searched either: RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment()) searches every choice of roles.',
                        get_debug_type($roleAssignment),
                        LegRoleSearch::REJECTION_LIMIT,
                        self::ROLE_SEARCH_ATTEMPTS,
                        get_debug_type($roleAssignment)
                    )
                    : sprintf(
                        'The roles of the first leg were then searched within what %s keeps, in %s, and no choice of them has a schedule the constraints accept. The pairings and their rounds were not searched: RoundRobinOptions(backtracking: true) searches them. Roles that %s does not keep were not searched either: RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment()) searches every choice of roles.',
                        get_debug_type($roleAssignment),
                        $layouts === 1 ? 'the one layout tried' : "each of the {$layouts} layouts tried",
                        get_debug_type($roleAssignment)
                    ))
            ),
            0,
            $greedyFailure,
            $greedyFailure->getAnalysis() ?? $this->analysisOfTheLastAttempt()
        );
    }

    /**
     * Search the roles of one layout within the rule the role assignment
     * states, in three steps. Each ends the search when it finds a
     * schedule.
     *
     * 1. The role assignment is asked, and its roles are put to the
     *    constraints in order, the legs after the first as the leg strategy
     *    derives them. Where an event is rejected by a constraint that may
     *    read anything (a closure, a metadata rule, a class of the
     *    caller's), the first-leg seating it comes from is reversed and
     *    fixed, and the role assignment is asked again, with the fixed
     *    seatings in its context (RoleAssignmentContext), for roles that
     *    keep them. This goes on until the constraints accept every event,
     *    or a seating is rejected both ways round, or the role assignment
     *    gives a leg that reverses a fixed seating or that it would not
     *    itself keep. It is exact, and costs one pass over the events for
     *    each seating fixed, where such a constraint fixes the roles of
     *    single pairings whatever else is played: the usual reason to write
     *    one about roles. A rejection by a constraint the library knows to
     *    read the roles a participant had before (the two role constraints)
     *    is not a fixed seating, and ends this step. The events of every
     *    pass count against the caller's allowance of events, so a
     *    constraint that fixes seatings by the hundred in every layout ends
     *    at the bound too: a pass that the allowance stops ends the search
     *    of the layout, and says that a bound stopped it.
     * 2. Where seatings were fixed: the roles of the first leg are searched
     *    (LegRoleSearch) from the roles the role assignment gave last, with
     *    the fixed seatings held and every other event free to be reversed,
     *    each choice held to the rule.
     * 3. The roles of the first leg are searched from the roles the role
     *    assignment gave first, with every event free. This is the step
     *    that shows that no roles exist, when it ends without a bound
     *    stopping it.
     *
     * All three steps are bounded: by the attempts allowed here and the
     * events left of the caller's allowance, and the two searches by the
     * rejected roles left of it as well.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $proposal The first leg as laid out, with
     *                                                                              the roles proposed for it
     * @param array<Participant> $field The field in the order the layout was made from
     * @param int $attemptLimit Events that may be put to the constraints, replays included
     * @param array{rejections: int, attempts: int} $allowance The rejected roles that the two searches may
     *                                                         still spend, and the events that the three
     *                                                         steps may still put to the constraints;
     *                                                         lowered by what they spend
     * @return array{
     *     events: list<Event>|null,
     *     steps: int,
     *     stopped: bool,
     *     slot: array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}|null,
     *     furthestEvents: list<Event>,
     *     furthestRejections: list<array{Event, SchedulingContext}>
     * } The events of every leg, or null; the events put to the constraints; whether a bound stopped a
     *   search; the slot the leg strategy returned no event for; and where the last search was furthest on
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function searchRolesWithinRule(
        array $proposal,
        array $field,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment,
        ConstraintInterface $rule,
        bool $rolesArePreferences,
        bool $contextHoldsOwnRound,
        int $attemptLimit,
        array &$allowance
    ): array {
        $legs = $plan->getLegs();
        $roundsPerLeg = $plan->getRoundsPerLeg();
        $eventsPerLeg = $plan->getEventsPerLeg();
        $start = new SchedulingContext($field, $plan, [], 1);
        $steps = 0;

        $firstRoles = $this->assignedRoles($roleAssignment, $proposal, new RoleAssignmentContext(1, $legs));
        $rounds = $firstRoles;
        /** @var array<string, array{0: int, 1: int}> $fixed */
        $fixed = [];
        $replay = new LegRoleSearch($this->constraints, $strategy, $rolesArePreferences, $contextHoldsOwnRound);

        // One pass for each seating fixed, and a seating is fixed once
        for ($pass = 0; $pass <= $eventsPerLeg; ++$pass) {
            [$legOneEvents, $positions] = $this->eventsOfSeatings($rounds);
            $events = $replay->search(
                $start,
                $this->slotsOfSearchedSchedule($legOneEvents, $legs, $roundsPerLeg, true),
                min($attemptLimit - $steps, $allowance['attempts']),
                LegRoleSearch::REJECTION_LIMIT
            );
            $steps += $replay->attempts();
            $allowance['attempts'] -= $replay->attempts();

            if ($events !== null || $replay->slotWithoutEvent() !== null || $replay->wasStoppedByABound()) {
                return $this->roleSearchResult($events, $replay, $steps);
            }

            $rejected = $replay->furthestSlot();
            if ($rejected !== null && $this->aConstraintThatIgnoresRolesRejects($replay->furthestRejections())) {
                // Rejected for who meets whom and when, which no choice of
                // roles changes in this layout: there is nothing to search
                ++$this->layoutsRejectedWhateverTheRoles;

                return $this->roleSearchResult(null, $replay, $steps);
            }
            if ($rejected === null || ($rejected >= $eventsPerLeg && $rolesArePreferences)) {
                // The later legs of a strategy of preferences were searched
                // and have no roles: no single seating of the first leg is
                // what the constraints rejected.
                break;
            }
            if ($this->aConstraintOnOwnEventsRejects($replay->furthestRejections())) {
                // The verdict turns on the roles the two participants had
                // before, which is a matter for a search
                break;
            }

            [$round, $position] = $positions[$rejected % $eventsPerLeg];
            $key = $round . ':' . $position;
            if (isset($fixed[$key])) {
                // Rejected both ways round
                break;
            }

            $reversed = $this->withSeatingReversed($rounds, $round, $position);
            $withThisOne = $fixed + [$key => [$round, $position]];
            $answer = $this->assignedRoles($roleAssignment, $reversed, new RoleAssignmentContext(1, $legs, array_values($withThisOne)));

            foreach ($withThisOne as [$fixedRound, $fixedPosition]) {
                if ($answer[$fixedRound][$fixedPosition] !== $reversed[$fixedRound][$fixedPosition]) {
                    break 2;
                }
            }
            if ($this->assignedRoles($roleAssignment, $answer, new RoleAssignmentContext(1, $legs)) !== $answer) {
                // Not a leg the role assignment keeps: the fixed seatings
                // leave it none, or it does not read them
                break;
            }

            $fixed = $withThisOne;
            $rounds = $answer;
        }

        $search = new LegRoleSearch(
            $this->constraints,
            $strategy,
            $rolesArePreferences,
            $contextHoldsOwnRound,
            $rule,
            fn(array $legOne): bool => $this->roleAssignmentKeeps($roleAssignment, $legOne, $legs)
        );
        $last = $replay;
        $stopped = false;

        foreach ([[$rounds, $fixed], [$firstRoles, []]] as $step => [$searchedRounds, $held]) {
            if ($step === 0 && $held === []) {
                // Nothing was fixed: the one search is the free one
                continue;
            }
            if ($allowance['rejections'] <= 0 || $allowance['attempts'] <= 0 || $attemptLimit - $steps <= 0) {
                // Nothing is left of an allowance: the search is not made
                $stopped = true;
                break;
            }

            [$legOneEvents, $positions] = $this->eventsOfSeatings($searchedRounds);
            $heldSlots = [];
            foreach ($positions as $slot => [$round, $position]) {
                if (isset($held[$round . ':' . $position])) {
                    $heldSlots[$slot] = true;
                }
            }

            $events = $search->search(
                $start,
                $this->slotsOfSearchedSchedule($legOneEvents, $legs, $roundsPerLeg, $heldSlots),
                min($attemptLimit - $steps, $allowance['attempts']),
                $allowance['rejections']
            );
            $steps += $search->attempts();
            $allowance['rejections'] -= $search->rejections();
            $allowance['attempts'] -= $search->attempts();
            $last = $search;

            if ($events !== null || $search->slotWithoutEvent() !== null) {
                return $this->roleSearchResult($events, $search, $steps);
            }
            $stopped = $stopped || $search->wasStoppedByABound();
        }

        return ['stopped' => $stopped] + $this->roleSearchResult(null, $last, $steps);
    }

    /**
     * Whether one of the rejected events is rejected by a constraint, taken
     * by itself, whose verdict can depend only on the events of the event's
     * own participants (ConstraintPurity::constraintReadsOwnEventsOnly()):
     * a rejection that other roles of earlier events may lift, as against
     * one that says something about this event's own role and nothing more
     * that the scheduler can know.
     *
     * @param list<array{Event, SchedulingContext}> $rejections
     */
    private function aConstraintOnOwnEventsRejects(array $rejections): bool
    {
        if (!$this->constraints instanceof ConstraintSet || $this->constraints::class !== ConstraintSet::class) {
            return false;
        }

        foreach ($rejections as [$event, $context]) {
            foreach ($this->constraints->getConstraints() as $constraint) {
                if (ConstraintPurity::constraintReadsOwnEventsOnly($constraint) && !$constraint->isSatisfied($event, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether one of the rejected events is rejected by a constraint, taken
     * by itself, that reads no position of an event
     * (ConstraintPurity::constraintIgnoresRoles()). The events are those
     * of the slot a replay could get no further than, each with every
     * earlier event of the layout in its context: such a constraint rejects
     * the pairing in that round whatever the roles of any of them, so the
     * layout has no roles the constraints accept.
     *
     * @param list<array{Event, SchedulingContext}> $rejections
     */
    private function aConstraintThatIgnoresRolesRejects(array $rejections): bool
    {
        if (!$this->constraints instanceof ConstraintSet || $this->constraints::class !== ConstraintSet::class) {
            return false;
        }

        foreach ($rejections as [$event, $context]) {
            foreach ($this->constraints->getConstraints() as $constraint) {
                if (ConstraintPurity::constraintIgnoresRoles($constraint) && !$constraint->isSatisfied($event, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the rotation just generated met its first rejection from a
     * constraint that reads no position ($firstRejectionIgnoresRoles, which
     * the generation of the rotation sets).
     */
    private function theFirstRejectionIgnoredRoles(): bool
    {
        return $this->firstRejectionIgnoresRoles === true;
    }

    /**
     * What a search of the roles of one layout came to, as
     * searchRolesWithinRule() returns it.
     *
     * @param list<Event>|null $events
     * @return array{
     *     events: list<Event>|null,
     *     steps: int,
     *     stopped: bool,
     *     slot: array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}|null,
     *     furthestEvents: list<Event>,
     *     furthestRejections: list<array{Event, SchedulingContext}>
     * }
     */
    private function roleSearchResult(?array $events, LegRoleSearch $search, int $steps): array
    {
        return [
            'events' => $events,
            'steps' => $steps,
            'stopped' => $search->wasStoppedByABound(),
            'slot' => $search->slotWithoutEvent(),
            'furthestEvents' => $search->furthestEvents(),
            'furthestRejections' => $search->furthestRejections(),
        ];
    }

    /**
     * The events of a leg given as rounds of seatings, in round order, and
     * for each event the round index and position of its seating. A bye
     * seating gives no event.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return array{list<Event>, list<array{0: int, 1: int}>}
     */
    private function eventsOfSeatings(array $rounds): array
    {
        $events = [];
        $positions = [];
        foreach ($rounds as $index => $seatings) {
            foreach ($seatings as $position => [$first, $second]) {
                if ($first === null || $second === null) {
                    continue;
                }

                $events[] = new Event([$first, $second], new Round($index + 1));
                $positions[] = [$index, $position];
            }
        }

        return [$events, $positions];
    }

    /**
     * The rounds with one seating reversed.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private function withSeatingReversed(array $rounds, int $round, int $position): array
    {
        $reversed = [];
        foreach ($rounds as $index => $seatings) {
            $roundSeatings = [];
            foreach ($seatings as $seatingPosition => $seating) {
                $roundSeatings[] = $index === $round && $seatingPosition === $position
                    ? [$seating[1], $seating[0]]
                    : $seating;
            }
            $reversed[] = $roundSeatings;
        }

        return $reversed;
    }

    /**
     * Search for a schedule after the greedy rotations failed: leg 1 via
     * backtracking over round decompositions and roles, later legs derived
     * from leg 1's actual rounds through the leg strategy.
     *
     * The search crosses the boundary of the first leg
     * (docs/design/backtracking-generation.md). For every first leg the
     * generator finds, the roles are searched with the pairings and rounds
     * held (LegRoleSearch): those of the first leg, which every later leg
     * derives from, and those of the later legs where the leg strategy
     * states them as preferences. A first leg that no roles complete is a
     * dead end, and the generator goes on to the next round decomposition,
     * under the one step budget.
     *
     * The search chooses the roles of leg 1 itself, to satisfy the
     * constraints, and what becomes of them depends on the role assignment:
     *
     * - one that states a rule ($rule) has the roles of every first leg the
     *   generator finds searched within that rule (searchRolesWithinRule()),
     *   the first leg included, so a leg that is returned is one the role
     *   assignment keeps, and a first leg with no such roles is a dead end
     *   like any other;
     * - one that states none is asked about every leg the generator finds,
     *   and roles it changes are checked against the constraints again: a
     *   rejection there is a failure. A first leg whose roles the role
     *   search then changed is put to the role assignment too, and is
     *   returned only when the role assignment keeps it as it is.
     *
     * @param array<Participant> $participants
     * @param int $stepBudget The steps of the search: its budget, or what is left of the events the
     *                        default of the options may still spend on balanced roles
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateWithBacktracking(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        IncompleteScheduleException $greedyFailure,
        RoleAssignmentInterface $roleAssignment,
        bool $rolesArePreferences,
        ?ConstraintInterface $rule,
        int $stepBudget = BacktrackingRoundRobinGenerator::STEP_BUDGET
    ): array {
        // The greedy final attempt's violations stay in the collector: if
        // the search also fails, callers still get constraint-level
        // diagnostics alongside the search-level message (the greedy
        // failure itself rides along as the previous exception).
        $this->roundByes = [];

        $field = $this->shuffled(array_values($participants));

        $roundsPerLeg = $plan->getRoundsPerLeg();
        $legs = $plan->getLegs();
        $roleSearch = new LegRoleSearch($this->constraints, $strategy, $rolesArePreferences, true);
        $roleSearchesStopped = 0;
        $roleSearchesOutOfSteps = 0;
        $roleSearchesRefused = 0;
        /** @var list<Event> $furthestGenerated */
        $furthestGenerated = [];
        /** @var list<array{Event, SchedulingContext}> $furthestRejections */
        $furthestRejections = [];

        // The roles of one layout: the first leg, each event free to be
        // reversed, and after it every later leg, each event derived from
        // the first-leg event of its pairing as the search then has it.
        $searchRoles = function (array $legOneEvents, int $stepsLeft) use (
            $roleAssignment,
            $field,
            $plan,
            $participants,
            $greedyFailure,
            $strategy,
            $roundsPerLeg,
            $legs,
            $roleSearch,
            $rule,
            $rolesArePreferences,
            &$roleSearchesStopped,
            &$roleSearchesOutOfSteps,
            &$roleSearchesRefused,
            &$furthestGenerated,
            &$furthestRejections
        ): array {
            $legOneEvents = array_values($legOneEvents);

            if ($rule instanceof ConstraintInterface) {
                // The role assignment states a rule: the roles of this
                // layout are searched within it, the first leg included,
                // and a leg the search returns is one it keeps.
                $allowance = ['rejections' => LegRoleSearch::REJECTION_LIMIT, 'attempts' => $stepsLeft];
                $found = $this->searchRolesWithinRule(
                    $this->seatingsOf($this->eventsByRound($legOneEvents)),
                    $field,
                    $strategy,
                    $plan,
                    $roleAssignment,
                    $rule,
                    $rolesArePreferences,
                    true,
                    $stepsLeft,
                    $allowance
                );
                $steps = $found['steps'];

                if ($found['events'] !== null) {
                    return ['events' => $found['events'], 'steps' => $steps, 'stopped' => false];
                }

                $generated = $found['furthestEvents'];
                $slot = $found['slot'];
                $rejections = $found['furthestRejections'];
                $stoppedByABound = $found['stopped'];
            } else {
                $events = $roleSearch->search(
                    new SchedulingContext($field, $plan, [], 1),
                    $this->slotsOfSearchedSchedule($legOneEvents, $legs, $roundsPerLeg),
                    $stepsLeft,
                    LegRoleSearch::REJECTION_LIMIT
                );
                $steps = $roleSearch->attempts();

                if ($events !== null) {
                    // A first leg the role search changed is put to the role
                    // assignment as the leg it was made from was. One it would
                    // change again is not returned in its place.
                    $searchedLegOne = array_slice($events, 0, count($legOneEvents));
                    if ($searchedLegOne !== $legOneEvents && !$this->roleAssignmentKeeps($roleAssignment, $searchedLegOne, $legs)) {
                        ++$roleSearchesRefused;

                        return ['events' => null, 'steps' => $steps, 'stopped' => false];
                    }

                    return ['events' => $events, 'steps' => $steps, 'stopped' => false];
                }

                $generated = $roleSearch->furthestEvents();
                $slot = $roleSearch->slotWithoutEvent();
                $rejections = $roleSearch->furthestRejections();
                $stoppedByABound = $roleSearch->wasStoppedByABound();
            }

            if ($slot !== null) {
                // No other first leg is tried: nothing says the strategy
                // would return an event for one, and a strategy that never
                // does would cost the whole budget to find out.
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($generated),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    sprintf(
                        'Backtracking found a first leg, but %s returned no event for %s in round %d of leg %d.',
                        get_debug_type($strategy),
                        implode(' vs ', array_map(static fn(Participant $participant): string => $participant->getId(), $slot['pairing'])),
                        $slot['round'],
                        $slot['leg']
                    ),
                    0,
                    $greedyFailure,
                    $this->analyzeFailure($participants, $plan, $generated)
                );
            }

            if (count($generated) >= count($furthestGenerated)) {
                $furthestGenerated = $generated;
                $furthestRejections = $rejections;
            }

            $outOfSteps = $stoppedByABound && $steps >= $stepsLeft;
            if ($stoppedByABound && !$outOfSteps) {
                ++$roleSearchesStopped;
            }
            if ($outOfSteps) {
                ++$roleSearchesOutOfSteps;
            }

            return ['events' => null, 'steps' => $steps, 'stopped' => $outOfSteps];
        };

        // The layout the rotations started from, with its roles searched:
        // tried once, after the first leg the generator finds has failed
        // or when it finds none within its budget. A constraint on roles
        // seldom needs other pairings in a round, and the generator comes
        // to the roles of a large field's early rounds last.
        //
        // Where the role assignment states a rule and the field is not
        // shuffled, that search has been made already: it is the first of
        // the layouts whose roles were searched before this search began.
        $circleLayoutTried = $rule instanceof ConstraintInterface && !$this->randomizer instanceof Randomizer;
        /** @var array<string, true> $layoutsTried */
        $layoutsTried = [];
        /** @var array<int, string>|null $circleLayoutByes */
        $circleLayoutByes = null;
        $searchCircleLayout = function (int $stepsLeft) use (
            $field,
            $roleAssignment,
            $roundsPerLeg,
            $legs,
            $searchRoles,
            &$circleLayoutTried,
            &$circleLayoutByes,
            &$layoutsTried
        ): array {
            $circleLayoutTried = true;
            [$legOneEvents, $byes] = $this->circleLayoutLeg($field, $roleAssignment, $legs);

            $layout = $this->layoutKey($legOneEvents);
            if (isset($layoutsTried[$layout])) {
                return ['events' => null, 'steps' => 0, 'stopped' => false];
            }
            $layoutsTried[$layout] = true;

            $outcome = $searchRoles($legOneEvents, $stepsLeft);

            if ($outcome['events'] !== null) {
                $circleLayoutByes = [];
                foreach ($byes as $legRound => $participantId) {
                    for ($leg = 1; $leg <= $legs; ++$leg) {
                        $circleLayoutByes[(($leg - 1) * $roundsPerLeg) + $legRound] = $participantId;
                    }
                }
            }

            return $outcome;
        };

        $completion = function (array $legOneEvents, array $roundByes, int $stepsLeft) use (
            $roleAssignment,
            $field,
            $plan,
            $participants,
            $greedyFailure,
            $legs,
            $rule,
            $searchRoles,
            $searchCircleLayout,
            &$circleLayoutTried,
            &$layoutsTried
        ): array {
            // The generator reaches the same pairings in the same rounds
            // once for every choice of roles the constraints allow the
            // first leg. The role search has been through all of those for
            // a layout it has seen, so the layout is not searched twice.
            $layout = $this->layoutKey($legOneEvents);
            if (isset($layoutsTried[$layout])) {
                return ['events' => null, 'steps' => 0, 'stopped' => false];
            }
            $layoutsTried[$layout] = true;

            if (!$rule instanceof ConstraintInterface) {
                $legOneEvents = $this->reviseSearchedRoles($legOneEvents, $roleAssignment, $field, $plan, $participants, $greedyFailure);

                if ($legs === 1) {
                    return ['events' => $legOneEvents, 'steps' => 0, 'stopped' => false];
                }
            }

            $outcome = $searchRoles($legOneEvents, $stepsLeft);
            if ($outcome['events'] !== null || $outcome['stopped'] || $circleLayoutTried) {
                return $outcome;
            }

            $circleOutcome = $searchCircleLayout($stepsLeft - $outcome['steps']);

            return [
                'events' => $circleOutcome['events'],
                'steps' => $outcome['steps'] + $circleOutcome['steps'],
                'stopped' => $circleOutcome['stopped'],
            ];
        };

        $generator = new BacktrackingRoundRobinGenerator($this->constraints, $stepBudget);
        $allEvents = $generator->generateSchedule($field, $plan, $completion);
        $generatorFoundNoLeg = $layoutsTried === [];

        if ($allEvents === null && !$circleLayoutTried && $legs > 1 && $generator->wasBudgetExhausted()) {
            // The generator spent its budget before it found one first
            // leg. The roles of the circle layout are searched all the
            // same, with as many attempts again and within the rejection
            // limit of a role search.
            $allEvents = $searchCircleLayout($stepBudget)['events'];
        }

        if ($allEvents === null) {
            $this->searchStoppedAtABound = $generator->wasBudgetExhausted() || $roleSearchesStopped > 0 || $roleSearchesOutOfSteps > 0;
            if ($this->searchStoppedAtABound && $this->attemptsAllowed !== null) {
                $this->searchWasCutShort = true;
            }

            foreach ($furthestRejections as [$rejected, $rejectedContext]) {
                $this->recordConstraintViolation($rejected, $rejectedContext);
            }

            throw new IncompleteScheduleException(
                $plan->getExpectedEventCount(),
                count($furthestGenerated),
                $this->violationCollector,
                $plan,
                $participants,
                $this->describeBacktrackingFailure($generator, $strategy, $roleAssignment, $roleSearchesStopped, $roleSearchesOutOfSteps, $roleSearchesRefused, $generatorFoundNoLeg ? 0 : count($layoutsTried), $rule instanceof ConstraintInterface),
                0,
                $greedyFailure,
                $this->analyzeFailure($participants, $plan, $furthestGenerated)
            );
        }

        $this->roundByes = [];
        if ($circleLayoutByes !== null) {
            $this->roundByes = $circleLayoutByes;
        } else {
            foreach ($generator->getRoundByes() as $legRound => $participantId) {
                for ($leg = 1; $leg <= $legs; ++$leg) {
                    $this->roundByes[(($leg - 1) * $roundsPerLeg) + $legRound] = $participantId;
                }
            }
        }

        // The search succeeded: the greedy attempt's violations would be
        // stale diagnostics on a complete schedule.
        $this->clearViolations();

        return $allEvents;
    }

    /**
     * A key for who meets whom in which round of a leg, whatever the roles
     * and whatever the order of the events: two legs have the same key
     * exactly when they differ in nothing else.
     *
     * @param array<Event> $legEvents
     */
    private function layoutKey(array $legEvents): string
    {
        $meetings = [];
        foreach ($legEvents as $event) {
            $pair = $event->getParticipants();
            $pairKey = PairKey::of($pair[0]->getId(), $pair[1]->getId());
            // Length-prefixed, so that no two lists of keys read the same
            $meetings[] = ($event->getRound()?->getNumber() ?? 0) . ':' . strlen($pairKey) . ':' . $pairKey;
        }
        sort($meetings, SORT_STRING);

        return implode('', $meetings);
    }

    /**
     * The first leg as the circle method lays it out from the field in the
     * order given, with the roles the role assignment gives it and no
     * constraint asked.
     *
     * @param list<Participant> $field
     * @return array{list<Event>, array<int, string>} The events in round order, and the ID of the
     *                                                participant sitting out by round number
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function circleLayoutLeg(array $field, RoleAssignmentInterface $roleAssignment, int $legs): array
    {
        $participantList = $field;
        if (count($participantList) % 2 !== 0) {
            $participantList[] = null; // null represents "bye"
        }

        $events = [];
        $byes = [];
        foreach ($this->assignedRoles($roleAssignment, $this->circleLayout($participantList), new RoleAssignmentContext(1, $legs)) as $index => $seatings) {
            $round = $index + 1;
            foreach ($seatings as [$participant1, $participant2]) {
                if ($participant1 === null || $participant2 === null) {
                    $sittingOut = $participant1 ?? $participant2;
                    if ($sittingOut !== null) {
                        $byes[$round] = $sittingOut->getId();
                    }
                    continue;
                }

                $events[] = new Event([$participant1, $participant2], new Round($round));
            }
        }

        return [$events, $byes];
    }

    /**
     * The slots of a schedule whose first leg a backtracking search found:
     * the events of that leg, each free to be reversed, and after them one
     * slot in every later leg for each of them, in the same order.
     *
     * @param list<Event> $legOneEvents The leg's events in round order
     * @param array<int, true>|bool $fixed The events of the first leg that keep the roles they have: all of
     *                                     them, none, or those at these indexes
     * @return list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}>
     */
    private function slotsOfSearchedSchedule(array $legOneEvents, int $legs, int $roundsPerLeg, array|bool $fixed = false): array
    {
        $slots = [];
        for ($leg = 1; $leg <= $legs; ++$leg) {
            foreach ($legOneEvents as $source => $legOneEvent) {
                $slots[] = $this->slotOfSearchedSchedule(
                    $legOneEvent,
                    $source,
                    $leg,
                    $roundsPerLeg,
                    is_array($fixed) ? isset($fixed[$source]) : $fixed
                );
            }
        }

        return $slots;
    }

    /**
     * @return array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}
     */
    private function slotOfSearchedSchedule(Event $legOneEvent, int $source, int $leg, int $roundsPerLeg, bool $firstLegIsFixed): array
    {
        $slot = [
            'leg' => $leg,
            'round' => (($leg - 1) * $roundsPerLeg) + ($legOneEvent->getRound()?->getNumber() ?? 0),
            'pairing' => $legOneEvent->getParticipants(),
        ];

        if ($leg === 1) {
            $slot['event'] = $legOneEvent;
            if ($firstLegIsFixed) {
                $slot['fixed'] = true;
            }
        } else {
            $slot['source'] = $source;
        }

        return $slot;
    }

    /**
     * The message of a backtracking search that returned no schedule. It
     * says "unsatisfiable" only when the whole search space was tried and
     * no bound stopped any part of the search, and that the constraints
     * reject the later legs of a first leg whatever its roles only of a
     * first leg whose roles it went through.
     *
     * @param int $roleSearchesStopped First legs whose role search stopped at its limit of rejected roles
     * @param int $roleSearchesOutOfSteps First legs whose role search stopped because the step budget was spent
     * @param int $roleSearchesRefused First legs for which roles were found that the role assignment would change
     * @param int $layoutsTried First legs whose roles were searched; 0 when the generator found none
     */
    private function describeBacktrackingFailure(
        BacktrackingRoundRobinGenerator $generator,
        LegStrategyInterface $strategy,
        RoleAssignmentInterface $roleAssignment,
        int $roleSearchesStopped,
        int $roleSearchesOutOfSteps,
        int $roleSearchesRefused,
        int $layoutsTried,
        bool $withinRule
    ): string {
        if ($layoutsTried === 0) {
            return $generator->wasBudgetExhausted()
                ? 'Backtracking search exhausted its step budget ('
                    . BacktrackingRoundRobinGenerator::STEP_BUDGET
                    . ' pairing attempts) without finding a complete schedule. The configuration may be unsatisfiable or may need a different participant order.'
                : 'Backtracking search exhausted the search space: no round decomposition of the first leg satisfies the constraints, so the configuration is unsatisfiable.';
        }

        // Under a rule of the role assignment the roles searched are the
        // ones it keeps, and the first leg is among what was searched
        $roles = $withinRule
            ? sprintf('whatever roles %s keeps for the first leg', get_debug_type($roleAssignment))
            : 'whatever the roles of the first leg';
        $rejected = $withinRule
            ? sprintf('the constraints reject the first leg, or the later legs that %s derives from it,', get_debug_type($strategy))
            : sprintf('the constraints reject the later legs that %s derives', get_debug_type($strategy));
        $found = ($withinRule ? 'in ' : 'from ') . ($layoutsTried === 1
            ? 'the one round decomposition of the first leg it tried, '
            : "each of the {$layoutsTried} round decompositions of the first leg it tried, ") . $roles;

        $stoppedAtABound = $roleSearchesStopped + $roleSearchesOutOfSteps;
        $shown = $layoutsTried - $stoppedAtABound - $roleSearchesRefused;

        if ($generator->wasBudgetExhausted() && $shown < $layoutsTried) {
            // The search went through every choice of roles for $shown of
            // the first legs only. Of the others it says what stopped it,
            // and not that no roles exist.
            $outcomes = [];
            if ($shown > 0) {
                $outcomes[] = $withinRule
                    ? sprintf('for %d %s %s', $shown, $rejected, $roles)
                    : sprintf('for %d %s, %s', $shown, $rejected, $roles);
            }
            if ($stoppedAtABound > 0) {
                $outcomes[] = sprintf('for %d the search for roles stopped at a bound (%d rejected roles, or the step budget)', $stoppedAtABound, LegRoleSearch::REJECTION_LIMIT);
            }
            if ($roleSearchesRefused > 0) {
                $outcomes[] = sprintf('for %d roles exist that satisfy the constraints in every leg, and %s would change them', $roleSearchesRefused, get_debug_type($roleAssignment));
            }

            return sprintf(
                'Backtracking search exhausted its step budget (%d pairing attempts) without finding a complete schedule. It tried %s of the first leg%s: %s. The configuration may be unsatisfiable, or roles beyond a bound or a first leg beyond the budget may have later legs the constraints accept.',
                BacktrackingRoundRobinGenerator::STEP_BUDGET,
                $layoutsTried === 1 ? '1 round decomposition' : "{$layoutsTried} round decompositions",
                $shown === 0 ? ', and for none of them did it get through every choice of roles' : '',
                implode('; ', $outcomes)
            );
        }

        if ($generator->wasBudgetExhausted()) {
            return sprintf(
                'Backtracking search exhausted its step budget (%d pairing attempts) without finding a complete schedule: %s %s. The configuration may be unsatisfiable, or a first leg beyond the budget may have later legs the constraints accept.',
                BacktrackingRoundRobinGenerator::STEP_BUDGET,
                $rejected,
                $found
            );
        }

        if ($roleSearchesStopped > 0) {
            return sprintf(
                'Backtracking search tried every round decomposition of the first leg without finding a complete schedule, but for %d of the %d it tried the search for roles stopped at its bound of %d rejected roles. Roles that satisfy the constraints may exist beyond that bound.',
                $roleSearchesStopped,
                $layoutsTried,
                LegRoleSearch::REJECTION_LIMIT
            );
        }

        if ($roleSearchesRefused > 0) {
            return sprintf(
                'Backtracking search tried every round decomposition of the first leg without finding a complete schedule that %s keeps: for %d of the %d it tried, roles exist that satisfy the constraints in every leg, and the role assignment would change them. Use the round-parity role assignment, or one that honours the constraints.',
                get_debug_type($roleAssignment),
                $roleSearchesRefused,
                $layoutsTried
            );
        }

        return sprintf(
            'Backtracking search exhausted the search space: %s %s, so the configuration is unsatisfiable with this leg strategy%s.',
            $rejected,
            $found,
            $withinRule ? ' and this role assignment' : ''
        );
    }

    /**
     * Build the deep failure analysis for a throw site: which constraint
     * blocks which missing pairing where, probed against the events that
     * were actually generated. Only meaningful with constraints
     * configured; unconstrained generation cannot fail on pairings.
     *
     * Null as well for a rotation attempt the retry loop goes on from, when
     * the constraints cannot tell (see generateScheduleWithRetries()): that
     * exception is caught and dropped, and nothing reads its report. What
     * it would have been built from is kept, for the one case in which it
     * is wanted after all (analysisOfTheLastAttempt()).
     *
     * @param array<Participant> $participants
     * @param array<Event> $partialEvents
     */
    private function analyzeFailure(array $participants, RoundRobinPlan $plan, array $partialEvents): ?DiagnosticReport
    {
        if ($this->constraints === null || $this->failureIsNeverShown) {
            return null;
        }

        if ($this->failureWillBeDiscarded) {
            $this->unanalysedFailure = [$participants, $plan, $partialEvents];

            return null;
        }

        return new SchedulingDiagnostics()->analyzeSchedulingFailure(
            $participants,
            $this->constraints,
            $partialEvents,
            $plan
        );
    }

    /**
     * The failure analysis of the last attempt of the rotations, built now
     * where it was left unbuilt because a search of roles was to follow.
     * Null without constraints, and where no analysis was left unbuilt.
     */
    private function analysisOfTheLastAttempt(): ?DiagnosticReport
    {
        if ($this->constraints === null || $this->unanalysedFailure === null || $this->failureIsNeverShown) {
            return null;
        }

        [$participants, $plan, $partialEvents] = $this->unanalysedFailure;
        $this->unanalysedFailure = null;

        return new SchedulingDiagnostics()->analyzeSchedulingFailure($participants, $this->constraints, $partialEvents, $plan);
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
        RoleAssignmentInterface $roleAssignment,
        bool $rolesArePreferences
    ): array {
        $allEvents = [];
        $this->roundByes = [];
        $legs = $plan->getLegs();
        $context = new SchedulingContext($participants, $plan, [], 1);

        $expectedEventsPerLeg = $plan->getEventsPerLeg();

        for ($leg = 1; $leg <= $legs; ++$leg) {
            // Roles that are preferences are searched over all the later
            // legs at once: an event of the last leg can need another role
            // for an event of the second. Without constraints nothing is
            // ever rejected, and the legs are generated one by one as
            // they always were.
            if ($leg === 2 && $rolesArePreferences && $this->constraints !== null) {
                return [
                    ...$allEvents,
                    ...$this->generateLaterLegsWithRoleSearch($participants, $strategy, $plan, $roleAssignment, $allEvents),
                ];
            }

            $this->pairingsWithoutEvent = 0;
            $legEvents = $this->generateLegWithFullContext($participants, $leg, $strategy, $plan, $context, $roleAssignment);

            // Check if we generated the expected number of events for this leg
            if (count($legEvents) < $expectedEventsPerLeg) {
                // A leg is short for one of two reasons, and the message
                // names the one that applies: the leg strategy returned no
                // event for a pairing, or a constraint rejected the event.
                $rejected = $expectedEventsPerLeg - count($legEvents) - $this->pairingsWithoutEvent;
                $cause = match (true) {
                    $this->pairingsWithoutEvent === 0 => 'Constraints may be preventing complete schedule generation.',
                    $rejected > 0 => "The leg strategy returned no event for {$this->pairingsWithoutEvent} of the pairings of the leg, and constraints rejected {$rejected} more.",
                    default => "The leg strategy returned no event for {$this->pairingsWithoutEvent} of the pairings of the leg.",
                };

                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($allEvents) + count($legEvents),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. {$cause}"
                        . ($leg > 1 ? $this->adviceOnRolesOfARule($strategy, $leg) : ''),
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
     * What to say, after the failure of a later leg whose roles are a rule
     * of the leg strategy, to a caller whose constraint on roles rejected
     * one of its events: the roles follow from the first leg, and the
     * option that searches those is `backtracking`. Empty when no
     * constraint this library knows to read roles rejected an event: a
     * closure or a class of the caller's says nothing about what it reads,
     * and other roles do not help a constraint that reads none.
     *
     * Read from the rejections already recorded for the attempt, which are
     * those of its later legs (a first leg that loses an event fails
     * before them), so no constraint is asked anything for it.
     *
     * Empty as well where a search of the roles of the first leg follows
     * the attempt (the role assignment states a rule): the failure that
     * reaches the caller then says what that search came to.
     */
    private function adviceOnRolesOfARule(LegStrategyInterface $strategy, int $leg): string
    {
        if ($this->aRoleSearchFollows) {
            return '';
        }

        foreach ($this->violationCollector->getViolations() as $violation) {
            $constraint = $violation->constraint;
            if (!$constraint instanceof ConsecutiveRoleConstraint && !$constraint instanceof RoleBalanceConstraint) {
                continue;
            }

            return sprintf(
                ' A constraint on roles (%s) rejected an event of leg %d in the roles %s gives it, which follow from the roles of leg 1 by a rule. The roles of leg 1 were not searched: RoundRobinOptions(backtracking: true) searches them.',
                $constraint->getName(),
                $leg,
                get_debug_type($strategy)
            );
        }

        return '';
    }

    /**
     * Generate every leg after the first for a leg strategy whose roles
     * are preferences, searching those roles where the constraints reject
     * them (LegRoleSearch). The legs are laid out as the first one
     * was, and the role assignment is asked about each.
     *
     * @param array<Participant> $participants
     * @param array<Event> $legOneEvents
     * @return list<Event> The events of legs 2 and up
     * @throws IncompleteScheduleException When no roles satisfy the constraints in this layout, the
     *                                     search stopped at its bound, or the strategy returned no event
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function generateLaterLegsWithRoleSearch(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        RoleAssignmentInterface $roleAssignment,
        array $legOneEvents
    ): array {
        $layout = $this->firstLegLayout;
        $roundsPerLeg = $plan->getRoundsPerLeg();
        $legs = $plan->getLegs();

        $slots = [];
        for ($leg = 2; $leg <= $legs; ++$leg) {
            $roundOffset = ($leg - 1) * $roundsPerLeg;

            foreach ($this->assignedRoles($roleAssignment, $layout, new RoleAssignmentContext($leg, $legs)) as $index => $seatings) {
                $globalRound = $index + 1 + $roundOffset;

                foreach ($seatings as [$participant1, $participant2]) {
                    if ($participant1 === null || $participant2 === null) {
                        $sittingOut = $participant1 ?? $participant2;
                        if ($sittingOut !== null) {
                            $this->roundByes[$globalRound] = $sittingOut->getId();
                        }
                        continue;
                    }

                    $slots[] = ['leg' => $leg, 'round' => $globalRound, 'pairing' => [$participant1, $participant2]];
                }
            }
        }

        $search = new LegRoleSearch($this->constraints, $strategy, true, false);
        $events = $search->search(
            new SchedulingContext($participants, $plan, $legOneEvents, 2),
            $slots,
            // With a schedule in hand the search has what is left of the
            // events that may still be put to the constraints, as every
            // other search for balanced roles has
            $this->attemptsAllowed === null ? PHP_INT_MAX : max(0, $this->attemptsAllowed - $this->attemptsSpent),
            LegRoleSearch::REJECTION_LIMIT
        );
        $this->attemptsSpent += $search->attempts();

        if ($events !== null) {
            return $events;
        }

        if ($this->attemptsAllowed !== null && $search->wasStoppedByABound()) {
            // Within what is left of the allowance, which is far below the
            // rejection limit, a bound that stops the search is the
            // allowance
            $this->searchWasCutShort = true;
        }

        foreach ($search->furthestRejections() as [$rejected, $rejectedContext]) {
            $this->recordConstraintViolation($rejected, $rejectedContext);
        }
        $generated = [...$legOneEvents, ...$search->furthestEvents()];

        throw new IncompleteScheduleException(
            $plan->getExpectedEventCount(),
            count($generated),
            $this->violationCollector,
            $plan,
            $participants,
            $this->describeRoleSearchFailure($search, $strategy, $legs),
            0,
            null,
            $this->analyzeFailure($participants, $plan, $generated)
        );
    }

    /**
     * The message of a role search of the later legs that returned no
     * events: which of its three reasons it was, and what was and was not
     * searched. Where a search of the roles of the first leg follows the
     * attempt (the role assignment states a rule), it says of the first leg
     * only that its roles were the ones it had: the failure that reaches
     * the caller goes on to say what that search came to.
     */
    private function describeRoleSearchFailure(LegRoleSearch $search, LegStrategyInterface $strategy, int $legs): string
    {
        $range = $legs === 2 ? 'leg 2' : "legs 2 to {$legs}";
        $slot = $search->slotWithoutEvent();

        if ($slot !== null) {
            return sprintf(
                'Failed to generate complete schedule for leg %d: %s returned no event for %s in round %d.',
                $slot['leg'],
                get_debug_type($strategy),
                implode(' vs ', array_map(static fn(Participant $participant): string => $participant->getId(), $slot['pairing'])),
                $slot['round']
            );
        }

        if ($search->wasStoppedByABound()) {
            return sprintf(
                'Failed to generate complete schedule for %s: the search for roles the constraints accept stopped at its bound of %d rejected roles. Roles that satisfy the constraints may exist beyond it%s',
                $range,
                LegRoleSearch::REJECTION_LIMIT,
                $this->aRoleSearchFollows
                    ? ', with the roles leg 1 has in this layout.'
                    : '; the pairings, their rounds and the roles of leg 1 were not searched, and RoundRobinOptions(backtracking: true) searches them.'
            );
        }

        return sprintf(
            'Failed to generate complete schedule for %s: the constraints reject every choice of roles for the events of %s in this layout%s',
            $range,
            $range,
            $this->aRoleSearchFollows
                ? ', with the roles leg 1 has in it.'
                : '. The pairings, their rounds and the roles of leg 1 were not searched: RoundRobinOptions(backtracking: true) searches them.'
        );
    }

    /**
     * Generate events for a specific leg with full tournament context.
     *
     * It records as it goes: the byes of the leg's rounds, the constraint
     * rejections, and the number of pairings the leg strategy returned no
     * event for.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     *
     * @phpstan-impure
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

        // The leg has the layout of the first leg, whatever order of the
        // field that was laid out from. The role assignment decides its
        // roles as it does for the first leg; the leg strategy then derives
        // this leg's roles from them.
        $rounds = $this->assignedRoles($roleAssignment, $this->firstLegLayout, new RoleAssignmentContext($leg, $plan->getLegs()));

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

                if ($event === null) {
                    ++$this->pairingsWithoutEvent;
                }

                if ($event !== null) {
                    // Check constraints with full tournament context (all previous events)
                    if ($this->shouldAddEventWithFullConstraints($event, $context)) {
                        $legEvents[] = $event;
                        $roundEvents[] = $event;
                    } else {
                        // If constraint fails, record violation for diagnostics
                        $this->recordConstraintViolation($event, $context);

                        if ($this->failureIsNeverShown) {
                            // The leg is short of an event, and nobody
                            // reads what else the constraints reject
                            return $legEvents;
                        }
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
    private function assignedRoles(RoleAssignmentInterface $roleAssignment, array $rounds, RoleAssignmentContext $context): array
    {
        $assigned = $roleAssignment->assignRoles($rounds, $context);

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
     * The events of one leg as a list of rounds, in the order given.
     *
     * @param array<Event> $legEvents The leg's events in round order
     * @return list<list<Event>>
     */
    private function eventsByRound(array $legEvents): array
    {
        $eventsByRound = [];
        foreach ($legEvents as $event) {
            $eventsByRound[$event->getRound()?->getNumber() ?? 0][] = $event;
        }

        return array_values($eventsByRound);
    }

    /**
     * The rounds of a leg as the seatings a role assignment is asked about.
     *
     * @param list<list<Event>> $eventsByRound
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private function seatingsOf(array $eventsByRound): array
    {
        $rounds = [];
        foreach ($eventsByRound as $roundEvents) {
            $seatings = [];
            foreach ($roundEvents as $event) {
                $pair = $event->getParticipants();
                $seatings[] = [$pair[0], $pair[1]];
            }
            $rounds[] = $seatings;
        }

        return $rounds;
    }

    /**
     * Whether the role assignment, asked about this first leg, returns it
     * with every role as it is.
     *
     * @param array<Event> $legOneEvents The leg's events in round order
     * @throws InvalidConfigurationException When the role assignment changes more than roles
     */
    private function roleAssignmentKeeps(RoleAssignmentInterface $roleAssignment, array $legOneEvents, int $legs): bool
    {
        $rounds = $this->seatingsOf($this->eventsByRound($legOneEvents));

        return $this->assignedRoles($roleAssignment, $rounds, new RoleAssignmentContext(1, $legs)) === $rounds;
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
        $eventsByRound = $this->eventsByRound($legOneEvents);
        $assigned = $this->assignedRoles($roleAssignment, $this->seatingsOf($eventsByRound), new RoleAssignmentContext(1, $plan->getLegs()));

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
        $this->firstLegLayout = $this->circleLayout($participantList);
        $rounds = $this->assignedRoles(
            $roleAssignment,
            $this->firstLegLayout,
            new RoleAssignmentContext($baseContext->getCurrentLeg(), $baseContext->getPlan()->getLegs() ?? 1)
        );

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
                    ++$this->attemptsSpent;
                    if ($this->constraints->isSatisfied($event, $context)) {
                        $events[] = $event;
                    } else {
                        $this->firstRejectionIgnoresRoles ??= $this->aConstraintThatIgnoresRolesRejects([[$event, $context]]);

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

                        if ($this->failureIsNeverShown) {
                            // The leg is short of an event, and nobody
                            // reads what else the constraints reject
                            return $events;
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

        ++$this->attemptsSpent;

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

        $this->firstRejectionIgnoresRoles ??= $this->aConstraintThatIgnoresRolesRejects([[$event, $context]]);

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

        $participants = $this->shuffled(array_values($participants));

        if ($hasBye) {
            $participants[] = null;
        }

        return $participants;
    }

    /**
     * The list in the order of the next shuffle of this schedule() call,
     * or as given without a randomizer.
     *
     * A shuffle is drawn from the randomizer once, as an order of
     * positions, and kept for the call ($draws): the first time a run of
     * generation comes to its n-th layout the randomizer is asked, and a
     * later run that starts over (startOver()) reads the same order for
     * its n-th layout. The randomizer moves on by the shuffles a call drew
     * and no further, however many runs read them. Randomizer::shuffleArray()
     * chooses positions and reads no value, so an order drawn for the
     * positions puts a list in the order a shuffle of the list itself
     * would have, drawn at the same point.
     *
     * @template T
     * @param list<T> $list
     * @return list<T>
     */
    private function shuffled(array $list): array
    {
        if (!$this->randomizer instanceof Randomizer) {
            return $list;
        }

        $count = count($list);
        // A run reads the orders one after the other, so the one that is
        // missing is the next to draw
        $this->draws[$this->drawCursor] ??= array_values($this->randomizer->shuffleArray(array_keys($list)));

        $order = $this->draws[$this->drawCursor];
        if (count($order) !== $count) {
            // Not reached: every shuffle of a call is of the whole field
            throw new InvariantViolationException('A shuffle of another size was kept for this layout');
        }
        ++$this->drawCursor;

        $shuffled = [];
        foreach ($order as $position) {
            $shuffled[] = $list[$position];
        }

        return $shuffled;
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
