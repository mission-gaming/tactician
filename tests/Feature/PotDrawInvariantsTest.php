<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;
use MissionGaming\Tactician\Tests\Support\PotDrawAudit;

// The rules of a pot draw, checked for every configuration the scheduler
// supports with up to 60 entrants, over several seeds each, by counting in
// the generated events (Tests\Support\PotDrawAudit). Nothing here asks the
// library whether its own output is right.
//
// The rules: every entrant is in every round once; every entrant has pots ×
// opponents per pot events; every entrant has exactly the configured number
// of opponents from every pot, its own included; no two entrants meet
// twice; the two role counts of an entrant differ by at most one (and are
// equal when its number of events is even); with an even number of
// opponents per pot an entrant is in each role half the time against every
// pot; the plan's counts are the generated counts; the same seed gives the
// same schedule and different seeds give different ones.

/**
 * The largest field the sweep covers.
 */
const POT_DRAW_SWEEP_MAX_ENTRANTS = 60;

/**
 * Seeds drawn for each configuration of the sweep. Every configuration gets
 * seeds of its own, so the sweep as a whole uses several thousand.
 */
const POT_DRAW_SWEEP_SEEDS = 3;

/**
 * Seeds drawn for each of the three worked cases.
 */
const POT_DRAW_WORKED_CASE_SEEDS = 200;

/**
 * Entrants "1".."n" in seeding order.
 *
 * @return list<Participant>
 */
function potDrawSweepField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant((string) $i, "Entrant {$i}");
    }

    return $field;
}

/**
 * What the format's rules say about a configuration, worked out from the
 * numbers alone: 'supported', 'not-yet-supported', or the reason it cannot
 * exist. The rules are those the issue states, in the order the library
 * documents for its refusals.
 */
function potDrawExpectedClass(int $count, int $pots, int $opponentsPerPot): string
{
    if ($count % 2 === 1) {
        return InvalidConfigurationReason::OddParticipantCount->value;
    }
    if ($count % $pots !== 0) {
        return InvalidConfigurationReason::UnequalPots->value;
    }

    $potSize = intdiv($count, $pots);
    if ($opponentsPerPot > $potSize - 1) {
        return InvalidConfigurationReason::TooManyOpponentsPerPot->value;
    }
    if ($potSize % 2 === 1 && $opponentsPerPot % 2 === 1) {
        return InvalidConfigurationReason::OddPotWithOddOpponents->value;
    }

    // Direct construction only: any even pot size, and an odd pot size
    // with two opponents per pot
    return $potSize % 2 === 0 || $opponentsPerPot === 2 ? 'supported' : 'not-yet-supported';
}

/**
 * Every configuration of the sweep with the given class.
 *
 * @return list<array{int, int, int}> [entrants, pots, opponents per pot]
 */
function potDrawConfigurations(string $class): array
{
    $configurations = [];
    for ($count = 2; $count <= POT_DRAW_SWEEP_MAX_ENTRANTS; ++$count) {
        for ($pots = 1; $pots <= $count; ++$pots) {
            for ($opponentsPerPot = 1; $opponentsPerPot <= $count; ++$opponentsPerPot) {
                if (potDrawExpectedClass($count, $pots, $opponentsPerPot) === $class) {
                    $configurations[] = [$count, $pots, $opponentsPerPot];
                }
            }
        }
    }

    return $configurations;
}

/**
 * The events as one string, in schedule order.
 */
function potDrawFingerprint(Schedule $schedule): string
{
    $parts = [];
    foreach ($schedule->getEvents() as $event) {
        $pair = $event->getParticipants();
        $parts[] = $event->getRound()?->getNumber() . ':' . $pair[0]->getId() . '-' . $pair[1]->getId();
    }

    return implode(' ', $parts);
}

