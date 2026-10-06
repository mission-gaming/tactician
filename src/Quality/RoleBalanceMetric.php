<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use Override;

/**
 * Measures role imbalance: how unevenly each participant's appearances
 * split between the first role (home, white, server...) and the second.
 *
 * The measure is the mean over appearing participants of
 * |first-role count − second-role count|. Non-pairwise events carry no
 * role reading and are skipped.
 *
 * @experimental
 */
final readonly class RoleBalanceMetric implements QualityMetric
{
    /**
     * Always 'Role Balance': the key of this metric's measurement in a
     * scorer's report.
     */
    #[Override]
    public function getName(): string
    {
        return 'Role Balance';
    }

    /**
     * The mean, over every participant in an event of two, of the absolute
     * difference between its first-named and second-named appearances, in
     * events. Events without a round are counted too. 0.0 for a schedule
     * with no event of two.
     *
     * This iterates the schedule itself, which moves its iteration
     * position: do not call it from inside a `foreach` over the same
     * Schedule object.
     */
    #[Override]
    public function measure(Schedule $schedule): float
    {
        /** @var array<string, array{0: int, 1: int}> $counts [first, second] per participant */
        $counts = [];
        foreach ($schedule as $event) {
            $participants = $event->getParticipants();
            if (count($participants) !== 2) {
                continue;
            }

            $counts[$participants[0]->getId()][0] = ($counts[$participants[0]->getId()][0] ?? 0) + 1;
            $counts[$participants[1]->getId()][1] = ($counts[$participants[1]->getId()][1] ?? 0) + 1;
        }

        if ($counts === []) {
            return 0.0;
        }

        $totalImbalance = 0;
        foreach ($counts as $split) {
            $totalImbalance += abs(($split[0] ?? 0) - ($split[1] ?? 0));
        }

        return $totalImbalance / count($counts);
    }
}
