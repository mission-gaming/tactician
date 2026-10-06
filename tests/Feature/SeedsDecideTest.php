<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\ScheduleOptimizer;
use MissionGaming\Tactician\Quality\ScheduleScorer;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use Random\Engine\Mt19937;
use Random\Randomizer;

// "The same seed gives the same schedule" is tested beside each generator.
// It is also true of a generator that ignores its randomizer. These tests
// are the other half: for everything that takes a `Random\Randomizer`, twenty
// seeds must give twenty different results, so a randomizer that is accepted
// and never asked fails here.
//
// The pot draw takes an integer seed in its options and has the same pair of
// tests already ("gives a different schedule for every seed" and "gives the
// same schedule for the same participants, options and seed" in
// tests/Unit/Scheduling/PotDrawSchedulerTest.php, and the sweep in
// tests/Feature/PotDrawInvariantsTest.php).
//
// Twenty different results from twenty seeds is not a property of
// randomness in general: two seeds can draw the same thing. The fields here
// are large enough (8! orders of the field, 2^28 role choices) that they do
// not, and the seeds are fixed, so the count is the same on every run.

const SEEDS_DECIDE_SEEDS = 20;

/**
 * @return list<Participant>
 */
function seedsDecideField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Participant {$i}", $i);
    }

    return $participants;
}

/**
 * A schedule as text: every event with its round and both roles, and the
 * byes the metadata records.
 *
 * @throws JsonException When the byes cannot be written as JSON
 */
function seedsDecideSignature(Schedule $schedule): string
{
    $parts = [];
    foreach ($schedule->getEvents() as $event) {
        $parts[] = $event->getRound()?->getNumber() . ':' . implode('v', array_map(
            static fn(Participant $participant): string => $participant->getId(),
            $event->getParticipants()
        ));
    }

    return implode(' ', $parts) . ' byes ' . json_encode($schedule->getMetadataValue('byes'), JSON_THROW_ON_ERROR);
}

/**
 * The first participant must meet the others in list order, one per round:
 * satisfiable, and out of reach of every rotation of the circle method, so
 * only the backtracking search finds a schedule.
 *
 * @param list<Participant> $field
 */
function seedsDecideFixturePlacement(array $field): ConstraintSet
{
    $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $field);

    return ConstraintSet::create()->custom(static function (Event $event) use ($ids): bool {
        $seats = array_map(
            static fn(Participant $participant): int|false => array_search($participant->getId(), $ids, true),
            $event->getParticipants()
        );
        if (!in_array(0, $seats, true)) {
            return true;
        }

        return max($seats) === $event->getRound()?->getNumber();
    }, 'Fixture Placement')->build();
}

