<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Timeline\IntervalArithmetic;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use Throwable;

/**
 * Timeline and session-grid configuration with very large counts, and
 * what becomes of each.
 *
 * A count that is not refused where it should be does not fail an
 * assertion: it loops until the process is stopped, or allocates until PHP
 * is out of memory. So the cases are run in a process of their own that has
 * a time limit and a memory limit (`tests/Feature/CountLimitsTest.php`),
 * which is why they live here and not in a test file: that process loads
 * this class and calls printVerdicts().
 */
final class CountLimitCorpus
{
    /**
     * Run every case and print what it came to, one line of JSON for each
     * as soon as it is known (`["name", "verdict"]`), so that whoever reads
     * the output of a process that was stopped can tell which case it was
     * stopped in. A verdict is `loaded`, or `refused: ` and the name of the
     * reason, or `error: ` and the class of anything thrown that is not the
     * library's refusal.
     *
     * @throws \JsonException When a line cannot be encoded
     */
    public static function printVerdicts(): void
    {
        foreach (self::cases() as $name => $case) {
            try {
                $case();
                $verdict = 'loaded';
            } catch (InvalidConfigurationException $exception) {
                $verdict = 'refused: ' . ($exception->getReason()->name ?? 'no reason');
            } catch (Throwable $throwable) {
                $verdict = 'error: ' . $throwable::class;
            }

            echo json_encode([$name, $verdict], JSON_THROW_ON_ERROR), "\n";
            flush();
        }
    }

    /**
     * What every case must come to: the statement of the limits.
     *
     * @return array<string, string> Keyed by the name of the case, in the order of cases()
     */
    public static function expected(): array
    {
        $expected = [];
        foreach (self::table() as [$name, $verdict]) {
            $expected[$name] = $verdict;
        }

        return $expected;
    }

    /**
     * @return array<string, Closure(): mixed>
     */
    private static function cases(): array
    {
        $cases = [];
        foreach (self::table() as [$name, , $case]) {
            $cases[$name] = $case;
        }

        return $cases;
    }

