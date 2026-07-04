<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
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
 */
final readonly class PairingSpacingMetric implements QualityMetric
{
    #[Override]
    public function getName(): string
    {
        return 'Pairing Spacing';
    }

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

                $ids = [$participants[0]->getId(), $participants[1]->getId()];
                sort($ids);
                $meetings[implode('|', $ids)][] = $round;
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
