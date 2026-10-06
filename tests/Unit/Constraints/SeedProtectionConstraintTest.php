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

    // The name is a lookup key: the violation collector and the diagnostics
    // group by it. The period is a fraction of the rounds and the name states
    // it as a percentage, by the rule on SeedProtectionConstraint::getName().
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
        'a whole percentage that is not a whole float when multiplied by 100' => [0.29, '29'],
        'a non-whole percentage' => [0.125, '12.5'],
        'less than one percent' => [0.0015, '0.15'],
        'far less than one percent' => [0.0000001, '0.00001'],
        'a third' => [1 / 3, '33.33333333333333'],
        // The float nearest to 2/3 is below it: 0.6666666666666666 is the
        // shortest decimal that reads back as that float.
        'two thirds' => [2 / 3, '66.66666666666666'],
        'a sum that is not three tenths' => [0.1 + 0.2, '30.000000000000004'],
        'the float below one' => [0.9999999999999999, '99.99999999999999'],
    ]);

    it('states the number of protected seeds in the name', function (): void {
        expect((new SeedProtectionConstraint(4, 0.25))->getName())->toBe('Seed Protection (top 4, 25% period)');
    });

    it('gives the same name whatever the precision settings', function (float $period, string $name): void {
        $precision = (string) ini_get('precision');
        $serializePrecision = (string) ini_get('serialize_precision');
        $names = [];

        try {
            foreach (['17', '3', '-1'] as $setting) {
                ini_set('precision', $setting);
                ini_set('serialize_precision', $setting);
                $names[$setting] = (new SeedProtectionConstraint(2, $period))->getName();
            }
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }

        expect($names)->toBe(['17' => $name, '3' => $name, '-1' => $name]);
    })->with([
        'a fifth' => [0.2, 'Seed Protection (top 2, 20% period)'],
        'a non-whole percentage' => [0.125, 'Seed Protection (top 2, 12.5% period)'],
        'a third' => [1 / 3, 'Seed Protection (top 2, 33.33333333333333% period)'],
    ]);

    it('writes the decimal point as a dot under a locale that writes a comma', function (): void {
        $previous = setlocale(LC_NUMERIC, '0');
        if ($previous === false) {
            $this->markTestSkipped('The current locale cannot be read, so it could not be restored.');
        } elseif (setlocale(LC_NUMERIC, ['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR']) === false) {
            // Harness limitation: the locales installed differ by machine.
            $this->markTestSkipped('No locale with a decimal comma is installed.');
        } else {
            try {
                $decimalPoint = localeconv()['decimal_point'];
                $name = (new SeedProtectionConstraint(2, 0.125))->getName();
            } finally {
                setlocale(LC_NUMERIC, $previous);
            }

            // The first expectation proves the locale was in force.
            expect($decimalPoint)->toBe(',')
                ->and($name)->toBe('Seed Protection (top 2, 12.5% period)');
        }
    });

    it('gives two different periods two different names', function (): void {
        // Each of these is a different float, and the constraint can treat
        // them differently: over 3 rounds a third protects round 1, and
        // 0.3333 protects none.
        $periods = [1 / 3, 0.3333, 0.33333, 0.3333333333333333, 0.33333333333333337, 0.3, 0.1 + 0.2, 0.30000000000000004];
        $distinctPeriods = [];
        $names = [];
        foreach ($periods as $period) {
            $distinctPeriods[bin2hex(pack('E', $period))] = true;
            $names[(new SeedProtectionConstraint(2, $period))->getName()] = true;
        }

        // Three of the literals above are spellings of a float already in the list.
        expect($distinctPeriods)->toHaveCount(6)
            ->and($names)->toHaveCount(6);
    });

    it('states a percentage that is exactly one hundred times the period', function (): void {
        // A fixed seed: the same periods on every run.
        $randomizer = new Random\Randomizer(new Random\Engine\Mt19937(82));

        for ($i = 0; $i < 2000; ++$i) {
            $period = $randomizer->nextFloat();
            if ($i % 2 === 1) {
                $period = round($period, $randomizer->getInt(0, 6));
            }

            $name = (new SeedProtectionConstraint(1, $period))->getName();

            expect(preg_match('/^Seed Protection \(top 1, (\d+)(?:\.(\d*[1-9]))?% period\)$/', $name, $matches))->toBe(1, $name);

            // Move the decimal point two places back on the digits, where it
            // is exact, and read the result as a float.
            $integerDigits = str_pad($matches[1] ?? '', 3, '0', STR_PAD_LEFT);
            $fraction = substr($integerDigits, 0, -2) . '.' . substr($integerDigits, -2) . ($matches[2] ?? '');

            expect((float) $fraction)->toBe($period, $name);
        }
    });
});
