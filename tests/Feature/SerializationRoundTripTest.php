<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use MissionGaming\Tactician\Tests\Support\PlainDataForms;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// Properties of every serializable type, over generated instances and not
// over one example each:
//
// - an instance comes back equal from toArray() and fromArray(), and writes
//   the same array again;
// - the same holds when the array has been through JSON in between, which
//   is how an application stores it;
// - the array says which version of its form it is, once, as its first key.
//
// The instances come from Tests\Support\PlainDataForms, drawn from a seeded
// randomizer, so a failure repeats. The forms that refer to participants by
// ID are generated over every set of awkward IDs the suite has: an ID is
// any non-empty string, and PHP turns some of them into integer array keys.

const ROUND_TRIP_INSTANCES = 40;

/**
 * What an instance writes: every serializable type has toArray().
 *
 * @return array<array-key, mixed>
 *
 * @throws LogicException When the object has no toArray()
 */
function plainDataOf(object $instance): array
{
    if (!method_exists($instance, 'toArray')) {
        throw new LogicException($instance::class . ' has no toArray().');
    }

    $data = $instance->toArray();
    if (!is_array($data)) {
        throw new LogicException($instance::class . '::toArray() did not return an array.');
    }

    return $data;
}

/**
 * The array as it comes back from a JSON column.
 *
 * @param array<array-key, mixed> $data
 *
 * @return array<array-key, mixed>
 *
 * @throws JsonException When a value has no JSON form
 */
function throughJson(array $data): array
{
    $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/**
 * How many times the version key occurs anywhere in the array.
 *
 * @param array<array-key, mixed> $data
 */
function versionKeys(array $data): int
{
    $count = 0;
    array_walk_recursive($data, function (mixed $value, int|string $key) use (&$count): void {
        if ($key === 'format_version') {
            ++$count;
        }
    });

    return $count;
}

/**
 * The fields the generated instances are drawn over: each set of awkward
 * IDs as six participants.
 *
 * @return array<string, array{list<Participant>}>
 */
function roundTripFields(): array
{
    return array_map(
        static fn(array $ids): array => [AwkwardIds::participants($ids)],
        AwkwardIds::sets()
    );
}

describe('an instance of a serializable type', function (): void {
    it('comes back equal from its array and writes the same array again', function (string $name, array $field): void {
        $form = PlainDataForms::all()[$name];
        if ($form['generate'] === null) {
            // A part with no class of its own: its container is another entry
            expect(['blackout window', 'tiebreaker with options'])->toContain($name);

            return;
        }

        $registry = PlainDataForms::registryOf($field);
        $random = new Randomizer(new Xoshiro256StarStar(crc32($name)));

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $instance = $form['generate']($random, $field);
            $written = plainDataOf($instance);

            $read = $form['read']($written, $registry);
            expect(plainDataOf($read))->toBe($written, "{$name}, instance {$i}");
            if ($form['kind'] === PlainDataForms::DATA) {
                expect($read)->toEqual($instance, "{$name}, instance {$i}");
            }

            $stored = $form['read'](throughJson($written), $registry);
            expect(plainDataOf($stored))->toBe($written, "{$name}, instance {$i}, through JSON")
                ->and(throughJson(plainDataOf($stored)))->toBe(throughJson($written));
        }
    })->with(fn(): array => array_keys(PlainDataForms::all()))->with(fn(): array => roundTripFields());

    it('comes back equal from its own JSON', function (array $field): void {
        $forms = PlainDataForms::all();
        $random = new Randomizer(new Xoshiro256StarStar(2026));

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $schedule = ($forms['schedule']['generate'] ?? null)?->__invoke($random, $field);
            $state = ($forms['stage state']['generate'] ?? null)?->__invoke($random, $field);
            $scheduled = ($forms['scheduled schedule']['generate'] ?? null)?->__invoke($random, $field);
            assert($schedule instanceof Schedule && $state instanceof StageState && $scheduled instanceof ScheduledSchedule);

            expect(Schedule::fromJson($schedule->toJson()))->toEqual($schedule)
                ->and(Schedule::fromJson($schedule->toJson())->toJson())->toBe($schedule->toJson())
                ->and(json_encode($schedule))->toBe($schedule->toJson())
                ->and(StageState::fromJson($state->toJson()))->toEqual($state)
                ->and(StageState::fromJson($state->toJson())->toJson())->toBe($state->toJson())
                ->and(ScheduledSchedule::fromJson($scheduled->toJson()))->toEqual($scheduled)
                ->and(ScheduledSchedule::fromJson($scheduled->toJson())->toJson())->toBe($scheduled->toJson())
                ->and(json_encode($scheduled))->toBe($scheduled->toJson());
        }
    })->with(fn(): array => roundTripFields());
});