describe('a different seed gives a different result', function (): void {
    it('shuffles the field of a round robin', function (int $size): void {
        $field = seedsDecideField($size);
        $unseeded = seedsDecideSignature((new RoundRobinScheduler())->schedule($field));

        $schedules = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $schedules[] = seedsDecideSignature(
                (new RoundRobinScheduler(null, new Randomizer(new Mt19937($seed))))->schedule($field)
            );
        }

        expect(array_unique($schedules))->toHaveCount(SEEDS_DECIDE_SEEDS)
            ->and($schedules)->not->toContain($unseeded);
    })->with([[8], [9]]);

    it('draws the roles of the later legs of a shuffled round robin, and nothing else', function (): void {
        $field = seedsDecideField(8);

        $firstLegs = [];
        $laterLegs = [];
        $structures = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $schedule = (new RoundRobinScheduler())->schedule(
                $field,
                new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937($seed))))
            );

            $firstLegs[] = seedsDecideSignature(new Schedule(RoleCounts::leg($schedule, 1, 7)));
            $laterLegs[] = seedsDecideSignature(new Schedule(RoleCounts::leg($schedule, 2, 7)));
            $structures[] = implode(' ', RoleCounts::structure($schedule));
        }

        // 28 events in the second leg, each drawn one way or the other
        expect(array_unique($laterLegs))->toHaveCount(SEEDS_DECIDE_SEEDS)
            // The first leg is not drawn, and no seed moves a pairing
            ->and(array_unique($firstLegs))->toHaveCount(1)
            ->and(array_unique($structures))->toHaveCount(1);
    });

    it('seeds the field a backtracking search starts from', function (): void {
        $field = seedsDecideField(6);

        $schedules = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $schedules[] = seedsDecideSignature(
                (new RoundRobinScheduler(seedsDecideFixturePlacement($field), new Randomizer(new Mt19937($seed))))
                    ->schedule($field, new RoundRobinOptions(backtracking: true))
            );
        }

        // The constraint fixes who the first participant meets in each
        // round; the seed decides the order the search tries the rest in,
        // and with it the other ten events and every role.
        expect(array_unique($schedules))->toHaveCount(SEEDS_DECIDE_SEEDS);
    });

    it('shuffles the first round of a Swiss stage, where the whole field is level', function (): void {
        $field = seedsDecideField(16);
        $pairs = static function (SwissPairingEngine $engine) use ($field): string {
            $keys = [];
            foreach ($engine->pairNextRound(StageState::start($field))->getEvents() as $event) {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($ids);
                $keys[] = implode('v', $ids);
            }
            sort($keys);

            return implode(' ', $keys);
        };

        $rounds = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $rounds[] = $pairs(new SwissPairingEngine(randomizer: new Randomizer(new Mt19937($seed))));
        }

        // Compared as sets of pairings: a seed that only reordered the
        // events of the round, or only swapped roles, would not count.
        expect(array_unique($rounds))->toHaveCount(SEEDS_DECIDE_SEEDS)
            ->and($rounds)->not->toContain($pairs(new SwissPairingEngine()));
    });

    it('draws a whole Swiss schedule', function (): void {
        $field = seedsDecideField(12);

        $schedules = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $schedules[] = seedsDecideSignature(
                (new SwissScheduler(null, new Randomizer(new Mt19937($seed))))->schedule($field, new SwissOptions(rounds: 4))
            );
        }

        expect(array_unique($schedules))->toHaveCount(SEEDS_DECIDE_SEEDS);
    });

    it('seeds every sample of the optimizer from the master seed', function (): void {
        $field = seedsDecideField(8);
        $samplesOf = static function (int $masterSeed) use ($field): array {
            $samples = [];
            (new ScheduleOptimizer(ScheduleScorer::of(new RoleBalanceMetric()), new Randomizer(new Mt19937($masterSeed))))
                ->optimize(static function (Randomizer $child) use (&$samples, $field): Schedule {
                    $schedule = (new RoundRobinScheduler(null, $child))->schedule($field);
                    $samples[] = seedsDecideSignature($schedule);

                    return $schedule;
                }, 5);

            return $samples;
        };

        $all = [];
        for ($seed = 1; $seed <= SEEDS_DECIDE_SEEDS; ++$seed) {
            $samples = $samplesOf($seed);

            // The five samples of one run are five different schedules: each
            // child randomizer is seeded on its own
            expect(array_unique($samples))->toHaveCount(5);
            $all[] = implode("\n", $samples);
        }

        expect(array_unique($all))->toHaveCount(SEEDS_DECIDE_SEEDS)
            ->and($samplesOf(7))->toBe($samplesOf(7));
    });
});

