<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;

describe('SeedProtectionConstraint', function (): void {
    it('reads the protection window from the plan total rounds for multi-leg stages', function (): void {
        $participants = [
            new Participant('seed1', 'Seed 1', 1),
            new Participant('seed2', 'Seed 2', 2),
            new Participant('seed3', 'Seed 3', 3),
            new Participant('seed4', 'Seed 4', 4),
        ];

        $constraint = new SeedProtectionConstraint(2, 0.5);
        // 4 participants over 2 legs: the plan declares 6 total rounds,
        // so protection covers rounds 1-3.
        $context = roundRobinContext($participants, [], legs: 2);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(2)), $context))->toBeFalse();
        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(4)), $context))->toBeTrue();
    });

    it('uses the plan bye-aware rounds per leg for odd fields', function (): void {
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("seed{$i}", "Seed {$i}", $i);
        }

        $constraint = new SeedProtectionConstraint(2, 0.5);
        // 5 participants (odd) over 2 legs: 5 rounds per leg, 10 total,
        // so protection covers rounds 1-5.
        $context = roundRobinContext($participants, [], legs: 2);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(5)), $context))->toBeFalse();
        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(6)), $context))->toBeTrue();
    });

    it('reads the window from a Swiss plan with configured rounds', function (): void {
        $participants = [
            new Participant('seed1', 'Seed 1', 1),
            new Participant('seed2', 'Seed 2', 2),
            new Participant('seed3', 'Seed 3', 3),
            new Participant('seed4', 'Seed 4', 4),
        ];

        $constraint = new SeedProtectionConstraint(2, 0.5);
        $context = swissContext($participants, [], rounds: 6);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(2)), $context))->toBeFalse();
        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(4)), $context))->toBeTrue();
    });

    it('is satisfied when the plan cannot know its total rounds', function (): void {
        $participants = [
            new Participant('seed1', 'Seed 1', 1),
            new Participant('seed2', 'Seed 2', 2),
        ];

        $constraint = new SeedProtectionConstraint(2, 1.0);
        // A Swiss stage without a configured length has no knowable
        // protection window; the constraint never rejects rather than
        // guessing a round count.
        $context = swissContext($participants, [], rounds: null);

        expect($constraint->isSatisfied(new Event([$participants[0], $participants[1]], new Round(1)), $context))->toBeTrue();
    });
});
