<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Tests\Support\CountLimitCorpus;
use MissionGaming\Tactician\Timeline\IntervalArithmetic;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use PHPUnit\Framework\Assert;

/*
 * A count in timeline or session-grid configuration is an integer the
 * caller gives, and building the object must not cost time or memory in
 * proportion to it unless it has a stated limit. Each count has one, and a
 * value above it is refused (`ValueOutOfRange`) before anything of that
 * size is visited or allocated.
 *
 * The tests in this process use values just over a limit, which are cheap
 * even where a limit is not applied. The very large values, which loop
 * without end or exhaust memory where a limit is not applied, run in a
 * process of their own under a time limit and a memory limit.
 */

/**
 * The exception a configuration is refused with, or null when it is
 * accepted.
 *
 * @param Closure(): mixed $build
 */
function countLimitRefusal(Closure $build): ?InvalidConfigurationException
{
    try {
        $build();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    return null;
}

/**
 * An interval made from a relative date string, which PHP adds one step at
 * a time.
 *
 * @throws DateMalformedIntervalStringException When PHP does not read the string
 * @throws PHPUnit\Framework\AssertionFailedError When PHP reads it as no interval
 * @throws PHPUnit\Framework\Exception
 */
function countLimitRelativeInterval(string $dateString): DateInterval
{
    $interval = DateInterval::createFromDateString($dateString);
    Assert::assertInstanceOf(DateInterval::class, $interval);

    return $interval;
}

/**
 * The verdicts CountLimitCorpus prints in a PHP process of its own, which
 * PHP stops after the given seconds of work or megabytes of memory.
 *
 * @return array{verdicts: array<string, string>, errors: string, exitCode: int}
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the process cannot be started
 * @throws PHPUnit\Framework\Exception
 * @throws JsonException When the process prints a line that is not JSON
 */
function countLimitVerdictsInChildProcess(int $seconds, int $megabytes): array
{
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . '\\' . CountLimitCorpus::class . '::printVerdicts();';

    $process = proc_open(
        [
            PHP_BINARY,
            '-d', 'error_reporting=-1',
            '-d', 'display_errors=stderr',
            '-d', "max_execution_time={$seconds}",
            '-d', "memory_limit={$megabytes}M",
            '-r', $code,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    Assert::assertIsResource($process, 'Could not start PHP for the child process');

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $verdicts = [];
    foreach (array_filter(explode("\n", $output), static fn(string $line): bool => $line !== '') as $line) {
        $decoded = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);
        Assert::assertIsString($decoded[0] ?? null);
        Assert::assertIsString($decoded[1] ?? null);
        $verdicts[$decoded[0]] = $decoded[1];
    }

    return ['verdicts' => $verdicts, 'errors' => $errors, 'exitCode' => $exitCode];
}

describe('The limits on the counts of a timeline and of a session grid', function (): void {
    it('are these numbers', function (): void {
        // Changing one changes which configuration loads
        expect(TimelineDefinition::MAX_SLOTS_PER_ROUND)->toBe(10_000)
            ->and(SessionGrid::MAX_POSITIONS)->toBe(100_000)
            ->and(SessionGrid::MAX_CAPACITY_PER_SLOT)->toBe(1_000_000_000_000)
            ->and(IntervalArithmetic::MAX_RELATIVE_STEPS)->toBe(100_000);
    });

    it('refuse a count one over the limit, and say which and what the limit is', function (Closure $build, string $message, array $context): void {
        $refusal = countLimitRefusal($build);

        Assert::assertNotNull($refusal, 'A count over its limit was accepted.');
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
            ->and($refusal->getMessage())->toBe("Invalid scheduler configuration: {$message}")
            ->and($refusal->getContext())->toBe($context);
    })->with([
        'slots per round, from plain data' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01 18:00', 'timezone' => 'Europe/London', 'round_interval' => 'P1000Y',
                'slots_per_round' => 10_001, 'slot_interval' => 'PT1S',
            ]),
            'A round has at most 10000 slots',
            ['slots_per_round' => 10_001, 'limit' => 10_000],
        ],
        'slots per round, from the constructor' => [
            fn() => new TimelineDefinition(
                new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
                new DateInterval('P1000Y'),
                10_001,
                new DateInterval('PT1S')
            ),
            'A round has at most 10000 slots',
            ['slots_per_round' => 10_001, 'limit' => 10_000],
        ],
        // The count is refused for itself, before what else is wrong with it
        'slots per round with no slot interval' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01 18:00', 'timezone' => 'Europe/London', 'round_interval' => 'P7D', 'slots_per_round' => 10_001,
            ]),
            'A round has at most 10000 slots',
            ['slots_per_round' => 10_001, 'limit' => 10_000],
        ],
        'the sessions of a shape-only grid' => [
            fn() => SessionGrid::fromArray(['session_count' => 100_001]),
            'A session grid has at most 100000 positions, and so at most that many sessions',
            ['session_count' => 100_001, 'limit' => 100_000],
        ],
        'the sessions of shapeOnly()' => [
            fn() => SessionGrid::shapeOnly(100_001),
            'A session grid has at most 100000 positions, and so at most that many sessions',
            ['session_count' => 100_001, 'limit' => 100_000],
        ],
        'the slots of every session' => [
            fn() => SessionGrid::fromArray(['session_count' => 1, 'slots_per_session' => 100_001]),
            'A session grid has at most 100000 positions, and so a session at most that many slots',
            ['slots_per_session' => 100_001, 'limit' => 100_000],
        ],
        'the slots of every session of a grid with instants' => [
            fn() => SessionGrid::fromArray([
                'sessions' => ['2026-08-12 20:00'], 'timezone' => 'Europe/London', 'slot_interval' => 'PT1S', 'slots_per_session' => 100_001,
            ]),
            'A session grid has at most 100000 positions, and so a session at most that many slots',
            ['slots_per_session' => 100_001, 'limit' => 100_000],
        ],
        'the slots of one session, by an override' => [
            fn() => SessionGrid::fromArray(['session_count' => 3, 'slots_per_session_overrides' => [2 => 100_001]]),
            'A session grid has at most 100000 positions, and so a session at most that many slots',
            ['session' => 2, 'slots' => 100_001, 'limit' => 100_000],
        ],
        'the positions, where every count is within the limit' => [
            fn() => SessionGrid::fromArray(['session_count' => 1000, 'slots_per_session' => 100, 'slots_per_session_overrides' => [7 => 101]]),
            'A session grid has at most 100000 positions',
            ['positions' => 100_001, 'sessions' => 1000, 'limit' => 100_000],
        ],
        'the capacity of a slot' => [
            fn() => SessionGrid::fromArray(['session_count' => 2, 'capacity_per_slot' => 1_000_000_000_001]),
            'A slot has a capacity of at most 1000000000000 events, or an unbounded one (null)',
            ['capacity_per_slot' => 1_000_000_000_001, 'limit' => 1_000_000_000_000],
        ],
        'the steps of an interval made from a relative date string' => [
            fn() => (new TimelineDefinition(
                new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
                countLimitRelativeInterval('next weekday')
            ))->getSlotTime(100_002),
            '100001 steps of an interval made from a relative date string are more than the 100000 that are added one at a time; '
                . 'write the interval as an ISO 8601 duration',
            ['steps' => 100_001, 'interval' => 'next weekday', 'limit' => 100_000],
        ],
    ]);

    it('accept a count at the limit', function (): void {
        $timeline = TimelineDefinition::fromArray([
            'start' => '2026-08-01 18:00', 'timezone' => 'UTC', 'round_interval' => 'P1D',
            'slots_per_round' => 10_000, 'slot_interval' => 'PT1S',
        ]);
        $grid = SessionGrid::fromArray(['session_count' => 1000, 'slots_per_session' => 100, 'capacity_per_slot' => 1_000_000_000_000]);

        expect($timeline->getSlotTime(1, 9_999)->format('Y-m-d H:i:s'))->toBe('2026-08-01 20:46:39')
            ->and($grid->getPositionCount())->toBe(100_000)
            ->and($grid->positionOf(99_999))->toBe(['session' => 999, 'slot' => 99])
            ->and($grid->getCapacityPerSlot())->toBe(1_000_000_000_000)
            ->and(SessionGrid::fromArray($grid->toArray())->getPositionCount())->toBe(100_000);
    });

    it('leave a round number without a limit of its own where the interval is a duration', function (): void {
        // Multiplied out, so round a million costs what round two costs;
        // what bounds it is the range of PHP's timestamps. The date is the
        // one 0.2.3 reached by adding a week 999,999 times
        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')), new DateInterval('P7D'));

        expect($timeline->getSlotTime(1_000_000)->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i'))->toBe('21191-11-30 18:00');
    });

    it('refuse a slot count override that is not an integer for its type, not for its size', function (mixed $slots): void {
        $refusal = countLimitRefusal(fn() => SessionGrid::fromArray(['session_count' => 2, 'slots_per_session_overrides' => [1 => $slots]]));

        Assert::assertNotNull($refusal, 'An override that is not an integer was accepted.');
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::WrongValueType)
            ->and($refusal->getMessage())->toBe('Invalid scheduler configuration: A slot count override must be an integer')
            ->and($refusal->getContext())->toBe(['session' => 1, 'slots' => $slots]);
    })->with([
        'a numeric string' => ['3'],
        'a float' => [3.0],
        'a float with a fraction' => [3.5],
        'true' => [true],
        'null' => [null],
        'a list' => [[3]],
    ]);

    it('refuse the very large counts at once, in a process that is stopped if it does not answer', function (): void {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled, so the cases cannot be run under a time limit.');
        }

        // The whole corpus takes under two seconds; one count that is
        // visited and not refused takes hours, or all the memory there is
        $result = countLimitVerdictsInChildProcess(seconds: 20, megabytes: 256);
        $expected = CountLimitCorpus::expected();

        $unanswered = array_values(array_diff(array_keys($expected), array_keys($result['verdicts'])));
        Assert::assertSame(
            [],
            array_slice($unanswered, 0, 1),
            'The process was stopped while it worked on this case: ' . trim($result['errors'])
        );
        Assert::assertSame('', $result['errors'], 'The child process wrote to its error stream');
        Assert::assertSame(0, $result['exitCode']);

        expect($result['verdicts'])->toBe($expected)
            ->and(count($expected))->toBe(46);
    });
});

