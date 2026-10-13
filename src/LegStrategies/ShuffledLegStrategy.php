<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use stdClass;

/**
 * Strategy that shuffles participant roles for each leg.
 *
 * This strategy randomizes the positional order of participants in each
 * pairing for every leg after the first, creating varied encounters
 * across legs while maintaining the same participant combinations.
 *
 * Only roles are drawn: who meets whom, and in which round, is the
 * scheduler's layout and is not shuffled.
 *
 * A draw is a preference, not a rule, and the strategy says so in its
 * plan (LegPlanContribution::$rolesArePreferences). Without constraints,
 * or where the constraints accept every event as drawn, the schedule has
 * exactly the roles that were drawn. Where they reject an event as drawn,
 * RoundRobinScheduler gives it the other role, and when they reject that
 * too it changes the roles of earlier events of the later legs until every
 * event is accepted: the role drawn for an event is the one tried first,
 * every time. So a role constraint fails a schedule of shuffled legs only
 * when no roles of the later legs satisfy it in that layout, or when the
 * search stops at its bound and says so (RoundRobinScheduler states the
 * bounds). Nothing is drawn again for any of this: one integer for each
 * event the scheduler comes to, in the order of the events, whatever the
 * constraints do. The same seed therefore gives the same schedule for the
 * same input and constraints, searched or not.
 *
 * The draws need a source, and the strategy is given exactly one of two:
 *
 * - a seed, an integer. Every call of planLegs() then starts the draws
 *   again from a new `Random\Randomizer` on the `Xoshiro256StarStar`
 *   engine, seeded with that integer; the engine is part of the output for
 *   a seed. The scheduler calls planLegs() once at the start of every
 *   schedule() call, so the same seed gives the same roles for the same
 *   input on every call and in every process, and the seed is plain data,
 *   which RoundRobinOptions reads and writes as `strategy_seed`. The roles
 *   are those the strategy would give once, were it handed
 *   `new Randomizer(new Xoshiro256StarStar($seed))`;
 * - a `Random\Randomizer`, which the strategy holds and draws from on
 *   every call, and never starts again. The roles then repeat only from a
 *   randomizer seeded the same and asked the same calls in the same order,
 *   so one strategy used for two schedules gives two different sets of
 *   roles.
 *
 * Either way a draw depends on the draws before it and on nothing about
 * the participants: not their IDs, and not their seeds.
 *
 * The restart of a seed happens in planLegs() and nowhere else. A class
 * that extends this one and overrides planLegs() calls the parent's, and a
 * strategy that wraps this one passes planLegs() on to it; otherwise the
 * draws of a seed go on from one schedule() call to the next, and the
 * second schedule is not the first. A clone of a strategy built from a
 * seed has draws of its own, which start at the seed: neither the clone
 * nor the original moves the other on or back. A clone of a strategy that
 * holds a randomizer holds the same randomizer object.
 *
 * There is no third way: the strategy never makes an unseeded randomizer
 * of its own. A caller who wants roles that differ from run to run passes
 * `new Randomizer()` and has said so.
 *
 * @api
 */
readonly class ShuffledLegStrategy implements LegStrategyInterface
{
    /**
     * Holds the randomizer of the current run of draws from the seed, as
     * its `randomizer` property; the property is null until the first draw
     * of a run. A holder, because the class is readonly and the randomizer
     * is replaced whenever a run starts.
     */
    private stdClass $seededDraws;

    /**
     * @param Randomizer|null $randomizer A source the strategy draws from on every call, one
     *                                    integer for each event of a later leg
     * @param int|null $seed Any integer; each one names one sequence of draws, which
     *                       starts again whenever planLegs() is called
     * @throws InvalidConfigurationException When neither a randomizer nor a seed is given, or both are
     */
    public function __construct(
        private ?Randomizer $randomizer = null,
        public ?int $seed = null
    ) {
        $this->seededDraws = new stdClass();
        $this->seededDraws->randomizer = null;

        if (($randomizer === null) === ($seed === null)) {
            throw new InvalidConfigurationException(
                $randomizer === null
                    ? 'The shuffled leg strategy needs a seed or a randomizer: it does not draw from an unseeded source of its own'
                    : 'The shuffled leg strategy takes a seed or a randomizer, not both',
                ['seed' => $seed, 'randomizer_given' => $randomizer !== null],
                reason: InvalidConfigurationReason::IncompatibleOptions,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }
    }

    /**
     * Gives a clone its own run of draws, starting at the seed. Without
     * this the clone would share the original's holder, and a plan made
     * on either would start the other's draws again.
     */
    public function __clone()
    {
        $this->seededDraws = new stdClass();
        $this->seededDraws->randomizer = null;
    }

    /**
     * States that roles do not mirror across legs, that randomization is
     * needed, and that the roles it gives are preferences: the scheduler
     * may reverse an event of a later leg that the constraints reject. No
     * configuration is unsatisfiable for this strategy, and it reads none
     * of its arguments.
     *
     * A strategy built from a seed starts its draws again here, so that
     * the schedule() call this plan opens draws what every other one does.
     * A strategy that holds a randomizer leaves it as it is.
     */
    #[Override]
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution {
        $this->seededDraws->randomizer = null;

        return new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
            requiresRandomization: true,
            rolesArePreferences: true
        );
    }

    /**
     * The pairing in the given round: in the given order for leg 1, and
     * for every later leg in the given order or reversed with equal
     * probability. One integer is drawn for each call with a later leg,
     * none for leg 1: from the randomizer the strategy holds, or from the
     * one it made from its seed for the draws since the last planLegs()
     * call (since construction, when there was none). The context is not
     * read.
     *
     * @return Event|null Null when not given exactly two participants
     */
    #[Override]
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event {
        if (count($participants) !== 2) {
            return null; // Shuffled strategy only works with 2 participants
        }

        $roundObject = new Round($round);

        if ($leg === 1) {
            // First leg: use original order
            return new Event($participants, $roundObject);
        }

        // Subsequent legs: randomly shuffle the pairing order
        $randomizer = $this->randomizer ?? $this->seededRandomizer();

        if ($randomizer->getInt(0, 1) === 1) {
            return new Event([
                $participants[1],
                $participants[0],
            ], $roundObject);
        }

        return new Event($participants, $roundObject);
    }

    /**
     * The randomizer of the current run of draws from the seed: the one
     * the run already has, or a new one that starts the run.
     */
    private function seededRandomizer(): Randomizer
    {
        $current = $this->seededDraws->randomizer;

        if (!$current instanceof Randomizer) {
            // The constructor refuses a strategy with neither source, so the
            // seed is an integer here. The engine reads null as "seed
            // yourself", and the fallback keeps that out of reach.
            $current = new Randomizer(new Xoshiro256StarStar($this->seed ?? 0));
            $this->seededDraws->randomizer = $current;
        }

        return $current;
    }
}