describe('Pot draw invariants', function (): void {
    // The sweep is not empty and covers both families of construction
    it('sweeps every supported configuration up to 60 entrants', function (): void {
        $supported = potDrawConfigurations('supported');

        $oddPots = array_filter($supported, fn(array $configuration): bool => intdiv($configuration[0], $configuration[1]) % 2 === 1);
        $largest = 0;
        foreach ($supported as [$count]) {
            $largest = max($largest, $count);
        }

        expect($supported)->toHaveCount(1449)
            ->and($oddPots)->toHaveCount(36)
            ->and($largest)->toBe(60)
            ->and($supported)->toContain([6, 3, 1])
            ->and($supported)->toContain([20, 5, 1])
            ->and($supported)->toContain([36, 4, 2])
            ->and($supported)->toContain([60, 1, 59])
            ->and($supported)->toContain([60, 30, 1])
            ->and($supported)->toContain([60, 4, 2]);
    });

    it('holds every rule for every supported configuration up to 60 entrants over several seeds', function (): void {
        $failures = [];
        $draws = 0;
        $seeds = [];

        foreach (potDrawConfigurations('supported') as $index => [$count, $pots, $opponentsPerPot]) {
            $field = potDrawSweepField($count);
            $scheduler = new PotDrawScheduler();
            $name = "{$count} entrants, {$pots} pots, {$opponentsPerPot} per pot";

            $fingerprints = [];

            for ($draw = 0; $draw < POT_DRAW_SWEEP_SEEDS; ++$draw) {
                $seed = $index * POT_DRAW_SWEEP_SEEDS + $draw;
                $seeds[$seed] = true;
                $options = new PotDrawOptions($pots, $opponentsPerPot, $seed);

                $schedule = $scheduler->schedule($field, $options);
                ++$draws;
                $audit = PotDrawAudit::of($schedule, $field, $pots);

                foreach ($audit->violations($field, $pots, $opponentsPerPot) as $violation) {
                    $failures[] = "{$name}, seed {$seed}: {$violation}";
                }

                // The plan's counts, stated before generation, are the counts generated
                $plan = $scheduler->getPlan($field, $options);
                if ($plan->getTotalRounds() !== count($audit->eventsByRound)
                    || $plan->getExpectedEventCount() !== $audit->events
                    || $plan->getEventsPerRound() * count($audit->eventsByRound) !== $audit->events
                ) {
                    $failures[] = "{$name}, seed {$seed}: the plan's counts are not the generated counts";
                }

                $fingerprint = potDrawFingerprint($schedule);
                $fingerprints[$fingerprint] = true;

                // The same call on the same instance, after the draws of
                // other seeds, gives the same schedule
                if ($draw === POT_DRAW_SWEEP_SEEDS - 1) {
                    $firstOptions = new PotDrawOptions($pots, $opponentsPerPot, $index * POT_DRAW_SWEEP_SEEDS);
                    if (potDrawFingerprint($scheduler->schedule($field, $firstOptions)) !== array_key_first($fingerprints)) {
                        $failures[] = "{$name}: a second call with the first seed drew another schedule";
                    }
                }
            }

            // Different seeds draw different schedules. Below 12 entrants a
            // configuration may allow so few schedules that two seeds draw
            // the same one
            if ($count >= 12 && count($fingerprints) !== POT_DRAW_SWEEP_SEEDS) {
                $failures[] = "{$name}: " . count($fingerprints) . ' different schedules from ' . POT_DRAW_SWEEP_SEEDS . ' seeds';
            }
        }

        expect(array_slice($failures, 0, 20))->toBe([])
            ->and($draws)->toBe(4347)
            ->and($seeds)->toHaveCount(4347);
    });

    it('holds every rule for the worked cases over many seeds', function (int $count, int $pots, int $opponentsPerPot): void {
        $field = potDrawSweepField($count);
        $scheduler = new PotDrawScheduler();

        $failures = [];
        $fingerprints = [];
        for ($seed = 1000; $seed < 1000 + POT_DRAW_WORKED_CASE_SEEDS; ++$seed) {
            $schedule = $scheduler->schedule($field, new PotDrawOptions($pots, $opponentsPerPot, $seed));
            $fingerprints[potDrawFingerprint($schedule)] = true;

            foreach (PotDrawAudit::of($schedule, $field, $pots)->violations($field, $pots, $opponentsPerPot) as $violation) {
                $failures[] = "seed {$seed}: {$violation}";
            }
        }

        expect(array_slice($failures, 0, 20))->toBe([])
            // 6 entrants in 3 pots of 2 allow only a few dozen schedules
            ->and(count($fingerprints))->toBeGreaterThanOrEqual($count === 6 ? 20 : POT_DRAW_WORKED_CASE_SEEDS);
    })->with([
        '6 entrants in 3 pots of 2, one opponent per pot' => [6, 3, 1],
        '20 entrants in 5 pots of 4, one opponent per pot' => [20, 5, 1],
        '36 entrants in 4 pots of 9, two opponents per pot' => [36, 4, 2],
    ]);

    // Every combination of numbers up to 60 entrants is either drawn or
    // refused with the reason its numbers call for, and getPlan() - which
    // draws nothing - refuses it the same way
    it('refuses every other configuration up to 60 entrants with the reason its numbers call for', function (): void {
        $scheduler = new PotDrawScheduler();
        $wrong = [];
        $classes = [];

        for ($count = 2; $count <= POT_DRAW_SWEEP_MAX_ENTRANTS; ++$count) {
            $field = potDrawSweepField($count);
            for ($pots = 1; $pots <= $count; ++$pots) {
                // One past the pot size is enough to reach every refusal
                $most = intdiv($count, $pots) + 1;
                for ($opponentsPerPot = 1; $opponentsPerPot <= $most; ++$opponentsPerPot) {
                    $expected = potDrawExpectedClass($count, $pots, $opponentsPerPot);
                    $expected = $expected === 'not-yet-supported' ? InvalidConfigurationReason::ConfigurationNotYetSupported->value : $expected;
                    $classes[$expected] = ($classes[$expected] ?? 0) + 1;
                    $options = new PotDrawOptions($pots, $opponentsPerPot, 1);

                    $calls = ['getPlan' => fn() => $scheduler->getPlan($field, $options)];
                    // Drawing every supported configuration is the sweep above
                    if ($expected !== 'supported') {
                        $calls['schedule'] = fn() => $scheduler->schedule($field, $options);
                    }

                    $actual = [];
                    foreach ($calls as $method => $call) {
                        try {
                            $call();
                            $actual[$method] = 'supported';
                        } catch (InvalidConfigurationException $exception) {
                            $actual[$method] = $exception->getReason()?->value;
                        }
                    }

                    if (array_unique(array_values($actual)) !== [$expected]) {
                        $wrong[] = "{$count} entrants, {$pots} pots, {$opponentsPerPot} per pot: expected {$expected}, got " . json_encode($actual);
                    }
                }
            }
        }

        ksort($classes);

        expect(array_slice($wrong, 0, 20))->toBe([])
            ->and(array_keys($classes))->toBe([
                'configuration_not_yet_supported',
                'odd_participant_count',
                'odd_pot_with_odd_opponents',
                'supported',
                'too_many_opponents_per_pot',
                'unequal_pots',
            ])
            ->and($classes['supported'])->toBe(1449)
            ->and($classes['configuration_not_yet_supported'])->toBe(123);
    });
});
