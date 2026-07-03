<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

/**
 * @return array<Participant>
 */
function retryParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}", $i);
    }

    return $participants;
}

// Regression: the greedy generator used to fail whenever the default circle
// ordering happened to pair top seeds inside the protected window, even
// though other orderings satisfy the constraint. Rotated retries find one.
it('satisfies seed protection by retrying alternative participant orderings', function (): void {
    $constraints = ConstraintSet::create()
        ->add(new SeedProtectionConstraint(2, 0.25))
        ->build();

    // 6 teams, 2 legs => 10 rounds; 25% protection covers rounds 1-2.
    $schedule = (new RoundRobinScheduler($constraints))->schedule(retryParticipants(6), 2, 2);

    expect(count($schedule))->toBe(30);

    foreach ($schedule as $event) {
        $seeds = array_map(fn (Participant $p) => $p->getSeed(), $event->getParticipants());
        sort($seeds);
        if ($seeds === [1, 2]) {
            expect($event->getRound()?->getNumber())->toBeGreaterThan(2);
        }
    }
});

it('still rejects configurations no participant ordering can satisfy', function (): void {
    // The circle method keeps one participant in a fixed, always-home seat,
    // so a 3-in-a-row home streak in leg 1 survives every rotation.
    $constraints = ConstraintSet::create()
        ->add(ConsecutiveRoleConstraint::homeAway(2))
        ->build();

    $scheduler = new RoundRobinScheduler($constraints);

    expect(fn () => $scheduler->schedule(retryParticipants(4), 2, 2))
        ->toThrow(IncompleteScheduleException::class);
});

it('produces identical unconstrained schedules with retries available', function (): void {
    // Without constraints the first ordering always completes, so retry
    // support must not change previously generated schedules.
    $schedule = (new RoundRobinScheduler())->schedule(retryParticipants(4));

    $pairings = [];
    foreach ($schedule as $event) {
        $round = $event->getRound()?->getNumber();
        $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
        $pairings[] = $round . ':' . implode('-', $ids);
    }

    expect($pairings)->toBe([
        '1:p1-p4', '1:p2-p3',
        '2:p1-p2', '2:p3-p4',
        '3:p1-p3', '3:p4-p2',
    ]);
});
