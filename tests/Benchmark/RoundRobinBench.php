<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Benchmark;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use RuntimeException;

/**
 * Round robin generation: the plain case, the case that needs retries, and
 * the case that fails.
 *
 * Run with `composer bench`. Each subject checks what it generated, so a
 * benchmark that stopped doing its work fails and is not reported as fast.
 *
 * The classes are plain PHP: nothing here names the tool that runs them
 * (tools/phpbench/), so the gate analyses them without it installed. What
 * the tool needs to know is in phpbench.json beside them: every public
 * method named bench* is a subject, timed for one call at a time, with the
 * constructor run first and outside the timing. A subject too quick to time
 * as one call repeats its work itself.
 */
final readonly class RoundRobinBench
{
    /** @var list<Participant> */
    private array $field24;

    /** @var list<Participant> */
    private array $field32;

    public function __construct()
    {
        $this->field24 = self::field(24);
        $this->field32 = self::field(32);
    }

    /**
     * 32 participants, one leg, no constraints: 496 events. Twenty times
     * over, because one takes under a millisecond.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchUnconstrained32(): void
    {
        for ($repeat = 0; $repeat < 20; ++$repeat) {
            $schedule = (new RoundRobinScheduler())->schedule($this->field32);

            self::expect(count($schedule) === 496, 'an unconstrained schedule of 496 events');
        }
    }

    /**
     * 32 participants, two legs, the top two seeds kept apart for the
     * first quarter: the input order fails, the third rotation succeeds.
     * Five times over.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchRetriesUnderSeedProtection32(): void
    {
        for ($repeat = 0; $repeat < 5; ++$repeat) {
            $schedule = (new RoundRobinScheduler(new ConstraintSet([new SeedProtectionConstraint(2, 0.25)])))
                ->schedule($this->field32, new RoundRobinOptions(legs: 2));

            self::expect(count($schedule) === 992, 'a seed-protected schedule of 992 events');
        }
    }

    /**
     * 24 participants, two legs, no pairing allowed twice: unsatisfiable by
     * design. Every rotation fails, and the last one is analysed.
     *
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchUnsatisfiable24TwoLegs(): void
    {
        $failed = false;
        try {
            (new RoundRobinScheduler(new ConstraintSet([new NoRepeatPairings(acrossLegs: true)])))
                ->schedule($this->field24, new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failed = $exception->getAnalysis() !== null;
        }

        self::expect($failed, 'an incomplete schedule with its failure analysis');
    }

    /**
     * @return list<Participant>
     */
    private static function field(int $size): array
    {
        $field = [];
        for ($i = 1; $i <= $size; ++$i) {
            $field[] = new Participant(sprintf('p%02d', $i), "Participant {$i}", $i);
        }

        return $field;
    }

    /**
     * @throws RuntimeException When the condition does not hold
     */
    private static function expect(bool $condition, string $what): void
    {
        if (!$condition) {
            throw new RuntimeException("The benchmark did not produce {$what}.");
        }
    }
}