// The array of a configuration form is compared above as text. A timeline
// and a grid are read for their instants, so those are compared too: what
// is read back gives every slot the time the written one gives it, to the
// microsecond, for a start with a fraction of a second as for one without.
describe('a timeline or a grid read back from its array', function (): void {
    it('has every slot time of the timeline that was written', function (): void {
        $form = PlainDataForms::all()['timeline definition'];
        $random = new Randomizer(new Xoshiro256StarStar(2026));
        $fractions = 0;

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $timeline = ($form['generate'] ?? null)?->__invoke($random, []);
            assert($timeline instanceof TimelineDefinition);
            $read = TimelineDefinition::fromArray(throughJson($timeline->toArray()));
            $fractions += $timeline->getStart()->format('u') === '000000' ? 0 : 1;

            for ($round = 1; $round <= 4; ++$round) {
                for ($slot = 0; $slot < $timeline->getSlotsPerRound(); ++$slot) {
                    expect($read->getSlotTime($round, $slot)->format('Y-m-d\TH:i:s.uP'))
                        ->toBe($timeline->getSlotTime($round, $slot)->format('Y-m-d\TH:i:s.uP'), "instance {$i}, round {$round}, slot {$slot}");
                }
            }
        }

        // Both kinds of start were generated
        expect($fractions)->toBeGreaterThan(0)->toBeLessThan(ROUND_TRIP_INSTANCES);
    });

    it('has every slot time of the grid that was written', function (): void {
        $form = PlainDataForms::all()['session grid with instants'];
        $random = new Randomizer(new Xoshiro256StarStar(2026));
        $fractions = 0;

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $grid = ($form['generate'] ?? null)?->__invoke($random, []);
            assert($grid instanceof SessionGrid);
            $read = SessionGrid::fromArray(throughJson($grid->toArray()));
            $fractions += $grid->getSessionStart(0)->format('u') === '000000' ? 0 : 1;

            expect($read->hasInstants())->toBeTrue()
                ->and($read->getSessionCount())->toBe($grid->getSessionCount());
            for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
                expect($read->getSlotCount($session))->toBe($grid->getSlotCount($session));
                for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
                    $time = $grid->getSlotTime($session, $slot);

                    expect($read->getSlotTime($session, $slot)->format('Y-m-d\TH:i:s.uP'))
                        ->toBe($time->format('Y-m-d\TH:i:s.uP'), "instance {$i}, session {$session}, slot {$slot}")
                        ->and($read->positionOf($time))->toBe(['session' => $session, 'slot' => $slot]);
                }
            }
        }

        expect($fractions)->toBeGreaterThan(0)->toBeLessThan(ROUND_TRIP_INSTANCES);
    });
});

describe('the format version of an array that is written', function (): void {
    it('is the first key, is 1, and is written once however much the array holds', function (string $name, array $field): void {
        $generate = PlainDataForms::all()[$name]['generate'] ?? PlainDataForms::writtenOnly()[$name]['generate'];
        $random = new Randomizer(new Xoshiro256StarStar(crc32($name)));

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $written = plainDataOf($generate($random, $field));

            expect(array_key_first($written))->toBe('format_version', "{$name}, instance {$i}")
                ->and($written['format_version'])->toBe(1)
                ->and(versionKeys($written))->toBe(1, "{$name}, instance {$i}");
        }
    })->with(fn(): array => [
        ...array_keys(array_filter(PlainDataForms::all(), static fn(array $form): bool => $form['generate'] !== null)),
        ...array_keys(PlainDataForms::writtenOnly()),
    ])->with(fn(): array => ['ordinary ids' => [AwkwardIds::participants(AwkwardIds::sets()['ordinary ids'])]]);

    // Data stored by 0.2.x has no version key anywhere
    it('may be missing from an array that is read: the value is the same', function (string $name, array $field): void {
        $form = PlainDataForms::all()[$name];
        if ($form['generate'] === null) {
            expect(['blackout window', 'tiebreaker with options'])->toContain($name);

            return;
        }

        $registry = PlainDataForms::registryOf($field);
        $random = new Randomizer(new Xoshiro256StarStar(crc32($name)));

        for ($i = 0; $i < ROUND_TRIP_INSTANCES; ++$i) {
            $written = plainDataOf($form['generate']($random, $field));
            $unversioned = $written;
            unset($unversioned['format_version']);

            expect(plainDataOf($form['read']($unversioned, $registry)))->toBe($written, "{$name}, instance {$i}");
        }
    })->with(fn(): array => array_keys(PlainDataForms::all()))->with(fn(): array => [
        'ordinary ids' => [AwkwardIds::participants(AwkwardIds::sets()['ordinary ids'])],
    ]);
});
