<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Measured;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Tests\Support\ExampleResults;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use PHPUnit\Framework\Assert;

/*
 * tests/Support/ExampleResults.php is the seam between the examples and the
 * suite: it reads the results a script hands over and writes them as the
 * text the golden fixtures pin. The 21 examples only exercise the paths
 * they happen to take. These tests cover the rest: the scripts it must
 * refuse, and the fixture form of every kind of result.
 */

require_once ExampleResults::directory() . '/support/Example.php';

/**
 * Write a script to a temporary file, read it as an example, and remove it.
 *
 * @return array<string, mixed>
 *
 * @throws LogicException When the script is not built as an example
 * @throws PHPUnit\Framework\AssertionFailedError When the temporary file cannot be written
 */
function readAsExample(string $source): array
{
    $script = tempnam(sys_get_temp_dir(), 'example-script');
    if ($script === false) {
        Assert::fail('Could not create a temporary file in ' . sys_get_temp_dir());
    }

    try {
        file_put_contents($script, $source);

        return ExampleResults::read($script);
    } finally {
        unlink($script);
    }
}

describe('Reading an example', function (): void {
    it('hands back the named results of a script that returns them', function (): void {
        expect(readAsExample("<?php\nreturn ['Count' => 3, 'Names' => ['a', 'b']];\n"))
            ->toBe(['Count' => 3, 'Names' => ['a', 'b']]);
    });

    // An example that printed its own output would be back to mixing what it
    // computes with how it is displayed, and the suite would never see it
    it('refuses a script that displays something itself', function (string $source): void {
        expect(fn () => readAsExample($source))
            ->toThrow(LogicException::class, 'displayed something while it was included');
    })->with([
        'echo before the return' => ["<?php\necho 'Round 1';\nreturn ['Count' => 3];\n"],
        'text outside the PHP tags' => ["Schedule\n<?php\nreturn ['Count' => 3];\n"],
        'a single space' => ["<?php\necho ' ';\nreturn ['Count' => 3];\n"],
    ]);

    // "No checked results" must fail loudly: an example that hands over
    // nothing has nothing for a demonstration or a fixture to hold
    it('refuses a script that hands over no named result', function (string $source): void {
        expect(fn () => readAsExample($source))
            ->toThrow(LogicException::class, 'hand over at least one named result');
    })->with([
        'no return statement' => ["<?php\n\$count = 3;\n"],
        'an empty set' => ["<?php\nreturn [];\n"],
        'a list without names' => ["<?php\nreturn [3, 4];\n"],
        'a scalar' => ["<?php\nreturn 'done';\n"],
        'null' => ["<?php\nreturn null;\n"],
    ]);

    it('refuses a script that does not exist', function (): void {
        expect(fn () => ExampleResults::read(sys_get_temp_dir() . '/no-such-example.php'))
            ->toThrow(LogicException::class, 'no-such-example.php does not exist.');
    });

    // The output buffer opened for the script must be closed again even
    // when the script throws, or everything the suite prints afterwards is lost
    it('leaves the output buffering as it found it when the script throws', function (): void {
        $level = ob_get_level();

        expect(fn () => readAsExample("<?php\necho 'partial';\nthrow new RuntimeException('broken example');\n"))
            ->toThrow(RuntimeException::class, 'broken example');
        expect(ob_get_level())->toBe($level);
    });

    it('keeps the variables of one script out of the next', function (): void {
        readAsExample("<?php\n\$leak = 'from the first';\nreturn ['A' => 1];\n");

        expect(readAsExample("<?php\nreturn ['Leaked' => isset(\$leak)];\n"))->toBe(['Leaked' => false]);
    });

    it('lists the numbered scripts only, in order', function (): void {
        $names = ExampleResults::names();
        $sorted = $names;
        sort($sorted);

        expect($names)->toBe($sorted);
        expect($names)->not->toContain('index');
        foreach ($names as $name) {
            expect($name)->toMatch('/^\d\d-[a-z\d-]+$/');
        }

        // Two examples sharing a number would make "the next number" ambiguous
        expect(array_unique(array_map(static fn (string $name): string => substr($name, 0, 2), $names)))->toHaveCount(count($names));
    });
});

