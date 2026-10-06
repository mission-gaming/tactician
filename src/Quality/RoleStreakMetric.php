<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use Override;

/**
 * Measures broken role alternation: how long each participant's longest
 * run of consecutive same-role appearances is, beyond the ideal of
 * strict alternation.
 *
 * Appearances are ordered by round; the measure is the mean over
 * appearing participants of (longest streak − 1), so perfect alternation
 * scores zero. Round-less and non-pairwise events carry no ordered role
 * reading and are skipped.
 *
 * @experimental
 */
final readonly class RoleStreakMetric implements QualityMetric
{
    /**
     * Always 'Role Streaks': the key of this metric's measurement in a
     * scorer's report.
     */
    #[Override]
    public function getName(): string
    {
        return 'Role Streaks';
    }

    /**
     * The mean, over every participant in an event of two that has a round,
     * of its longest run of consecutive appearances in one role, minus one.
     *
     * A participant has one role per round: of two of its events in the
     * same round, the later in schedule order is the one read. 0.0 for a
     * schedule with no such event.
     */
    #[Override]
    public function measure(Schedule $schedule): float
    {
        /** @var array<string, array<int, int>> $roles participant => round => role index */
        $roles = [];
        foreach ($schedule->getEventsByRound() as $round => $events) {
            foreach ($events as $event) {
                $participants = $event->getParticipants();
                if (count($participants) !== 2) {
                    continue;
                }

                $roles[$participants[0]->getId()][$round] = 0;
                $roles[$participants[1]->getId()][$round] = 1;
            }
        }

        if ($roles === []) {
            return 0.0;
        }

        $totalExcess = 0;
        foreach ($roles as $byRound) {
            ksort($byRound);
            $sequence = array_values($byRound);

            $longest = 1;
            $current = 1;
            for ($i = 1; $i < count($sequence); ++$i) {
                $current = $sequence[$i] === $sequence[$i - 1] ? $current + 1 : 1;
                $longest = max($longest, $current);
            }

            $totalExcess += $longest - 1;
        }

        return $totalExcess / count($roles);
    }
}
