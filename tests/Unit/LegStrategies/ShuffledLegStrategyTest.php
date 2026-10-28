<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Ask the strategy for the given number of second-leg events of one pairing
 * and say, for each, whether it reversed the order it was given.
 *
 * @return list<bool>
 */
function shuffledLegReversals(ShuffledLegStrategy $strategy, int $draws): array
{
    $pair = [new Participant('a', 'Amy'), new Participant('b', 'Bob')];
    $context = roundRobinContext($pair, [], legs: 2);

    $reversals = [];
    for ($round = 2; $round < 2 + $draws; ++$round) {
        $event = $strategy->generateEventForLeg($pair, 2, $round, $context);
        $reversals[] = $event?->getParticipants() === [$pair[1], $pair[0]];
    }

    return $reversals;
}

/**
 * What the strategy is documented to do with a source: reverse a pairing
 * when the next integer from 0 to 1 is 1.
 *
 * @return list<bool>
 */
function reversalsDrawnFrom(Randomizer $source, int $draws): array
{
    $reversals = [];
    for ($draw = 0; $draw < $draws; ++$draw) {
        $reversals[] = $source->getInt(0, 1) === 1;
    }

    return $reversals;
}

/**
 * @param Closure(): mixed $build
 */
function shuffledLegRefusal(Closure $build): ?InvalidConfigurationException
{
    try {
        $build();
    } catch (InvalidConfigurationException $refusal) {
        return $refusal;
    }

    return null;
}

describe('ShuffledLegStrategy', function (): void {
    // The defect of issue #52: a strategy given nothing drew from an
    // unseeded randomizer, and no seed anywhere could repeat its schedule.
    it('is refused without a source of its draws', function (): void {
        $refusal = shuffledLegRefusal(fn(): ShuffledLegStrategy => new ShuffledLegStrategy());

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal?->getMessage())->toContain('needs a seed or a randomizer');
    });

    it('is refused with two sources, which could not both decide a draw', function (): void {
        $refusal = shuffledLegRefusal(
            fn(): ShuffledLegStrategy => new ShuffledLegStrategy(new Randomizer(new Mt19937(1)), 1)
        );

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal?->getMessage())->toContain('not both');
    });

    it('says which seed it was built from, and none when it holds a randomizer', function (): void {
        expect(new ShuffledLegStrategy(seed: 2026)->seed)->toBe(2026)
            ->and(new ShuffledLegStrategy(seed: 0)->seed)->toBe(0)
            ->and(new ShuffledLegStrategy(new Randomizer(new Mt19937(2026)))->seed)->toBeNull();
    });

    it('draws from its seed what a Xoshiro256StarStar randomizer of that seed gives', function (int $seed): void {
        expect(shuffledLegReversals(new ShuffledLegStrategy(seed: $seed), 64))
            ->toBe(reversalsDrawnFrom(new Randomizer(new Xoshiro256StarStar($seed)), 64));
    })->with([[0], [1], [2026], [-7], [PHP_INT_MAX]]);

    it('draws different roles from different seeds', function (): void {
        $drawn = array_map(
            fn(int $seed): array => shuffledLegReversals(new ShuffledLegStrategy(seed: $seed), 64),
            range(1, 8)
        );

        expect(array_unique($drawn, SORT_REGULAR))->toHaveCount(8);
    });

    // planLegs() opens a schedule() call, so a strategy built from a seed
    // gives every call the draws it gave the first
    it('starts its draws again from the seed when a plan is made', function (): void {
        $strategy = new ShuffledLegStrategy(seed: 2026);
        $first = shuffledLegReversals($strategy, 32);

        // Without a new plan the sequence goes on
        expect(shuffledLegReversals($strategy, 32))->not->toBe($first);

        $strategy->planLegs([], 2, ConstraintSet::create()->build());

        expect(shuffledLegReversals($strategy, 32))->toBe($first);
    });

    // A clone that shared the state of the original would start the
    // original's draws again whenever a plan was made on the clone
    it('gives a clone draws of its own, which start at the seed', function (): void {
        $whole = shuffledLegReversals(new ShuffledLegStrategy(seed: 2026), 64);

        $original = new ShuffledLegStrategy(seed: 2026);
        $firstHalf = shuffledLegReversals($original, 32);

        $clone = clone $original;
        $clone->planLegs([], 2, ConstraintSet::create()->build());
        $fromTheClone = shuffledLegReversals($clone, 64);

        expect($clone->seed)->toBe(2026)
            ->and($fromTheClone)->toBe($whole)
            ->and([...$firstHalf, ...shuffledLegReversals($original, 32)])->toBe($whole);

        // And the other way: a plan on the original leaves the clone where it is
        $clone = clone $original;
        $firstHalfOfTheClone = shuffledLegReversals($clone, 32);
        $original->planLegs([], 2, ConstraintSet::create()->build());

        expect([...$firstHalfOfTheClone, ...shuffledLegReversals($clone, 32)])->toBe($whole);
    });

    it('draws nothing for an event of the first leg', function (): void {
        $pair = [new Participant('a', 'Amy'), new Participant('b', 'Bob')];
        $context = roundRobinContext($pair, [], legs: 2);
        $strategy = new ShuffledLegStrategy(seed: 2026);

        for ($call = 0; $call < 10; ++$call) {
            expect($strategy->generateEventForLeg($pair, 1, 1, $context)?->getParticipants())->toBe($pair);
        }

        expect(shuffledLegReversals($strategy, 32))
            ->toBe(reversalsDrawnFrom(new Randomizer(new Xoshiro256StarStar(2026)), 32));
    });

    // A randomizer the caller passed in is the caller's: the strategy draws
    // from it as it always did, and making a plan does not rewind it
    it('draws from the randomizer it holds, one integer for each event of a later leg', function (): void {
        $strategy = new ShuffledLegStrategy(new Randomizer(new Mt19937(42)));
        $reference = new Randomizer(new Mt19937(42));

        expect(shuffledLegReversals($strategy, 32))->toBe(reversalsDrawnFrom($reference, 32));

        $strategy->planLegs([], 2, ConstraintSet::create()->build());

        expect(shuffledLegReversals($strategy, 32))->toBe(reversalsDrawnFrom($reference, 32));
    });
});