// What a second call on the same scheduler returns.
//
// A scheduler keeps the `Randomizer` it was given and asks it again on every
// call, so the second call continues the random sequence where the first
// left it: it does not start again from the seed. The same seed gives the
// same schedule for a randomizer that is new for the call; a scheduler that
// is used twice gives the first and then the second schedule of that seed.
// docs/USAGE.md ("Deterministic Randomization") shows one call and says
// "Same seed will always produce the same schedule"; it does not say what a
// second call on the instance gives. These tests pin what the code does.
describe('a scheduler used twice', function (): void {
    it('continues the random sequence on the second call of a seeded round robin', function (): void {
        $field = seedsDecideField(8);
        $twoCalls = static function () use ($field): array {
            $scheduler = new RoundRobinScheduler(null, new Randomizer(new Mt19937(2026)));

            return [
                seedsDecideSignature($scheduler->schedule($field)),
                seedsDecideSignature($scheduler->schedule($field)),
            ];
        };

        [$first, $second] = $twoCalls();

        expect($second)->not->toBe($first)
            // The pair of calls is as repeatable as one call is
            ->and($twoCalls())->toBe([$first, $second])
            // The first call is what a new scheduler with the seed gives
            ->and($first)->toBe(seedsDecideSignature(
                (new RoundRobinScheduler(null, new Randomizer(new Mt19937(2026))))->schedule($field)
            ));
    });

    it('gives the second call what a new scheduler gives a randomizer in the same state', function (): void {
        $field = seedsDecideField(8);

        $reused = new RoundRobinScheduler(null, new Randomizer(new Mt19937(2026)));
        $reused->schedule($field);
        $second = seedsDecideSignature($reused->schedule($field));

        // The randomizer is the only thing a scheduler carries from one call
        // to the next: advance one by a call, hand it to a new scheduler
        $randomizer = new Randomizer(new Mt19937(2026));
        (new RoundRobinScheduler(null, $randomizer))->schedule($field);

        expect(seedsDecideSignature((new RoundRobinScheduler(null, $randomizer))->schedule($field)))->toBe($second);
    });

    it('continues the random sequence on the second call of a seeded Swiss scheduler', function (): void {
        $field = seedsDecideField(12);
        $twoCalls = static function () use ($field): array {
            $scheduler = new SwissScheduler(null, new Randomizer(new Mt19937(2026)));

            return [
                seedsDecideSignature($scheduler->schedule($field, new SwissOptions(rounds: 4))),
                seedsDecideSignature($scheduler->schedule($field, new SwissOptions(rounds: 4))),
            ];
        };

        [$first, $second] = $twoCalls();

        expect($second)->not->toBe($first)
            ->and($twoCalls())->toBe([$first, $second]);
    });

    it('carries nothing from one call to the next without a randomizer', function (): void {
        $odd = seedsDecideField(7);
        $even = seedsDecideField(8);
        $options = new RoundRobinOptions(legs: 2);

        $scheduler = new RoundRobinScheduler();
        $firstOdd = seedsDecideSignature($scheduler->schedule($odd, $options));
        $firstEven = seedsDecideSignature($scheduler->schedule($even, $options));

        // The same schedules again, and the byes of the odd field do not
        // turn up in the even one that follows it
        expect(seedsDecideSignature($scheduler->schedule($odd, $options)))->toBe($firstOdd)
            ->and(seedsDecideSignature($scheduler->schedule($even, $options)))->toBe($firstEven)
            ->and($firstEven)->toBe(seedsDecideSignature((new RoundRobinScheduler())->schedule($even, $options)))
            ->and($firstEven)->toEndWith('byes []')
            ->and($firstOdd)->toContain('byes {"1":"p');
    });

    it('carries nothing from a failed call to the next', function (): void {
        $field = seedsDecideField(6);
        $scheduler = new RoundRobinScheduler(seedsDecideFixturePlacement($field));

        // The rotations cannot satisfy the placement, and the search can
        $failure = null;
        try {
            $scheduler->schedule($field);
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }
        expect($failure)->not->toBeNull();

        $afterFailure = seedsDecideSignature($scheduler->schedule($field, new RoundRobinOptions(backtracking: true)));

        expect($afterFailure)->toBe(seedsDecideSignature(
            (new RoundRobinScheduler(seedsDecideFixturePlacement($field)))->schedule($field, new RoundRobinOptions(backtracking: true))
        ));
    });
});
