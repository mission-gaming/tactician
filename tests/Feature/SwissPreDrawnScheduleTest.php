<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// The pre-drawn Swiss schedule: every round drawn before any result exists.
// It accepts any number of rounds up to the number of participants minus
// one, and a schedule of that many rounds without a repeat always exists.
// The suite holds the scheduler to generating every one of them, and to the
// same schedule for the same participants, options and seed.

/**
 * @return list<Participant>
 */
function preDrawnField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Participant {$i}");
    }

    return $participants;
}

/**
 * What is wrong with a schedule as a pre-drawn Swiss schedule of the given
 * shape; an empty list when nothing is. The check keeps its own books.
 *
 * @return list<string>
 */
function preDrawnProblems(Schedule $schedule, int $size, int $rounds): array
{
    $problems = [];
    $met = [];
    $byRound = [];
    foreach ($schedule->getEvents() as $event) {
        $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        if (count($ids) !== 2 || $ids[0] === $ids[1]) {
            $problems[] = 'an event that is not between two participants';

            continue;
        }

        sort($ids, SORT_STRING);
        $pair = implode(' v ', $ids);
        if (isset($met[$pair])) {
            $problems[] = "{$pair} meet twice";
        }
        $met[$pair] = true;

        foreach ($ids as $id) {
            $byRound[$event->getRound()?->getNumber() ?? 0][] = $id;
        }
    }

    $byes = $schedule->getMetadataValue('byes');
    $byes = is_array($byes) ? $byes : [];

    if (array_keys($byRound) !== range(1, $rounds)) {
        $problems[] = 'rounds ' . implode(',', array_keys($byRound)) . " instead of 1 to {$rounds}";
    }
    foreach ($byRound as $round => $ids) {
        if (count($ids) !== count(array_unique($ids))) {
            $problems[] = "a participant twice in round {$round}";
        }
        if (count($ids) !== $size - $size % 2) {
            $problems[] = count($ids) . " participants paired in round {$round} of a field of {$size}";
        }
        if ($size % 2 === 1 && (!isset($byes[$round]) || in_array($byes[$round], $ids, true))) {
            $problems[] = "round {$round} does not name the one participant it leaves out as its bye";
        }
    }
    // Not checked: that nobody has two byes. The engine gives a second bye
    // to a participant when the round has no pairing otherwise, which a
    // field of odd size reaches at its largest round counts.
    if (count($byes) !== ($size % 2 === 1 ? $rounds : 0)) {
        $problems[] = count($byes) . ' byes';
    }

    return $problems;
}

/**
 * @return list<string>
 */
function preDrawnLines(Schedule $schedule): array
{
    return array_values(array_map(
        fn(Event $event): string => $event->getRound()?->getNumber() . ': ' . implode(' v ', array_map(
            fn(Participant $participant): string => $participant->getId(),
            $event->getParticipants()
        )),
        $schedule->getEvents()
    ));
}

