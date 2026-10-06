<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Tests\Support\RoleCounts;

describe('MirroredLegStrategy Integration', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('celtic', 'Celtic'),
            new Participant('athletic', 'Athletic Bilbao'),
            new Participant('livorno', 'AS Livorno'),
            new Participant('redstar', 'Red Star FC'),
        ];
        $this->schedule = (new RoundRobinScheduler())->schedule(
            $this->participants,
            new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
        );
    });

    // Every event of the second leg, not only the first two: the event in
    // the same place of the first leg, three rounds later, the other way
    // round.
    it('plays every event of the first leg again in the second with the roles reversed', function (): void {
        $firstLeg = RoleCounts::leg($this->schedule, 1, 3);
        $secondLeg = RoleCounts::leg($this->schedule, 2, 3);

        $asRows = fn(array $events, int $roundOffset, bool $reversed): array => array_map(
            function (Event $event) use ($roundOffset, $reversed): array {
                [$first, $second] = $event->getParticipants();

                return [
                    ($event->getRound()?->getNumber() ?? 0) + $roundOffset,
                    $reversed ? $second->getId() : $first->getId(),
                    $reversed ? $first->getId() : $second->getId(),
                ];
            },
            $events
        );

        expect($firstLeg)->toHaveCount(6)
            ->and($asRows($secondLeg, 0, false))->toBe($asRows($firstLeg, 3, true));
    });

    // Once titled "demonstrates the Celtic always home issue": the issue is
    // the one this guards against. Mirroring gives every participant, and
    // not only the one in the fixed seat of the circle method, as many
    // first-named as second-named events, and every pairing one of each.
    it('gives every participant as many first-named as second-named events', function (): void {
        $differences = RoleCounts::differences($this->schedule);
        ksort($differences);

        expect($differences)->toBe(['athletic' => 0, 'celtic' => 0, 'livorno' => 0, 'redstar' => 0])
            ->and(RoleCounts::pairingSplits($this->schedule))->toHaveCount(6)
            ->and(array_values(array_unique(RoleCounts::pairingSplits($this->schedule))))->toBe([0]);
    });
});