describe('Fixture form of a result', function (): void {
    it('writes scalars so that null, booleans and text cannot be confused with an empty result', function (): void {
        expect(ExampleResults::lines(null))->toBe(['null'])
            ->and(ExampleResults::lines(true))->toBe(['true'])
            ->and(ExampleResults::lines(false))->toBe(['false'])
            ->and(ExampleResults::lines(0))->toBe(['0'])
            ->and(ExampleResults::lines(''))->toBe([''])
            ->and(ExampleResults::lines([]))->toBe(['(none)'])
            ->and(ExampleResults::lines("two\nlines"))->toBe(['two', 'lines']);
    });

    it('writes a float the same way whatever the precision settings', function (): void {
        $precision = (string) ini_get('precision');
        $serializePrecision = (string) ini_get('serialize_precision');

        ini_set('precision', '3');
        ini_set('serialize_precision', '3');
        try {
            $lines = ExampleResults::lines(['third' => 1 / 3, 'whole' => 6.0, 'large' => 1234567.891, 'zero' => 0.0, 'sum' => 0.1 + 0.2]);
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }

        expect($lines)->toBe(['third: 0.333333', 'whole: 6', 'large: 1234567.891', 'zero: 0', 'sum: 0.3']);
    });

    // The whole point of Measured: the figure changes on every run, so the
    // fixture holds the unit and the reason and never the figure
    it('writes a measured value as its unit and reason, never its value', function (): void {
        $lines = ExampleResults::lines(['Took' => new Measured(12.3456, 'ms', 'A duration differs on every run.')]);

        expect($lines)->toBe(['Took: measured (ms): A duration differs on every run.'])
            ->and(implode("\n", $lines))->not->toContain('12');
    });

    it('finds a measured value however deep it is, and none where there is none', function (): void {
        $measured = new Measured(1, 'ms', 'It varies.');

        expect(ExampleResults::containsMeasured($measured))->toBeTrue()
            ->and(ExampleResults::containsMeasured(['a' => ['b' => [1, 'x', $measured]]]))->toBeTrue()
            ->and(ExampleResults::containsMeasured(['a' => ['b' => [1, 'x', null]], 'c' => new Schedule()]))->toBeFalse()
            ->and(ExampleResults::containsMeasured([]))->toBeFalse();
    });

    // A result the writer does not know must stop the run: writing its class
    // name, or nothing, would pin a fixture that cannot notice a change
    it('refuses a result it has no fixture form for', function (mixed $value): void {
        expect(fn () => ExampleResults::lines($value))->toThrow(LogicException::class, 'has no fixture form');
    })->with([
        'an object of another class' => [new stdClass()],
        'an object inside a list' => [[1, new ArrayObject()]],
        'an object as a named value' => [['When' => new DateInterval('P1D')]],
    ]);

    it('writes ids, not labels, for participants, events and results', function (): void {
        $alpha = new Participant('a', 'Alpha', 1, ['city' => 'São Paulo']);
        $beta = new Participant('b', 'Beta');
        $event = new Event([$alpha, $beta], new Round(2));

        expect(ExampleResults::lines($alpha))->toBe(['a "Alpha" seed=1 {"city":"São Paulo"}'])
            ->and(ExampleResults::lines($beta))->toBe(['b "Beta"'])
            ->and(ExampleResults::lines($event))->toBe(['R2: a-b'])
            ->and(ExampleResults::lines(new Event([$alpha, $beta])))->toBe(['R?: a-b'])
            ->and(ExampleResults::lines(new Result($event, $alpha, ['a' => 2, 'b' => 1])))->toBe(['R2: a-b => a (2-1)'])
            ->and(ExampleResults::lines(new Result($event)))->toBe(['R2: a-b => draw'])
            ->and(ExampleResults::lines(new Result($event, null, ['a' => 1.5, 'b' => 1.5])))->toBe(['R2: a-b => draw (1.5-1.5)']);
    });

    it('writes a kickoff in UTC whatever zone it carries and whatever the default timezone is', function (): void {
        $kickoff = new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('Europe/London'));
        $alpha = new Participant('a', 'Alpha');
        $beta = new Participant('b', 'Beta');
        $timezone = date_default_timezone_get();

        date_default_timezone_set('Pacific/Auckland');
        try {
            $lines = ExampleResults::lines([
                'When' => $kickoff,
                'Calendar' => new ScheduledSchedule([
                    new ScheduledEvent(new Event([$alpha, $beta], new Round(1)), $kickoff, 'North Pitch'),
                    new ScheduledEvent(new Event([$beta, $alpha]), $kickoff),
                ]),
                'Empty calendar' => new ScheduledSchedule([]),
            ]);
        } finally {
            date_default_timezone_set($timezone);
        }

        expect($lines)->toBe([
            'When: 2026-08-01T17:00:00Z',
            'Calendar:',
            '  R1: a-b @ 2026-08-01T17:00:00Z on North Pitch',
            '  R?: b-a @ 2026-08-01T17:00:00Z',
            'Empty calendar: (no events)',
        ]);
    });

    it('writes a standings row with its counts, scores and tiebreakers', function (): void {
        $table = new Standings([
            (new StandingEntry(new Participant('a', 'Alpha'), 2, 1, 1, 0, 4.0, 3.0, 1.0))->withTiebreakers(['buchholz' => 1.5]),
            new StandingEntry(new Participant('b', 'Beta'), 2, 0, 1, 1, 1.0, 1.0, 3.0),
        ]);

        expect(ExampleResults::lines($table))->toBe([
            '1. a played=2 wins=1 draws=1 losses=0 value=4 for=3 against=1 buchholz=1.5',
            '2. b played=2 wins=0 draws=1 losses=1 value=1 for=1 against=3',
        ])->and(ExampleResults::lines(new Standings([])))->toBe(['(no entries)']);
    });

    it('nests arrays by indentation and keeps a one-line value on the line of its name', function (): void {
        $alpha = new Participant('a', 'Alpha');
        $beta = new Participant('b', 'Beta');
        $event = new Event([$alpha, $beta], new Round(1));

        expect(ExampleResults::lines([
            'Count' => 3,
            'Rounds' => ['Round 1' => [new Result($event, $beta)], 'Round 2' => []],
            'Pairing' => new RoundPairing(1, 'final', [$event], []),
            'Failure' => new RuntimeException("first line\nsecond line"),
            'List' => ['x', ['y']],
        ]))->toBe([
            'Count: 3',
            'Rounds:',
            '  Round 1:',
            '    - R1: a-b => b',
            '  Round 2:',
            '    (none)',
            'Pairing: ' . ExampleResults::lines(new RoundPairing(1, 'final', [$event], []))[0],
            'Failure:',
            '  RuntimeException: first line',
            '  second line',
            'List:',
            '  - x',
            '  -',
            '    - y',
        ]);
    });
});
