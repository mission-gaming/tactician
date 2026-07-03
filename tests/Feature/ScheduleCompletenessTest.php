<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @return array<Participant>
 */
function completenessParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}");
    }

    return $participants;
}

/**
 * Assert every pairing appears exactly $legs times, each participant plays at
 * most once per round, and all rounds fall within the expected range.
 */
function assertCompleteRoundRobin(Schedule $schedule, int $participantCount, int $legs): void
{
    $expectedEvents = intdiv($participantCount * ($participantCount - 1), 2) * $legs;
    expect(count($schedule))->toBe($expectedEvents);

    $roundsPerLeg = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
    $totalRounds = $roundsPerLeg * $legs;

    $pairingCounts = [];
    $participantsByRound = [];
    foreach ($schedule as $event) {
        $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);
        $key = implode('|', $ids);
        $pairingCounts[$key] = ($pairingCounts[$key] ?? 0) + 1;

        $round = $event->getRound()?->getNumber();
        expect($round)->not->toBeNull();
        expect($round)->toBeGreaterThanOrEqual(1);
        expect($round)->toBeLessThanOrEqual($totalRounds);

        foreach ($ids as $id) {
            expect($participantsByRound[$round][$id] ?? false)
                ->toBeFalse("Participant {$id} plays more than once in round {$round}");
            $participantsByRound[$round][$id] = true;
        }
    }

    $expectedPairings = intdiv($participantCount * ($participantCount - 1), 2);
    expect(count($pairingCounts))->toBe($expectedPairings);
    foreach ($pairingCounts as $key => $count) {
        expect($count)->toBe($legs, "Pairing {$key} appears {$count} time(s), expected {$legs}");
    }
}

// Regression: odd participant counts historically dropped the bye round in
// legs after the first, producing incomplete multi-leg schedules.
it('generates complete schedules for every participant count, leg count, and strategy', function (
    int $participantCount,
    int $legs,
    string $strategyName
): void {
    $strategy = match ($strategyName) {
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        default => throw new UnexpectedValueException($strategyName),
    };

    $schedule = (new RoundRobinScheduler())->schedule(
        completenessParticipants($participantCount),
        new RoundRobinOptions(legs: $legs, strategy: $strategy)
    );

    assertCompleteRoundRobin($schedule, $participantCount, $legs);
})
    ->with([[3], [4], [5], [6], [7]])
    ->with([[1], [2], [3]])
    ->with([['mirrored'], ['repeated']]);

// Regression: shuffling with a randomizer historically corrupted the bye
// sentinel for odd participant counts, producing duplicate pairings.
it('generates complete randomized schedules for odd and even participant counts', function (
    int $participantCount,
    int $legs,
    int $seed
): void {
    $scheduler = new RoundRobinScheduler(null, new Randomizer(new Mt19937($seed)));
    $schedule = $scheduler->schedule(completenessParticipants($participantCount), new RoundRobinOptions(legs: $legs));

    assertCompleteRoundRobin($schedule, $participantCount, $legs);
})
    ->with([[5], [6]])
    ->with([[1], [2]])
    ->with([[42], [1337]]);

it('generates complete schedules with the shuffled leg strategy', function (): void {
    $strategy = new ShuffledLegStrategy(new Randomizer(new Mt19937(42)));
    $schedule = (new RoundRobinScheduler())->schedule(completenessParticipants(5), new RoundRobinOptions(legs: 2, strategy: $strategy));

    assertCompleteRoundRobin($schedule, 5, 2);
});