describe('The slots of a round or a session, computed in one pass', function (): void {
    it('are the times the steps give one by one, for every kind of interval', function (): void {
        $utc = new DateTimeZone('UTC');
        $localHour = (new DateTimeImmutable('2026-01-01 00:00', $utc))->diff(new DateTimeImmutable('2026-01-01 01:00', $utc));
        $fraction = new DateInterval('PT1S');
        $fraction->f = 0.29;

        $intervals = [
            new DateInterval('PT25M'), new DateInterval('PT1H'), new DateInterval('P1D'), new DateInterval('P1M'),
            new DateInterval('P1M7DT12H'), $localHour, $fraction,
            DateInterval::createFromDateString('25 minutes'), DateInterval::createFromDateString('1 month'),
            DateInterval::createFromDateString('next weekday'), DateInterval::createFromDateString('last day of next month'),
        ];
        $anchors = [
            new DateTimeImmutable('2026-01-31 19:00', new DateTimeZone('Europe/London')),
            new DateTimeImmutable('2026-03-28 23:30', new DateTimeZone('Europe/London')),
            new DateTimeImmutable('2026-10-24 23:30', new DateTimeZone('Europe/London')),
            new DateTimeImmutable('2011-12-28 10:00', new DateTimeZone('Pacific/Apia')),
            new DateTimeImmutable('2026-08-12 20:00:00.250000', $utc),
        ];

        $compared = 0;
        foreach ($intervals as $interval) {
            Assert::assertInstanceOf(DateInterval::class, $interval);
            foreach ($anchors as $anchor) {
                foreach ([0, 1, 2, 40] as $count) {
                    $series = iterator_to_array(IntervalArithmetic::series($anchor, $interval, $count));

                    expect(array_keys($series))->toBe($count === 0 ? [] : range(0, $count - 1));
                    foreach ($series as $step => $time) {
                        Assert::assertEquals(IntervalArithmetic::advance($anchor, $interval, $step), $time);
                        Assert::assertSame($anchor->getTimezone()->getName(), $time->getTimezone()->getName());
                        ++$compared;
                    }
                }
            }
        }

        expect($compared)->toBe(11 * 5 * 43);
    });

    it('are, for an interval made from a relative date string, each the one before plus the interval', function (): void {
        // Worked out here by adding, with no call to the arithmetic
        $london = new DateTimeZone('Europe/London');
        $start = new DateTimeImmutable('2026-03-28 20:00', $london);
        $interval = DateInterval::createFromDateString('25 minutes');
        Assert::assertInstanceOf(DateInterval::class, $interval);

        $timeline = new TimelineDefinition($start, new DateInterval('P7D'), 300, $interval);
        $grid = new SessionGrid([$start], $interval, 300);

        $expected = $start;
        foreach (range(0, 299) as $slot) {
            Assert::assertEquals($expected, $timeline->getSlotTime(1, $slot));
            Assert::assertEquals($expected, $grid->getSlotTime(0, $slot));
            $expected = $expected->add($interval);
        }

        // The round after is counted from its own first slot
        expect($timeline->getSlotTime(2, 1))->toEqual($start->add(new DateInterval('P7D'))->add($interval));
    });
});
