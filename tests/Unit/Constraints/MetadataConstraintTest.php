<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

function metadataEvent(Participant ...$participants): Event
{
    return new Event($participants);
}

describe('MetadataConstraint', function (): void {
    beforeEach(function (): void {
        $this->north1 = new Participant('n1', 'North One', null, ['region' => 'north', 'tier' => 1]);
        $this->north2 = new Participant('n2', 'North Two', null, ['region' => 'north', 'tier' => 2]);
        $this->south1 = new Participant('s1', 'South One', null, ['region' => 'south', 'tier' => 3]);
        $this->untagged = new Participant('u1', 'Untagged');
        $this->untagged2 = new Participant('u2', 'Untagged Two');
        $this->context = new SchedulingContext([$this->north1, $this->north2, $this->south1, $this->untagged, $this->untagged2]);
    });

    it('rejects a non-callable validator', function (): void {
        expect(fn () => new MetadataConstraint('region', 'not-callable'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('requires matching values with requireSameValue', function (): void {
        $constraint = MetadataConstraint::requireSameValue('region');

        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->north2), $this->context))->toBeTrue();
        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->south1), $this->context))->toBeFalse();
        expect($constraint->getName())->toBe('Same region');
    });

    it('treats missing metadata as a wildcard in requireSameValue', function (): void {
        $constraint = MetadataConstraint::requireSameValue('region');

        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->untagged), $this->context))->toBeTrue();
    });

    it('requires distinct values with requireDifferentValues', function (): void {
        $constraint = MetadataConstraint::requireDifferentValues('region');

        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->south1), $this->context))->toBeTrue();
        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->north2), $this->context))->toBeFalse();
    });

    it('caps distinct values with maxUniqueValues', function (): void {
        $constraint = MetadataConstraint::maxUniqueValues('region', 1);

        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->north2), $this->context))->toBeTrue();
        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->south1), $this->context))->toBeFalse();
    });

    it('requires numerically adjacent values with requireAdjacentValues', function (): void {
        $constraint = MetadataConstraint::requireAdjacentValues('tier');

        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->north2), $this->context))->toBeTrue(); // tiers 1, 2
        expect($constraint->isSatisfied(metadataEvent($this->north1, $this->south1), $this->context))->toBeFalse(); // tiers 1, 3
        // Non-numeric values are ignored entirely
        expect($constraint->isSatisfied(metadataEvent($this->untagged, $this->untagged2), $this->context))->toBeTrue();
    });

    it('passes values, participants, event, and context to a custom validator', function (): void {
        $captured = [];
        $constraint = new MetadataConstraint(
            'region',
            function (array $values, array $participants, Event $event, SchedulingContext $context) use (&$captured): bool {
                $captured = [$values, count($participants), $event, $context];

                return true;
            },
            'Capture'
        );

        $event = metadataEvent($this->north1, $this->south1);
        expect($constraint->isSatisfied($event, $this->context))->toBeTrue();
        expect($captured[0])->toBe(['north', 'south']);
        expect($captured[1])->toBe(2);
        expect($captured[2])->toBe($event);
        expect($captured[3])->toBe($this->context);
        expect($constraint->getName())->toBe('Capture');
    });
});
