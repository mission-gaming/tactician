<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\PairKey;
use Override;

/**
 * Measures uneven repeat spacing: how far apart each pair's repeat
 * meetings actually are versus the even spread across the schedule.
 *
 * For each pair meeting k ≥ 2 times, the ideal gap between consecutive
 * meetings is totalRounds / k; the measure is the mean absolute
 * deviation of the actual gaps from that ideal, averaged over all
 * repeat gaps. Single-meeting pairs (and single-leg schedules with
 * them) contribute nothing, so the metric is zero where the concept
 * does not apply. Round-less and non-pairwise events are skipped.
 *
 * @experimental
 */
final readonly class PairingSpacingMetric implements QualityMetric
{
    /**
     * Always 'Pairing Spacing': the key of this metric's measurement in a
     * scorer's report.
     */
    #[Override]
    public function getName(): string
    {
        return 'Pairing Spacing';
    }

    /**
     * The mean, over every gap between two consecutive meetings of a pair,
     * of how far the gap is from that pair's ideal gap, in rounds.
     *
     * The total number of rounds is taken to be the highest round number in
     * the schedule. 0.0 when no pair meets twice.
     */
    #[Override]
    public function measure(Schedule $schedule): float
    {
        /** @var array<string, array<int>> $meetings pair key => rounds met */
        $meetings = [];
        $maxRound = 0;
        foreach ($schedule->getEventsByRound() as $round => $events) {
            $maxRound = max($maxRound, $round);
            foreach ($events as $event) {
                $participants = $event->getParticipants();
                if (count($participants) !== 2) {
                    continue;
                }

                $meetings[PairKey::of($participants[0]->getId(), $participants[1]->getId())][] = $round;
            }
        }

        $totalDeviation = 0.0;
        $gapCount = 0;
        foreach ($meetings as $rounds) {
            if (count($rounds) < 2) {
                continue;
            }

            sort($rounds);
            $idealGap = $maxRound / count($rounds);
            for ($i = 1; $i < count($rounds); ++$i) {
                $totalDeviation += abs(($rounds[$i] - $rounds[$i - 1]) - $idealGap);
                ++$gapCount;
            }
        }

        return $gapCount === 0 ? 0.0 : $totalDeviation / $gapCount;
    }
}
