<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Best-of-N schedule optimization by seeded sampling.
 *
 * The generators are deterministic given a randomizer, so sampling the
 * space of schedules is just sampling seeds: one master randomizer
 * derives a child seed per sample, a caller-supplied generator turns
 * each child into a candidate schedule, and the best-scoring candidate
 * wins (ties break to the earliest sample). The same master seed always
 * produces the same result.
 *
 * Determinism holds only if the generator threads the supplied child
 * randomizer through every randomness source in its pipeline — the
 * scheduler *and* anything else that randomizes (a ShuffledLegStrategy,
 * for instance). An unseeded source anywhere makes sampling
 * unrepeatable.
 *
 * A sample whose generation throws IncompleteScheduleException — a
 * shuffled ordering no retry can fix, for instance — is skipped and
 * counted; only zero valid candidates is an error, in which case the
 * last generation failure is rethrown with its full diagnostics.
 *
 * Whole-schedule generators only: results-driven engines (Swiss,
 * elimination) pair from results that do not exist yet, so there is
 * nothing to sample up front.
 *
 * @experimental
 */
final readonly class ScheduleOptimizer
{
    /**
     * @param ScheduleScorer $scorer Scores every candidate; the lowest score wins
     * @param Randomizer $randomizer The master randomizer the child seeds are drawn from. Each
     *                               optimize() call draws one value per sample and so moves it
     *                               on: a second call on the same optimizer samples other seeds
     */
    public function __construct(
        private ScheduleScorer $scorer,
        private Randomizer $randomizer
    ) {}

    /**
     * Generate N candidates and keep the best-scoring one.
     *
     * The generator is called once per sample, in order, each time with a
     * new Mt19937 randomizer seeded from the master randomizer. Of several
     * candidates with the lowest score the first sampled is kept. An
     * exception from the generator other than IncompleteScheduleException
     * is not caught and ends the run.
     *
     * @param callable(Randomizer): Schedule $generate Builds one candidate from a seeded randomizer
     * @param int $samples How many candidates to generate, 1 or more
     *
     * @throws InvalidConfigurationException When samples is not positive, or a metric
     *                                       measures a candidate as NAN or INF
     * @throws IncompleteScheduleException When every sample fails generation
     */
    public function optimize(callable $generate, int $samples): OptimizedSchedule
    {
        if ($samples < 1) {
            throw new InvalidConfigurationException(
                'Optimization needs at least one sample',
                ['samples' => $samples],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        $best = null;
        $bestScore = 0.0;
        $generated = 0;
        $failed = 0;
        $lastFailure = null;

        for ($sample = 0; $sample < $samples; ++$sample) {
            $child = new Randomizer(new Mt19937($this->randomizer->getInt(0, 2147483647)));

            try {
                $candidate = $generate($child);
            } catch (IncompleteScheduleException $exception) {
                ++$failed;
                $lastFailure = $exception;
                continue;
            }

            ++$generated;
            $score = $this->scorer->score($candidate);
            // The first candidate is the best so far whatever it scores.
            if ($best === null || $score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            // Every sample failed; the last failure carries the diagnostics.
            assert($lastFailure !== null);
            throw $lastFailure;
        }

        return new OptimizedSchedule(
            $best,
            $bestScore,
            $this->scorer->report($best),
            $generated,
            $failed
        );
    }
}
