<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Tests\Support\CiEnvironment;
use PHPUnit\Framework\Assert;

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

    // The name is a lookup key: the violation collector and the diagnostics
    // group by it. The period is a fraction of the rounds and the name states
    // it as a percentage rounded to at most two decimal places, by the rule
    // on SeedProtectionConstraint::getName().
    it('names the protection period as a percentage', function (float $period, string $percentage): void {
        expect((new SeedProtectionConstraint(2, $period))->getName())
            ->toBe("Seed Protection (top 2, {$percentage}% period)");
    })->with([
        'a fifth' => [0.2, '20'],
        'a half' => [0.5, '50'],
        'three tenths' => [0.3, '30'],
        'the whole stage' => [1.0, '100'],
        'no rounds' => [0.0, '0'],
        'negative zero' => [-0.0, '0'],
        'one percent' => [0.01, '1'],
        'seven percent' => [0.07, '7'],
        // 0.29 * 100 and 0.285 * 100 are not 29 and 28.5 as floats.
        'a whole percentage that is not a whole float when multiplied by 100' => [0.29, '29'],
        'one decimal place that is not exact when multiplied by 100' => [0.285, '28.5'],
        'one decimal place' => [0.125, '12.5'],
        'half a percent' => [0.005, '0.5'],
        'two decimal places' => [0.0015, '0.15'],
        'two decimal places ending in a zero digit before the last' => [0.1005, '10.05'],
        'the smallest percentage that can be named' => [0.0001, '0.01'],
        'a third, rounded down' => [1 / 3, '33.33'],
        'two thirds, rounded up' => [2 / 3, '66.67'],
        'a sum that is not three tenths as a float' => [0.1 + 0.2, '30'],
        'a third decimal place below the half' => [0.12344, '12.34'],
        'a third decimal place above the half' => [0.12346, '12.35'],
        // A half goes up. The floats nearest to 0.33335 and 0.01005 are
        // below those numbers, so rounding the float's exact value would give
        // 33.33 and 1; the rule rounds the number as written.
        'a half, where the float is below it' => [0.33335, '33.34'],
        'another half, where the float is below it' => [0.01005, '1.01'],
        'a half, where the float is above it' => [0.12345, '12.35'],
        // 0.00015 * 10000 is 1.4999999999999998 as a float, so rounding the
        // product would give 0.01.
        'a half that the float product falls short of' => [0.00015, '0.02'],
        'another half that the float product falls short of' => [0.00145, '0.15'],
        // The float just below 0.12345. It takes 17 significant digits to
        // tell it from 0.12345; with fewer it would round up.
        'the float below a half' => [0.12344999999999999, '12.34'],
        'a half that rounds up to the smallest percentage' => [0.00005, '0.01'],
        'just below the half that rounds up to the smallest percentage' => [0.0000499999, '0'],
        'a half that rounds up to the whole stage' => [0.99995, '100'],
        'the float below the half that rounds up to the whole stage' => [0.9999499999999999, '99.99'],
        'just below the whole stage' => [0.999999, '100'],
        'the float below one' => [0.9999999999999999, '100'],
        'a carry into the units' => [0.09996, '10'],
        'a period too small to show' => [0.0000001, '0'],
        'the smallest positive float' => [4.9E-324, '0'],
        'the smallest normal float' => [PHP_FLOAT_MIN, '0'],
        // 2 ** -24 is one of the floats whose shortest decimal form has a
        // last digit that is not the rounding of its exact value.
        'a small power of two' => [2 ** -24, '0'],
    ]);

    it('states the number of protected seeds in the name', function (): void {
        expect((new SeedProtectionConstraint(4, 0.25))->getName())->toBe('Seed Protection (top 4, 25% period)');
    });

    // The constructor's range check lets NAN through. A patch does not change
    // that, and the name of such a constraint stays what it was.
    it('keeps the name of a period that is not a number', function (): void {
        expect((new SeedProtectionConstraint(2, NAN))->getName())->toBe('Seed Protection (top 2, NAN% period)');
    });

    it('gives the same name whatever the precision settings', function (float $period, string $name): void {
        $precision = (string) ini_get('precision');
        $serializePrecision = (string) ini_get('serialize_precision');
        $names = [];

        try {
            foreach (['17', '3', '0', '-1'] as $setting) {
                ini_set('precision', $setting);
                ini_set('serialize_precision', $setting);
                $names[$setting] = (new SeedProtectionConstraint(2, $period))->getName();
            }
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }

        expect($names)->toBe(['17' => $name, '3' => $name, '0' => $name, '-1' => $name]);
    })->with([
        'a fifth' => [0.2, 'Seed Protection (top 2, 20% period)'],
        'one decimal place' => [0.125, 'Seed Protection (top 2, 12.5% period)'],
        'a third' => [1 / 3, 'Seed Protection (top 2, 33.33% period)'],
        'a half that rounds up' => [0.33335, 'Seed Protection (top 2, 33.34% period)'],
        'a period too small to show' => [0.0000001, 'Seed Protection (top 2, 0% period)'],
    ]);

    it('writes the decimal point as a dot under a locale that writes a comma', function (): void {
        $previous = setlocale(LC_NUMERIC, '0');
        if ($previous === false) {
            $this->markTestSkipped('The current locale cannot be read, so it could not be restored.');
        } elseif (setlocale(LC_NUMERIC, ['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR']) === false) {
            // Harness limitation: the locales installed differ by machine.
            // The CI workflow generates de_DE.UTF-8, so there the test must
            // run; a skip would let the check pass without it.
            if (CiEnvironment::isCi(getenv('CI'))) {
                Assert::fail('No locale with a decimal comma is installed. On CI this check must run; a skip would hide it.');
            }

            $this->markTestSkipped('No locale with a decimal comma is installed.');
        } else {
            try {
                $decimalPoint = localeconv()['decimal_point'];
                // What a locale-aware conversion gives here, to prove the
                // locale changes how a number is written.
                $localeAware = sprintf('%.1f', 12.5);
                $names = [
                    (new SeedProtectionConstraint(2, 0.125))->getName(),
                    (new SeedProtectionConstraint(2, 1 / 3))->getName(),
                    (new SeedProtectionConstraint(2, 0.0015))->getName(),
                ];
            } finally {
                setlocale(LC_NUMERIC, $previous);
            }

            // The first two expectations prove the locale was in force.
            expect($decimalPoint)->toBe(',')
                ->and($localeAware)->toBe('12,5')
                ->and($names)->toBe([
                    'Seed Protection (top 2, 12.5% period)',
                    'Seed Protection (top 2, 33.33% period)',
                    'Seed Protection (top 2, 0.15% period)',
                ]);
        }
    });

    // The accepted cost of a short name: it does not identify the period
    // exactly. The constraint can treat two periods that share a name
    // differently (over 3 rounds a third protects round 1, and 0.3333
    // protects none).
    it('gives periods that round to the same percentage the same name', function (array $periods, string $percentage): void {
        $names = [];
        foreach ($periods as $period) {
            $names[] = (new SeedProtectionConstraint(2, $period))->getName();
        }

        expect(array_unique($names))->toBe(["Seed Protection (top 2, {$percentage}% period)"]);
    })->with([
        'around a third' => [[1 / 3, 0.3333, 0.33333, 0.33334, 0.33325], '33.33'],
        'around three tenths' => [[0.3, 0.1 + 0.2, 0.30004, 0.29995], '30'],
        'the top of the range' => [[1.0, 0.99995, 0.999999], '100'],
        'the bottom of the range' => [[0.0, 0.00004, 4.9E-324], '0'],
    ]);

    it('gives periods a ten-thousandth apart different names', function (): void {
        $names = [];
        for ($tenThousandths = 0; $tenThousandths <= 10000; ++$tenThousandths) {
            $names[(new SeedProtectionConstraint(2, $tenThousandths / 10000))->getName()] = true;
        }

        expect($names)->toHaveCount(10001);
    });

    it('states a percentage of at most two decimal places within half a hundredth of the period', function (): void {
        // A fixed seed: the same periods on every run.
        $randomizer = new Random\Randomizer(new Random\Engine\Mt19937(82));
        $periods = [];

        for ($i = 0; $i < 2000; ++$i) {
            $period = $randomizer->nextFloat();
            if ($i % 2 === 1) {
                // Short decimals, among them halves at the third decimal
                // place of the percentage
                $period = round($period, $randomizer->getInt(0, 6));
            }
            $periods[] = $period;
        }

        sort($periods);
        $previous = 0.0;

        foreach ($periods as $period) {
            $name = (new SeedProtectionConstraint(1, $period))->getName();

            // No leading zero, no trailing zero, no trailing decimal point
            expect(preg_match('/^Seed Protection \(top 1, ((?:0|[1-9]\d{0,2})(?:\.\d?[1-9])?)% period\)$/', $name, $matches))->toBe(1, $name);

            $percentage = (float) ($matches[1] ?? '');

            // Half a hundredth is the most that rounding to two decimal
            // places moves a number. The margin of 1e-9 is for the float
            // arithmetic of this comparison, not for the name.
            expect(abs($percentage - $period * 100))->toBeLessThanOrEqual(0.005 + 1e-9, $name);

            // Rounding keeps the order: a longer period never has a smaller
            // percentage.
            expect($percentage)->toBeGreaterThanOrEqual($previous, $name);
            $previous = $percentage;
        }
    });
});
