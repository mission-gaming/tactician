<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackObjective;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\SessionGrid;

describe('RepackOptions', function (): void {
    it('defaults to consolidation-dominant weights and a bounded budget', function (): void {
        $options = new RepackOptions();

        expect($options->consolidationWeight)->toBe(3);
        expect($options->earlyFillWeight)->toBe(1);
        expect($options->stepBudget)->toBe(200_000);
        expect($options->throwOnViolations)->toBeFalse();
    });

    it('round-trips through plain configuration data', function (): void {
        $options = RepackOptions::fromArray([
            'consolidation_weight' => 5,
            'early_fill_weight' => 2,
            'step_budget' => 1_000,
            'throw_on_violations' => true,
        ]);

        expect(RepackOptions::fromArray($options->toArray())->toArray())->toBe($options->toArray());
    });

    it('rejects negative weights', function (): void {
        new RepackOptions(consolidationWeight: -1);
    })->throws(InvalidConfigurationException::class);

    it('rejects a non-positive step budget', function (): void {
        new RepackOptions(stepBudget: 0);
    })->throws(InvalidConfigurationException::class);

    it('rejects malformed configuration values', function (): void {
        RepackOptions::fromArray(['step_budget' => 'plenty']);
    })->throws(InvalidConfigurationException::class);
});

describe('RepackOptions objectives', function (): void {
    it('names no objective by default', function (): void {
        expect(new RepackOptions()->objectives)->toBe([])
            ->and(RepackOptions::fromArray([])->objectives)->toBe([])
            ->and(RepackOptions::fromArray(['objectives' => null])->objectives)->toBe([]);
    });

    it('keeps the objectives in the order given', function (): void {
        $objectives = [RepackObjective::FewestIdleSessions, RepackObjective::LeastChurn];

        expect(new RepackOptions(objectives: $objectives)->objectives)->toBe($objectives);
    });

    it('reads them from plain data by their identifiers, and writes them back', function (): void {
        $options = RepackOptions::fromArray(['objectives' => ['least_churn', 'fewest_lone_event_sessions', 'fewest_idle_sessions']]);

        expect($options->objectives)->toBe([
            RepackObjective::LeastChurn,
            RepackObjective::FewestLoneEventSessions,
            RepackObjective::FewestIdleSessions,
        ])
            ->and($options->toArray())->toBe([
                'format_version' => 1,
                'consolidation_weight' => 3,
                'early_fill_weight' => 1,
                'step_budget' => 200_000,
                'throw_on_violations' => false,
                'objectives' => ['least_churn', 'fewest_lone_event_sessions', 'fewest_idle_sessions'],
            ])
            ->and(RepackOptions::fromArray($options->toArray()))->toEqual($options);
    });

    it('writes options that name no objective as they were written before objectives existed', function (): void {
        expect(new RepackOptions()->toArray())->toBe([
            'format_version' => 1,
            'consolidation_weight' => 3,
            'early_fill_weight' => 1,
            'step_budget' => 200_000,
            'throw_on_violations' => false,
        ]);
    });

    it('has a stable identifier for every objective', function (): void {
        expect(array_map(static fn(RepackObjective $objective): string => $objective->value, RepackObjective::cases()))
            ->toBe(['least_churn', 'fewest_lone_event_sessions', 'fewest_idle_sessions']);
    });

    it('refuses objectives that are not a list of distinct cases', function (array $objectives, InvalidConfigurationReason $reason): void {
        try {
            // Through reflection, because the declared type of the argument
            // is the very thing these values break
            new ReflectionClass(RepackOptions::class)->newInstance(3, 1, 200_000, false, $objectives);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception->getReason();
        }

        expect($thrown)->toBe($reason);
    })->with([
        'an identifier where a case belongs' => [['least_churn'], InvalidConfigurationReason::WrongValueType],
        'a map' => [['first' => RepackObjective::LeastChurn], InvalidConfigurationReason::WrongValueType],
        'a case twice' => [[RepackObjective::LeastChurn, RepackObjective::FewestIdleSessions, RepackObjective::LeastChurn], InvalidConfigurationReason::DuplicateName],
    ]);

    it('refuses plain data whose objectives are not a list of known identifiers', function (mixed $objectives, InvalidConfigurationReason $reason): void {
        try {
            RepackOptions::fromArray(['objectives' => $objectives]);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception->getReason();
        }

        expect($thrown)->toBe($reason);
    })->with([
        'one identifier, not a list' => ['least_churn', InvalidConfigurationReason::WrongValueType],
        'a map' => [['first' => 'least_churn'], InvalidConfigurationReason::WrongValueType],
        'an entry that is no string' => [[1], InvalidConfigurationReason::WrongValueType],
        'an identifier it does not know' => [['least_travel'], InvalidConfigurationReason::UnknownIdentifier],
        'an identifier twice' => [['least_churn', 'least_churn'], InvalidConfigurationReason::DuplicateName],
    ]);

    it('names the identifiers it knows when it is given one it does not', function (): void {
        try {
            RepackOptions::fromArray(['objectives' => ['least_travel']]);
            $context = null;
        } catch (InvalidConfigurationException $exception) {
            $context = $exception->getContext();
        }

        expect($context)->toBe([
            'objective' => 'least_travel',
            'known' => ['least_churn', 'fewest_lone_event_sessions', 'fewest_idle_sessions'],
        ]);
    });
});

describe('The current position of a movable event', function (): void {
    it('is absent unless it is given', function (): void {
        $event = new MovableEvent('m1', new Participant('a', 'A'), new Participant('b', 'B'));

        expect($event->hasCurrentPosition())->toBeFalse()
            ->and($event->getCurrentSession())->toBeNull()
            ->and($event->getCurrentSlot())->toBeNull();
    });

    it('is a session and a slot, as a pinned event has them', function (): void {
        $event = new MovableEvent('m1', new Participant('a', 'A'), new Participant('b', 'B'), 4, 0);

        expect($event->hasCurrentPosition())->toBeTrue()
            ->and($event->getCurrentSession())->toBe(4)
            ->and($event->getCurrentSlot())->toBe(0);
    });

    it('is given whole or not at all, and never negative', function (?int $session, ?int $slot, InvalidConfigurationReason $reason): void {
        try {
            new MovableEvent('m1', new Participant('a', 'A'), new Participant('b', 'B'), $session, $slot);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception->getReason();
        }

        expect($thrown)->toBe($reason);
    })->with([
        'a session without a slot' => [2, null, InvalidConfigurationReason::IncompatibleOptions],
        'a slot without a session' => [null, 1, InvalidConfigurationReason::IncompatibleOptions],
        'a negative session' => [-1, 0, InvalidConfigurationReason::PositionOutOfRange],
        'a negative slot' => [0, -1, InvalidConfigurationReason::PositionOutOfRange],
    ]);

    it('need not be a position of the grid, nor free: a request takes it as a fact', function (): void {
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');
        $request = new RepackRequest(
            [
                new MovableEvent('off the grid', $a, $b, 7, 3),
                new MovableEvent('under a pin', $a, $c, 0, 0),
                new MovableEvent('with another', $b, $c, 0, 0),
            ],
            [new PinnedEvent('x1', $a, $b, 0, 0)],
            SessionGrid::shapeOnly(2, 1)
        );

        expect($request->getMovableEvents()[0]->getCurrentSession())->toBe(7)
            ->and(count($request->getMovableEvents()))->toBe(3);
    });
});
