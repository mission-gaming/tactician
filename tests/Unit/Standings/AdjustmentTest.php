<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;

// The two plain-data values of this feature: an adjustment (data the
// application records) and the awarded result policy (configuration of a
// standings calculator).

describe('Adjustment', function (): void {
    it('holds the participant, the signed amount, and an optional label and reason', function (): void {
        $adjustment = new Adjustment('p1', -3.0, 'ruling', 'Fielded an unregistered player');

        expect($adjustment->getParticipantId())->toBe('p1')
            ->and($adjustment->getAmount())->toBe(-3.0)
            ->and($adjustment->getLabel())->toBe('ruling')
            ->and($adjustment->getReason())->toBe('Fielded an unregistered player')
            ->and((new Adjustment('p1', 1.5))->getLabel())->toBeNull()
            ->and((new Adjustment('p1', 1.5))->getReason())->toBeNull();
    });

    it('accepts an amount of zero', function (): void {
        expect((new Adjustment('p1', 0.0))->getAmount())->toBe(0.0);
    });

    it('refuses an empty participant ID', function (): void {
        new Adjustment('', 1.0);
    })->throws(InvalidInputException::class, 'An adjustment needs the ID of the participant it is for');

    it('refuses an amount that is not a finite number', function (float $amount): void {
        new Adjustment('p1', $amount);
    })->with([
        'NAN' => [NAN],
        'INF' => [INF],
        '-INF' => [-INF],
    ])->throws(InvalidInputException::class, 'must be a finite number');

    it('round-trips through its array form, with all four keys always written', function (): void {
        $adjustment = new Adjustment('p1', -3.0, 'ruling', 'Fielded an unregistered player');

        expect($adjustment->toArray())->toBe([
            'participant' => 'p1',
            'amount' => -3.0,
            'label' => 'ruling',
            'reason' => 'Fielded an unregistered player',
        ])
            ->and(Adjustment::fromArray($adjustment->toArray()))->toEqual($adjustment)
            ->and((new Adjustment('p2', 0.5))->toArray())->toBe([
                'participant' => 'p2',
                'amount' => 0.5,
                'label' => null,
                'reason' => null,
            ]);
    });

    it('round-trips through JSON, where a whole amount comes back as an integer', function (): void {
        $adjustment = new Adjustment('p1', -3.0, 'ruling');

        $decoded = json_decode(json_encode($adjustment->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        expect(Adjustment::fromArray($decoded))->toEqual($adjustment);
    });

    it('reads an integer amount as that amount, and leaves the label and the reason optional', function (): void {
        expect(Adjustment::fromArray(['participant' => '7', 'amount' => 2]))->toEqual(new Adjustment('7', 2.0));
    });

    it('refuses data it cannot read', function (array $data, string $message): void {
        expect(fn() => Adjustment::fromArray($data))->toThrow(InvalidInputException::class, $message);
    })->with([
        'no participant' => [['amount' => 1], 'Adjustment data requires a participant ID'],
        'a participant that is not a string' => [['participant' => 7, 'amount' => 1], 'Adjustment data requires a participant ID'],
        'an empty participant' => [['participant' => '', 'amount' => 1], 'needs the ID of the participant'],
        'no amount' => [['participant' => 'p1'], 'requires an amount that is an int or a float'],
        'a numeric string as the amount' => [['participant' => 'p1', 'amount' => '3'], 'requires an amount that is an int or a float'],
        'a label that is not a string' => [['participant' => 'p1', 'amount' => 1, 'label' => 3], 'must each be a string or null'],
        'a misspelt key' => [['participant' => 'p1', 'amont' => 1], "Unknown key in adjustment data: 'amont'"],
        'the points of one scale as a key' => [['participant' => 'p1', 'amount' => 1, 'points' => 1], "Unknown key in adjustment data: 'points'"],
    ]);
});

describe('AwardedResultPolicy', function (): void {
    it('counts an awarded result everywhere by default', function (): void {
        $policy = new AwardedResultPolicy();

        expect($policy->countScores)->toBeTrue()
            ->and($policy->countBetweenTiedParticipants)->toBeTrue();
    });

    it('is constructible from plain data, each key optional', function (array $config, bool $scores, bool $betweenTied): void {
        $policy = AwardedResultPolicy::fromArray($config);

        expect([$policy->countScores, $policy->countBetweenTiedParticipants])->toBe([$scores, $betweenTied]);
    })->with([
        'nothing' => [[], true, true],
        'no scores' => [['count_scores' => false], false, true],
        'not between the tied participants' => [['count_between_tied_participants' => false], true, false],
        'both off' => [['count_scores' => false, 'count_between_tied_participants' => false], false, false],
        'a null value is the key left out' => [['count_scores' => null], true, true],
    ]);

    it('round-trips through its array form', function (bool $scores, bool $betweenTied): void {
        $policy = new AwardedResultPolicy($scores, $betweenTied);

        expect($policy->toArray())->toBe([
            'count_scores' => $scores,
            'count_between_tied_participants' => $betweenTied,
        ])->and(AwardedResultPolicy::fromArray($policy->toArray()))->toEqual($policy);
    })->with([[true, true], [true, false], [false, true], [false, false]]);

    it('refuses a key it does not have, with the reason and both lists', function (): void {
        try {
            AwardedResultPolicy::fromArray(['count_score' => false]);
            $this->fail('The misspelt key was read.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UnknownOptionKey)
                ->and($exception->getContext()['unknown'] ?? null)->toBe(['count_score'])
                ->and($exception->getContext()['known'] ?? null)->toBe(['count_scores', 'count_between_tied_participants']);
        }
    });

    it('refuses a value that is not a boolean', function (array $config): void {
        try {
            AwardedResultPolicy::fromArray($config);
            $this->fail('The value was read.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::WrongValueType);
        }
    })->with([
        'an integer' => [['count_scores' => 0]],
        'a string' => [['count_between_tied_participants' => 'no']],
    ]);
});