describe('a pre-drawn Swiss schedule', function (): void {
    // Without a source of draws nothing is random, so one schedule per
    // configuration is the whole space: every field size from 2 to 24 at
    // every round count the scheduler accepts.
    it('is generated for every accepted round count when nothing is drawn', function (): void {
        $problems = [];
        $schedules = 0;

        for ($size = 2; $size <= 24; ++$size) {
            for ($rounds = 1; $rounds <= $size - 1; ++$rounds) {
                $schedule = (new SwissScheduler())->schedule(preDrawnField($size), new SwissOptions($rounds));
                foreach (preDrawnProblems($schedule, $size, $rounds) as $problem) {
                    $problems[] = "n={$size}, {$rounds} rounds: {$problem}";
                }
                ++$schedules;
            }
        }

        expect($problems)->toBe([])->and($schedules)->toBe(276);
    });

    // The round counts at which a single draw fails for roughly one seed
    // in ten to one in four (the first draw of 30 of these 200 seeds at 6
    // participants and 4 rounds, of 23 at 8 and 6, of 30 at 10 and 9, of
    // 44 at 12 and 11; none needs more than four draws), and the largest
    // count of a field of odd size: 200 seeds each.
    it('is generated for every seed at the round counts where a single draw can fail', function (int $size, int $rounds): void {
        $problems = [];

        for ($seed = 1; $seed <= 200; ++$seed) {
            $schedule = (new SwissScheduler())->schedule(preDrawnField($size), new SwissOptions($rounds, $seed));
            foreach (preDrawnProblems($schedule, $size, $rounds) as $problem) {
                $problems[] = "seed {$seed}: {$problem}";
            }
        }

        expect($problems)->toBe([]);
    })->with([[6, 4], [6, 5], [8, 6], [8, 7], [10, 9], [12, 11], [9, 8], [13, 12]]);

    it('is generated for a scheduler that holds a randomizer, at the round counts where a single draw can fail', function (): void {
        // One randomizer drawn from across sixty schedules, as an
        // application that reuses a scheduler does
        $scheduler = new SwissScheduler(null, new Randomizer(new Mt19937(7)));

        for ($schedule = 1; $schedule <= 60; ++$schedule) {
            expect(preDrawnProblems($scheduler->schedule(preDrawnField(8), new SwissOptions(6)), 8, 6))->toBe([]);
        }
    });

    // The rotation is what makes generation certain: it is what the
    // scheduler builds when every draw has reached a round with no pairing.
    // No input is known to get that far (a schedule drawn without a
    // randomizer goes through at every size tried, and a seeded one within
    // four draws of the twenty), so the method is called here directly, by
    // reflection, and held to the same rules as a drawn schedule.
    it('can always be built by rotation, with or without a source of draws', function (): void {
        $rotation = new ReflectionMethod(SwissScheduler::class, 'drawByRotation');
        $problems = [];
        $built = 0;

        for ($size = 2; $size <= 24; ++$size) {
            for ($rounds = 1; $rounds <= $size - 1; ++$rounds) {
                foreach ([null, 1, 2] as $seed) {
                    $drawn = $rotation->invoke(
                        new SwissScheduler(),
                        preDrawnField($size),
                        $rounds,
                        $seed === null ? null : new Randomizer(new Mt19937($seed))
                    );
                    assert(is_array($drawn));

                    $schedule = new Schedule($drawn['events'], ['byes' => $drawn['byes']]);
                    foreach (preDrawnProblems($schedule, $size, $rounds) as $problem) {
                        $problems[] = "n={$size}, {$rounds} rounds, seed " . ($seed ?? 'none') . ": {$problem}";
                    }
                    // A rotation gives nobody a second bye
                    if (count($drawn['byes']) !== count(array_unique($drawn['byes']))) {
                        $problems[] = "n={$size}, {$rounds} rounds: a participant with two byes";
                    }
                    ++$built;
                }
            }
        }

        expect($problems)->toBe([])->and($built)->toBe(828);
    });

    it('is the same schedule on every call for the same participants, rounds and seed', function (int $size, int $rounds): void {
        $scheduler = new SwissScheduler();
        $options = new SwissOptions($rounds, 2026);

        $first = preDrawnLines($scheduler->schedule(preDrawnField($size), $options));

        // The same scheduler asked again, a new scheduler, and options
        // read back from plain data
        expect(preDrawnLines($scheduler->schedule(preDrawnField($size), $options)))->toBe($first)
            ->and(preDrawnLines((new SwissScheduler())->schedule(preDrawnField($size), $options)))->toBe($first)
            ->and(preDrawnLines((new SwissScheduler())->schedule(preDrawnField($size), SwissOptions::fromArray($options->toArray()))))->toBe($first)
            // Another seed, another schedule
            ->and(preDrawnLines($scheduler->schedule(preDrawnField($size), new SwissOptions($rounds, 2027))))->not->toBe($first);
    })->with([[8, 3], [8, 7], [9, 5], [16, 8], [12, 11]]);

    it('draws from a seed what a new randomizer on the Xoshiro256StarStar engine draws', function (): void {
        $seeded = (new SwissScheduler())->schedule(preDrawnField(10), new SwissOptions(4, 99));
        $handed = (new SwissScheduler(null, new Randomizer(new Xoshiro256StarStar(99))))
            ->schedule(preDrawnField(10), new SwissOptions(4));

        expect(preDrawnLines($seeded))->toBe(preDrawnLines($handed));
    });

    it('continues the sequence of a randomizer it holds, so two calls give two schedules', function (): void {
        $scheduler = new SwissScheduler(null, new Randomizer(new Mt19937(3)));

        expect(preDrawnLines($scheduler->schedule(preDrawnField(8), new SwissOptions(3))))
            ->not->toBe(preDrawnLines($scheduler->schedule(preDrawnField(8), new SwissOptions(3))));
    });

    it('refuses a seed in the options of a scheduler that holds a randomizer', function (): void {
        $scheduler = new SwissScheduler(null, new Randomizer(new Mt19937(3)));

        try {
            $scheduler->schedule(preDrawnField(8), new SwissOptions(3, 5));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);

            return;
        }

        throw new LogicException('A schedule was drawn from two sources.');
    });

    it('pairs the first round in list order when nothing is drawn', function (): void {
        $schedule = (new SwissScheduler())->schedule(preDrawnField(6), new SwissOptions(1));

        expect(preDrawnLines($schedule))->toBe(['1: p2 v p1', '1: p4 v p3', '1: p6 v p5']);
    });

    it('still reports a constraint that leaves no schedule', function (): void {
        // Nobody may meet anybody: no draw and no rotation can help
        $scheduler = new SwissScheduler(
            ConstraintSet::create()->custom(fn(Event $event): bool => false, 'Nobody meets')->build(),
            new Randomizer(new Mt19937(1))
        );

        expect(fn() => $scheduler->schedule(preDrawnField(6), new SwissOptions(2)))
            ->toThrow(IncompleteScheduleException::class, 'Failed to generate complete Swiss schedule for round 1.');
    });
});

describe('SwissOptions', function (): void {
    it('has no seed unless one is given, and writes the key only then', function (): void {
        expect((new SwissOptions(4))->seed)->toBeNull()
            ->and((new SwissOptions(4))->toArray())->toBe(['rounds' => 4])
            ->and((new SwissOptions(4, 0))->toArray())->toBe(['rounds' => 4, 'seed' => 0])
            ->and(SwissOptions::fromArray(['rounds' => 4, 'seed' => -7])->seed)->toBe(-7)
            ->and(SwissOptions::fromArray(['rounds' => 4, 'seed' => null])->seed)->toBeNull()
            ->and(SwissOptions::fromArray(['rounds' => 4])->seed)->toBeNull();
    });

    it('refuses a seed that is not an integer', function (mixed $seed): void {
        try {
            SwissOptions::fromArray(['rounds' => 4, 'seed' => $seed]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::WrongValueType);

            return;
        }

        throw new LogicException('A seed that is not an integer was accepted.');
    })->with([['7'], [7.0], [true], [[7]]]);
});
