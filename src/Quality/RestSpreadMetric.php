<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use Override;

/**
 * Measures irregular appearance rhythm: how unevenly the gaps between
 * each participant's consecutive playing rounds vary.
 *
 * For each participant the gaps between consecutive rounds played are
 * collected; the measure is the mean over participants of the population
 * variance of those gaps. A participant playing every round — or resting
 * on a perfectly regular cycle — contributes zero. Round-less events
 * have no position in the rhythm and are skipped.
 */
final readonly class RestSpreadMetric implements QualityMetric
{
    #[Override]
    public function getName(): string
    {
        return 'Rest Spread';
    }

    #[Override]
    public function measure(Schedule $schedule): float
    {
        /** @var array<string, array<int, true>> $rounds participant => set of rounds played */
        $rounds = [];
        foreach ($schedule->getEventsByRound() as $round => $events) {
            foreach ($events as $event) {
                foreach ($event->getParticipants() as $participant) {
                    $rounds[$participant->getId()][$round] = true;
                }
            }
        }

        if ($rounds === []) {
            return 0.0;
        }

        $totalVariance = 0.0;
        foreach ($rounds as $played) {
            $playedRounds = array_keys($played);
            sort($playedRounds);

            $gaps = [];
            for ($i = 1; $i < count($playedRounds); ++$i) {
                $gaps[] = $playedRounds[$i] - $playedRounds[$i - 1];
            }

            if (count($gaps) < 2) {
                continue;
            }

            $mean = array_sum($gaps) / count($gaps);
            $variance = 0.0;
            foreach ($gaps as $gap) {
                $variance += ($gap - $mean) ** 2;
            }
            $totalVariance += $variance / count($gaps);
        }

        return $totalVariance / count($rounds);
    }
}