    /**
     * @return list<array{string, string, Closure(): mixed}> Name, expected verdict, case
     */
    private static function table(): array
    {
        $range = 'refused: ValueOutOfRange';
        $huge = PHP_INT_MAX;

        $london = new DateTimeZone('Europe/London');
        $start = new DateTimeImmutable('2026-08-01 18:00', $london);
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');

        $timeline = static fn(array $with): TimelineDefinition => TimelineDefinition::fromArray(
            $with + ['start' => '2026-08-01 18:00', 'timezone' => 'Europe/London', 'round_interval' => 'P7D']
        );
        // A round interval long enough for any number of slots a second apart
        $roomy = ['round_interval' => 'P1000Y', 'slot_interval' => 'PT1S'];
        $relative = static function (string $text): DateInterval {
            $interval = DateInterval::createFromDateString($text);
            if (!$interval instanceof DateInterval) {
                throw new LogicException("Not a relative date string: {$text}");
            }

            return $interval;
        };
        $microsecond = new DateInterval('PT0S');
        $microsecond->f = 0.000001;

        $inRound = static fn(int $round): Schedule => new Schedule([new Event([$a, $b], new Round(1)), new Event([$a, $b], new Round($round))]);
        $pairing = static fn(int $round): RoundPairing => new RoundPairing($round, null, [new Event([$a, $b], new Round(1))]);

        $shape = static fn(array $with): SessionGrid => SessionGrid::fromArray($with);
        $instant = static fn(array $with): SessionGrid => SessionGrid::fromArray(
            $with + ['sessions' => ['2026-08-12 20:00'], 'timezone' => 'Europe/London', 'slot_interval' => 'PT1S']
        );
        $repack = static fn(SessionGrid $grid): mixed => (new ScheduleRepacker())->repack(new RepackRequest(
            [new MovableEvent('1', $a, $b), new MovableEvent('2', $a, $c), new MovableEvent('3', $b, $c)],
            [],
            $grid
        ));

        return [
            // --- slots per round of a timeline: at most 10,000
            ['timeline: slots_per_round at the largest integer', $range, static fn() => $timeline(['slots_per_round' => $huge] + $roomy)],
            ['timeline: slots_per_round at the largest integer, slots a minute apart in a week', $range, static fn() => $timeline(['slots_per_round' => $huge, 'slot_interval' => 'PT1M'])],
            ['timeline: slots_per_round at the largest integer, slots a day apart', $range, static fn() => $timeline(['slots_per_round' => $huge, 'round_interval' => 'P1000Y', 'slot_interval' => 'P1D'])],
            ['timeline: slots_per_round at the largest integer and no slot interval', $range, static fn() => $timeline(['slots_per_round' => $huge])],
            ['timeline: slots_per_round of a million', $range, static fn() => $timeline(['slots_per_round' => 1_000_000] + $roomy)],
            ['timeline: slots_per_round one over the limit', $range, static fn() => $timeline(['slots_per_round' => 10_001] + $roomy)],
            ['timeline: slots_per_round at the limit', 'loaded', static fn() => $timeline(['slots_per_round' => 10_000] + $roomy)],
            ['timeline: the constructor given the largest integer and slots a microsecond apart', $range, static fn() => new TimelineDefinition($start, new DateInterval('P1000Y'), $huge, $microsecond)],
            ['timeline: the constructor given the largest integer and a relative slot interval', $range, static fn() => new TimelineDefinition($start, new DateInterval('P1000Y'), $huge, $relative('1 minute'))],
            // 10,000 slots of a relative interval were fifty million additions
            ['timeline: slots at the limit, a relative minute apart', 'loaded', static fn() => new TimelineDefinition($start, new DateInterval('P1000Y'), 10_000, $relative('1 minute'))],

            // --- round numbers: any the arithmetic reaches, and 100,000 steps of a relative interval
            ['timeline: the time of the largest round number, weekly', $range, static fn() => $timeline([])->getSlotTime($huge)],
            ['timeline: the time of the largest round number, rounds a second apart', $range, static fn() => $timeline(['round_interval' => 'PT1S'])->getSlotTime($huge)],
            ['timeline: the time of round one million, weekly', 'loaded', static fn() => $timeline([])->getSlotTime(1_000_000)],
            ['timeline: the time of the largest round number, a relative week apart', $range, static fn() => (new TimelineDefinition($start, $relative('1 week')))->getSlotTime($huge)],
            ['timeline: the time of round 100,002, a relative week apart', $range, static fn() => (new TimelineDefinition($start, $relative('1 week')))->getSlotTime(100_002)],
            ['timeline: the time of round 100,001, a relative week apart', 'loaded', static fn() => (new TimelineDefinition($start, $relative('1 week')))->getSlotTime(100_001)],
            // Read in one pass to the limit and past it: here and not in the
            // test process, because a series computed step by step from its
            // anchor again would take hours to get there
            ['series: a relative second read to the limit of steps', 'loaded', static fn() => iterator_to_array(IntervalArithmetic::series($start, $relative('1 second'), 100_001))],
            ['series: a relative second read one step past the limit', $range, static fn() => iterator_to_array(IntervalArithmetic::series($start, $relative('1 second'), 100_002))],
            ['assign: a schedule with an event in the largest round number, weekly', $range, static fn() => (new TimelineAssigner())->assign($inRound($huge), $timeline([]))],
            ['assign: a schedule with an event in the largest round number, a relative week apart', $range, static fn() => (new TimelineAssigner())->assign($inRound($huge), new TimelineDefinition($start, $relative('1 week')))],
            ['assignRound: a pairing of the largest round number, weekly', $range, static fn() => (new TimelineAssigner())->assignRound($pairing($huge), $timeline([]))],
            ['assignRound: a pairing of the largest round number, a relative week apart', $range, static fn() => (new TimelineAssigner())->assignRound($pairing($huge), new TimelineDefinition($start, $relative('1 week')))],

            // --- positions of a grid: at most 100,000
            ['grid: session_count at the largest integer', $range, static fn() => $shape(['session_count' => $huge])],
            ['grid: session_count one over the limit', $range, static fn() => $shape(['session_count' => 100_001])],
            ['grid: session_count at the limit', 'loaded', static fn() => $shape(['session_count' => 100_000])],
            ['grid: slots_per_session at the largest integer, shape-only', $range, static fn() => $shape(['session_count' => 1, 'slots_per_session' => $huge])],
            ['grid: slots_per_session at the largest integer, two sessions', $range, static fn() => $shape(['session_count' => 2, 'slots_per_session' => $huge])],
            ['grid: slots_per_session at the largest integer, with instants', $range, static fn() => $instant(['slots_per_session' => $huge])],
            ['grid: slots_per_session one over the limit, with instants', $range, static fn() => $instant(['slots_per_session' => 100_001])],
            ['grid: slots_per_session at the limit, with instants', 'loaded', static fn() => $instant(['slots_per_session' => 100_000])],
            ['grid: an override at the largest integer, shape-only', $range, static fn() => $shape(['session_count' => 2, 'slots_per_session_overrides' => [1 => $huge]])],
            ['grid: an override at the largest integer, with instants', $range, static fn() => $instant(['slots_per_session_overrides' => [0 => $huge]])],
            ['grid: an override that brings the positions one over the limit', $range, static fn() => $shape(['session_count' => 2, 'slots_per_session_overrides' => [1 => 100_000]])],
            ['grid: an override that brings the positions to the limit', 'loaded', static fn() => $shape(['session_count' => 2, 'slots_per_session_overrides' => [1 => 99_999]])],
            ['grid: 1,000 sessions of 101 slots', $range, static fn() => $shape(['session_count' => 1000, 'slots_per_session' => 101])],
            ['grid: 1,000 sessions of 100 slots', 'loaded', static fn() => $shape(['session_count' => 1000, 'slots_per_session' => 100])],
            ['grid: 99,999 sessions of 99,999 slots, each count under the limit', $range, static fn() => $shape(['session_count' => 99_999, 'slots_per_session' => 99_999])],
            ['grid: shapeOnly() given the largest integer for the sessions', $range, static fn() => SessionGrid::shapeOnly($huge)],
            ['grid: shapeOnly() given the largest integer for the slots', $range, static fn() => SessionGrid::shapeOnly(1, $huge)],
            ['grid: the constructor given the largest integer and a relative slot interval', $range, static fn() => new SessionGrid([$start], $relative('1 second'), $huge)],
            // 100,000 slots of a relative interval were five thousand million additions
            ['grid: slots at the limit, a relative second apart', 'loaded', static fn() => new SessionGrid([$start], $relative('1 second'), 100_000)],
            ['grid: 100,001 session starts', $range, static function () use ($start): SessionGrid {
                $starts = [];
                for ($session = 0; $session <= 100_000; ++$session) {
                    $starts[] = $start->setTimestamp($start->getTimestamp() + $session * 3600);
                }

                return new SessionGrid($starts, new DateInterval('PT1M'));
            }],

            // --- capacity per slot: at most a million million, or unbounded
            ['grid: capacity_per_slot at the largest integer', $range, static fn() => $shape(['session_count' => 2, 'capacity_per_slot' => $huge])],
            ['grid: capacity_per_slot one over the limit', $range, static fn() => $shape(['session_count' => 2, 'capacity_per_slot' => 1_000_000_000_001])],
            ['grid: shapeOnly() given the largest integer for the capacity', $range, static fn() => SessionGrid::shapeOnly(2, 3, [], $huge)],
            ['grid: the largest capacity on the most positions, repacked', 'loaded', static fn() => $repack(SessionGrid::shapeOnly(100_000, 1, [], 1_000_000_000_000))],
            ['grid: a number written to mean no limit, repacked', 'loaded', static fn() => $repack($shape(['session_count' => 2, 'slots_per_session' => 3, 'capacity_per_slot' => 2_147_483_647]))],
            ['grid: an unbounded capacity, repacked', 'loaded', static fn() => $repack($shape(['session_count' => 2, 'slots_per_session' => 3, 'capacity_per_slot' => SessionGrid::UNBOUNDED]))],
        ];
    }
}
