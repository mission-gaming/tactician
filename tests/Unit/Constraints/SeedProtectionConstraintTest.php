<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('SeedProtectionConstraint', function (): void {
    it('uses configured total rounds metadata for multi-leg protection windows', function (): void {
        $participants = [
            new Participant('seed1', 'Seed 1', 1),
            new Participant('seed2', 'Seed 2', 2),
            new Participant('seed3', 'Seed 3', 3),
            new Participant('seed4', 'Seed 4', 4),
        ];

        $constraint = new SeedProtectionConstraint(2, 0.5);
        $context = new SchedulingContext($participants, [], 1, 2, 2, [
            'total_rounds' => 6,
        ]);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(2)), $context))->toBeFalse();
        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(4)), $context))->toBeTrue();
    });

    it('falls back to total legs and odd-player bye-aware rounds when metadata is absent', function (): void {
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("seed{$i}", "Seed {$i}", $i);
        }

        $constraint = new SeedProtectionConstraint(2, 0.5);
        $context = new SchedulingContext($participants, [], 1, 2, 2);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(5)), $context))->toBeFalse();
        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(6)), $context))->toBeTrue();
    });
});
