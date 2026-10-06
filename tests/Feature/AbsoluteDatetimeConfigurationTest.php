<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Tests\Support\DatetimeCorpus;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\DateTimeString;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use MissionGaming\Tactician\Timeline\ZonedTime;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

// The rule: a datetime read from plain data states its date in full, and
// means what it writes. PHP's parser also accepts `now`, `tomorrow`,
// `+1 week` and the empty string, and fills a date, or the year of one, that
// a string leaves out from the clock, so a timeline or a session grid built
// from such a string was a different one each time the configuration was
// loaded. The library never asks for the current time; such a string is a
// configuration error. So is a string that PHP reads to another instant than
// the one written: a date or a time that does not exist, a weekday name that
// is not the weekday of the date, a second timezone that is not the first.
//
// Everything else PHP reads is accepted as it was, to the same instant. A
// date without a time of day is midnight, and an offset on top of a stated
// date (`2026-08-01 20:00 +1 week`) is counted from that date: PHP takes
// neither from the clock.
//
// The library has no clock to replace in a test, and is not to grow one for
// this. That no accepted string depends on the current time is shown another
// way, in three parts:
//
// 1. Every string of a generated corpus of absolute datetimes parses to the
//    fields that were written into it, and every string of a corpus of
//    offsets from a stated date to the fields the offset gives from that
//    date. The expected fields come from the numbers the string was built
//    from, not from PHP's reading of it, so a field taken from the clock
//    would have to equal the written one by chance, for every one of several
//    thousand strings.
// 2. Every string of a corpus of relative and partial datetimes is rejected.
// 3. A child process under another default timezone, in which "today" is
//    another day for part of every day, gives the same verdict on every
//    string of the corpora.
//
// Gap left knowingly: no test here runs under another clock, because the
// suite has no means to fake one. A child process under another default
// timezone moves "today" by a day at most, which a string that took only its
// year from the clock would not show; part 1 is what covers that. When this
// rule was written, some 200,000 generated strings were also run under two
// faked system clocks eleven years apart, on PHP 8.3 and 8.5, and every
// verdict was the same under both.

/**
 * The data of one scheduled event between two participants, with the given
 * kickoff, and the registry that resolves its participants.
 *
 * @return array{0: array<string, mixed>, 1: array<string, Participant>}
 */
function scheduledEventDataWithKickoff(mixed $kickoff): array
{
    $alice = new Participant('a', 'Alice');
    $bob = new Participant('b', 'Bob');

    $event = new ScheduledEvent(
        new Event([$alice, $bob], new Round(1)),
        new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC'))
    );

    return [[...$event->toArray(), 'kickoff' => $kickoff], ['a' => $alice, 'b' => $bob]];
}

/**
 * The verdicts of DatetimeCorpus::verdicts() as a PHP process of its own
 * gives them, under the given default timezone.
 *
 * @return array{timezone: string, today: string, verdicts: array<string, string>}
 *
 * @throws AssertionFailedError When the process cannot be run or prints something else
 * @throws PHPUnit\Framework\Exception
 * @throws JsonException When the process prints something that is not JSON
 */
function datetimeVerdictsInChildProcess(string $defaultTimezone, string $configurationTimezone): array
{
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . 'echo json_encode(['
        . '"timezone" => date_default_timezone_get(),'
        . '"today" => date("Y-m-d"),'
        . '"verdicts" => \\' . DatetimeCorpus::class . '::verdicts(' . var_export($configurationTimezone, true) . '),'
        . '], JSON_THROW_ON_ERROR);';

    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', "date.timezone={$defaultTimezone}", '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [...getenv(), 'TZ' => $defaultTimezone]
    );
    Assert::assertIsResource($process, 'Could not start PHP for the child process');

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    Assert::assertSame('', $errors, 'The child process wrote to its error stream');
    Assert::assertSame(0, $exitCode, "The child process exited with code {$exitCode}: {$output}");

    $decoded = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    Assert::assertIsArray($decoded);
    Assert::assertIsString($decoded['timezone'] ?? null);
    Assert::assertIsString($decoded['today'] ?? null);
    Assert::assertIsArray($decoded['verdicts'] ?? null);

    $verdicts = [];
    foreach ($decoded['verdicts'] as $value => $verdict) {
        Assert::assertIsString($verdict);
        $verdicts[(string) $value] = $verdict;
    }

    return ['timezone' => $decoded['timezone'], 'today' => $decoded['today'], 'verdicts' => $verdicts];
}

describe('a datetime string that does not state an instant', function (): void {
    it('is a configuration error at every place that reads a configured instant', function (string $value, Closure $entryPoint, string $field): void {
        $thrown = null;
        try {
            $entryPoint($value);
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        Assert::assertNotNull($thrown, 'The string ' . var_export($value, true) . ' was accepted.');
        expect($thrown->getReason())->toBe(InvalidConfigurationReason::UnparseableTime)
            ->and($thrown->getMessage())->toBe("Invalid scheduler configuration: {$field} or its timezone is not parseable")
            ->and($thrown->getContext()[$field] ?? null)->toBe($value)
            ->and($thrown->getContext()['timezone'] ?? null)->toBe('Europe/London')
            ->and($thrown->getRequirements())->toBe([]);
    })->with([
        'the empty string' => [''],
        'now' => ['now'],
        'tomorrow' => ['tomorrow'],
        'a relative offset' => ['+1 week'],
        'a relative weekday with a time' => ['next monday 20:00'],
        'a time of day without a date' => ['20:00'],
        'a date without its year' => ['August 1 20:00'],
        'a day the month does not have' => ['2026-02-30 20:00'],
        'a day the month does not have, without a time' => ['2026-02-30'],
        'an hour the day does not have' => ['2026-08-01 24:00'],
        'a second the minute does not have' => ['2026-08-01 23:59:60'],
        'a weekday that is not the weekday of the date' => ['Mon, 01 Aug 2026 19:00:00'],
    ])->with([
        'ZonedTime::parse()' => [
            fn(string $value) => ZonedTime::parse($value, 'Europe/London', 'start'),
            'start',
        ],
        'the start of a timeline' => [
            fn(string $value) => TimelineDefinition::fromArray(['start' => $value, 'timezone' => 'Europe/London', 'round_interval' => 'P7D']),
            'start',
        ],
        'a session of a grid' => [
            fn(string $value) => SessionGrid::fromArray([
                'sessions' => ['2026-08-12 20:00', $value],
                'timezone' => 'Europe/London',
                'slot_interval' => 'PT25M',
            ]),
            'sessions[1]',
        ],
        'the start of a blackout window' => [
            fn(string $value) => BlackoutRule::fromArray(['windows' => [
                ['from' => $value, 'to' => '2026-11-17 00:00', 'timezone' => 'Europe/London'],
            ]]),
            'from',
        ],
        'the end of a blackout window' => [
            fn(string $value) => BlackoutRule::fromArray(['windows' => [
                ['from' => '2026-11-09 00:00', 'to' => $value, 'timezone' => 'Europe/London'],
            ]]),
            'to',
        ],
    ]);

    it('is rejected as a kickoff of scheduled-event data', function (string $value): void {
        [$data, $participants] = scheduledEventDataWithKickoff($value);

        expect(fn() => ScheduledEvent::fromArray($data, $participants))
            ->toThrow(InvalidInputException::class, 'Scheduled event kickoff is not parseable');
        expect(fn() => ScheduledSchedule::fromArray([
            'participants' => array_map(fn(Participant $participant): array => $participant->toArray(), array_values($participants)),
            'events' => [$data],
        ]))->toThrow(InvalidInputException::class, 'Scheduled event kickoff is not parseable');
    })->with([
        'the empty string' => [''],
        'now' => ['now'],
        'tomorrow' => ['tomorrow'],
        'a relative offset' => ['+1 week'],
        'a relative weekday with a time' => ['next monday 20:00'],
        'a time of day without a date' => ['20:00'],
        'a date without its year' => ['August 1 20:00'],
        'a day the month does not have' => ['2026-02-30T19:00:00Z'],
        'an hour the day does not have' => ['2026-08-01T24:00:00Z'],
        'a weekday that is not the weekday of the date' => ['Mon, 01 Aug 2026 19:00:00 +0000'],
        'two offsets that differ' => ['2026-08-01T19:00:00 +01:00 +05:00'],
    ]);

    // A second timezone is a contradiction only when it is not the first.
    // The declared timezone is the one both strings carry, so neither is
    // rejected for it.
    it('is a configuration error when it carries two timezones that differ', function (): void {
        expect(ZonedTime::parse('2026-08-01 19:00 +01:00 +01:00', '+01:00', 'start')->format(DATE_ATOM))->toBe('2026-08-01T19:00:00+01:00');

        $exception = null;
        try {
            ZonedTime::parse('2026-08-01 19:00 +01:00 +05:00', '+01:00', 'start');
        } catch (InvalidConfigurationException $thrown) {
            $exception = $thrown;
        }

        expect($exception?->getReason())->toBe(InvalidConfigurationReason::UnparseableTime)
            ->and($exception?->getPrevious())->toBeNull();
    });

    // A string with both faults was reported for its timezone before the
    // other fault was looked for, and still is.
    it('is reported for a timezone that contradicts the declared one first', function (string $value, string $embedded): void {
        $exception = null;
        try {
            ZonedTime::parse($value, 'Europe/London', 'start');
        } catch (InvalidConfigurationException $thrown) {
            $exception = $thrown;
        }

        expect($exception?->getReason())->toBe(InvalidConfigurationReason::TimezoneMismatch)
            ->and($exception?->getMessage())->toBe(
                "Invalid scheduler configuration: The start string carries its own timezone; declare the zone only via the 'timezone' field"
            )
            ->and($exception?->getContext())->toBe(['start' => $value, 'timezone' => 'Europe/London', 'embedded_timezone' => $embedded]);
    })->with([
        'tomorrow in another zone' => ['tomorrow UTC', 'UTC'],
        'a time of day with an offset' => ['20:00 +05:00', '+05:00'],
        'a day the month does not have, with Z' => ['2026-02-30T19:00:00Z', 'Z'],
        'the wrong weekday, as RFC 2822 writes it' => ['Mon, 01 Aug 2026 19:00:00 +0000', '+00:00'],
        'an hour the day does not have, with an abbreviation' => ['2026-08-01 24:00 EST', 'EST'],
        'two offsets that differ' => ['2026-08-01 19:00 +01:00 +05:00', '+01:00'],
    ]);

    it('is unparseable, not a timezone mismatch, when its timezone is the declared one', function (string $value): void {
        $exception = null;
        try {
            ZonedTime::parse($value, 'Europe/London', 'start');
        } catch (InvalidConfigurationException $thrown) {
            $exception = $thrown;
        }

        expect($exception?->getReason())->toBe(InvalidConfigurationReason::UnparseableTime);
    })->with([
        'tomorrow in the declared zone' => ['tomorrow Europe/London'],
        'a day the month does not have' => ['2026-02-30 19:00 Europe/London'],
    ]);

    // PHP's own error stays attached where PHP raised one: a string its
    // parser rejects is reported as it was before.
    it('keeps the error PHP raised for a string PHP cannot parse', function (): void {
        $thrown = null;
        try {
            ZonedTime::parse('half past never', 'UTC', 'start');
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getPrevious())->toBeInstanceOf(Exception::class)
            ->and($thrown?->getReason())->toBe(InvalidConfigurationReason::UnparseableTime)
            ->and($thrown?->getMessage())->toBe('Invalid scheduler configuration: start or its timezone is not parseable');

        [$data, $participants] = scheduledEventDataWithKickoff('half past never');
        $thrown = null;
        try {
            ScheduledEvent::fromArray($data, $participants);
        } catch (InvalidInputException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getPrevious())->toBeInstanceOf(Exception::class)
            ->and($thrown?->getMessage())->toBe('Scheduled event kickoff is not parseable');
    });

    // A relative string has no error of PHP's to attach: PHP accepts it
    it('has no previous exception when PHP would have accepted the string', function (): void {
        $thrown = null;
        try {
            ZonedTime::parse('tomorrow', 'UTC', 'start');
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown?->getPrevious())->toBeNull();
    });

    // A bad timezone is still reported with PHP's error, whatever the string
    it('keeps the error of a timezone PHP does not know', function (): void {
        $thrown = null;
        try {
            ZonedTime::parse('2026-08-01 19:00', 'Neverland/Nowhere', 'start');
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getPrevious())->toBeInstanceOf(Exception::class)
            ->and($thrown?->getReason())->toBe(InvalidConfigurationReason::UnparseableTime);
    });
});

describe('an absolute datetime string', function (): void {
    // Every form the documentation, the examples, the tests and the golden
    // fixtures use, and the other absolute forms PHP reads. Each parsed to
    // this instant before relative strings were rejected, and still does.
    it('parses to the instant it states', function (string $value, string $timezone, string $instant): void {
        expect(ZonedTime::parse($value, $timezone, 'start')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'))
            ->toBe($instant);
    })->with([
        'a space, with seconds' => ['2026-08-01 19:00:00', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        'a space, without seconds' => ['2026-08-01 19:00', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        'a T, with seconds' => ['2026-08-01T19:00:00', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        'a T, without seconds' => ['2026-08-01T19:00', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        'midnight written out' => ['2026-11-09 00:00:00', 'Europe/London', '2026-11-09T00:00:00.000000Z'],
        'fractional seconds' => ['2026-08-01T19:00:00.123456', 'UTC', '2026-08-01T19:00:00.123456Z'],
        'a fraction of one digit' => ['2026-08-01 19:00:00.5', 'UTC', '2026-08-01T19:00:00.500000Z'],
        'seconds that are not zero' => ['2026-08-01 19:00:07', 'UTC', '2026-08-01T19:00:07.000000Z'],
        'the last second of a day' => ['2026-12-31 23:59:59', 'UTC', '2026-12-31T23:59:59.000000Z'],
        '29 February of a leap year' => ['2024-02-29 12:00', 'UTC', '2024-02-29T12:00:00.000000Z'],
        'the zone of the timezone field, repeated' => ['2026-08-01 19:00:00 Europe/London', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        'an offset under the same declared offset' => ['2026-08-01T19:00:00+05:00', '+05:00', '2026-08-01T14:00:00.000000Z'],
        'a declared offset and no zone in the string' => ['2026-08-01 19:00', '-03:30', '2026-08-01T22:30:00.000000Z'],
        'compact ISO 8601' => ['20260801T190000', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'an hour and no minutes after a T' => ['2026-08-01T19', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a twelve-hour time' => ['2026-08-01 7pm', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'slashes' => ['2026/08/01 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a month name' => ['1 August 2026 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'day, month and year with dots' => ['01.08.2026 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a US date' => ['8/1/2026 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'no leading zeros' => ['2026-8-1 9:05', 'UTC', '2026-08-01T09:05:00.000000Z'],
        'the time before the date' => ['19:00 2026-08-01', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a weekday that is the weekday of the date' => ['Saturday 2026-08-01 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'RFC 2822' => ['Sat, 01 Aug 2026 19:00:00 +0000', '+00:00', '2026-08-01T19:00:00.000000Z'],
        'RFC 2822 on a Sunday' => ['Sun, 02 Aug 2026 19:00:00 +0000', '+00:00', '2026-08-02T19:00:00.000000Z'],
        'an ISO 8601 week date' => ['2026-W31-6T19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a compact ISO 8601 week date' => ['2026W316T19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'a week date in the days before 1 January' => ['2026-W01-1T19:00', 'UTC', '2025-12-29T19:00:00.000000Z'],
        'an ISO 8601 ordinal date' => ['2026-213T19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'the last ordinal day of a leap year' => ['2024-366T19:00', 'UTC', '2024-12-31T19:00:00.000000Z'],
        'a Unix timestamp' => ['@1785610800', '+00:00', '2026-08-01T19:00:00.000000Z'],
        'a Unix timestamp before 1970' => ['@-5', '+00:00', '1969-12-31T23:59:55.000000Z'],
        'a Unix timestamp with a fraction' => ['@1785610800.5', '+00:00', '2026-08-01T19:00:00.500000Z'],
        // A date without a time of day is midnight in the declared timezone
        'a date without a time of day' => ['2026-11-09', 'Europe/London', '2026-11-09T00:00:00.000000Z'],
        'a date without a time of day, in summer' => ['2026-08-01', 'Europe/London', '2026-07-31T23:00:00.000000Z'],
        'a date with a month name and no time of day' => ['9 November 2026', 'UTC', '2026-11-09T00:00:00.000000Z'],
        'a week date without a time of day' => ['2026-W31-6', 'UTC', '2026-08-01T00:00:00.000000Z'],
        'a month and a year, which is the first of the month' => ['August 2026 19:00', 'UTC', '2026-08-01T19:00:00.000000Z'],
        'noon on a date' => ['2026-08-01 noon', 'UTC', '2026-08-01T12:00:00.000000Z'],
        // An offset from a stated date, counted from that date
        'a week after a date' => ['2026-08-01 20:00 +1 week', 'Europe/London', '2026-08-08T19:00:00.000000Z'],
        'a week after a date, across a clock change' => ['2026-10-24 20:00 +1 week', 'Europe/London', '2026-10-31T20:00:00.000000Z'],
        'the first Monday of a month' => ['first monday of August 2026 19:00', 'UTC', '2026-08-03T19:00:00.000000Z'],
        'the declared zone written twice' => ['2026-08-01 19:00 Europe/London Europe/London', 'Europe/London', '2026-08-01T18:00:00.000000Z'],
        // A local time that a clock change skips. PHP moves it forward by
        // the hour that was skipped, as it did before.
        'a time in the hour a clock change skips' => ['2026-03-29 01:30', 'Europe/London', '2026-03-29T01:30:00.000000Z'],
        // A local time that occurs twice. PHP takes the later one.
        'a time in the hour a clock change repeats' => ['2026-10-25 01:30', 'Europe/London', '2026-10-25T01:30:00.000000Z'],
    ]);

    // A date without a time of day was read as midnight before the rule,
    // from the string and not from the clock, and still is: at every place
    // that reads a configured instant.
    it('is midnight of its date when it has no time of day, at every entry point', function (): void {
        $timeline = TimelineDefinition::fromArray(['start' => '2026-11-09', 'timezone' => 'Europe/London', 'round_interval' => 'P7D']);
        $grid = SessionGrid::fromArray(['sessions' => ['2026-11-09', '2026-11-16'], 'timezone' => 'Europe/London', 'slot_interval' => 'PT25M']);
        $blackout = BlackoutRule::fromArray(['windows' => [
            ['from' => '2026-11-09', 'to' => '2026-11-17', 'timezone' => 'Europe/London', 'label' => 'break'],
        ]]);
        [$data, $participants] = scheduledEventDataWithKickoff('2026-11-09');

        expect($timeline->getStart()->format(DATE_ATOM))->toBe('2026-11-09T00:00:00+00:00')
            ->and($grid->getSessionStart(0)->format(DATE_ATOM))->toBe('2026-11-09T00:00:00+00:00')
            ->and($grid->getSessionStart(1)->format(DATE_ATOM))->toBe('2026-11-16T00:00:00+00:00')
            ->and($blackout->toArray()['windows'][0]['from'])->toBe('2026-11-09 00:00:00')
            ->and($blackout->toArray()['windows'][0]['to'])->toBe('2026-11-17 00:00:00')
            ->and(ScheduledEvent::fromArray($data, $participants)->getKickoff()->format(DATE_ATOM))->toBe('2026-11-09T00:00:00+00:00');
    });

    it('is still rejected when it carries a zone that contradicts the timezone field', function (string $value): void {
        $thrown = null;
        try {
            ZonedTime::parse($value, 'Europe/London', 'start');
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::TimezoneMismatch)
            ->and($thrown?->getMessage())->toBe(
                "Invalid scheduler configuration: The start string carries its own timezone; declare the zone only via the 'timezone' field"
            );
    })->with([
        'Z' => ['2026-08-01T19:00:00Z'],
        'an offset' => ['2026-08-01T19:00:00+05:00'],
        'an offset after a space' => ['2026-08-01 19:00:00 +02:00'],
        'another zone' => ['2026-08-01 19:00:00 America/New_York'],
        'a Unix timestamp' => ['@1785610800'],
        'RFC 2822' => ['Sat, 01 Aug 2026 19:00:00 +0000'],
    ]);

    it('is read as a kickoff of scheduled-event data', function (string $value, string $instant): void {
        [$data, $participants] = scheduledEventDataWithKickoff($value);

        expect(ScheduledEvent::fromArray($data, $participants)->getKickoff()->format('Y-m-d\TH:i:s.u\Z'))->toBe($instant);
    })->with([
        'the form toArray() writes' => ['2026-08-01T19:00:00Z', '2026-08-01T19:00:00.000000Z'],
        'no zone, which is UTC' => ['2026-08-01 19:00:00', '2026-08-01T19:00:00.000000Z'],
        'no seconds' => ['2026-08-01T19:00', '2026-08-01T19:00:00.000000Z'],
        'an offset, normalized to UTC' => ['2026-08-01T19:00:00+05:00', '2026-08-01T14:00:00.000000Z'],
        'a zone, normalized to UTC' => ['2026-08-01 19:00:00 Europe/London', '2026-08-01T18:00:00.000000Z'],
        'fractional seconds' => ['2026-08-01T19:00:00.250Z', '2026-08-01T19:00:00.250000Z'],
        'RFC 2822' => ['Sat, 01 Aug 2026 19:00:00 +0000', '2026-08-01T19:00:00.000000Z'],
        'an email date, which names its zone twice' => ['Sat, 01 Aug 2026 19:00:00 +0000 (UTC)', '2026-08-01T19:00:00.000000Z'],
        'a Unix timestamp' => ['@1785610800', '2026-08-01T19:00:00.000000Z'],
        'a date without a time of day, which is midnight UTC' => ['2026-08-01', '2026-08-01T00:00:00.000000Z'],
        'an offset from a stated date' => ['2026-08-01T19:00:00Z +1 week', '2026-08-08T19:00:00.000000Z'],
    ]);

    it('round-trips a kickoff through its array form', function (): void {
        [$data, $participants] = scheduledEventDataWithKickoff('2026-08-01T19:00:00Z');

        expect(ScheduledEvent::fromArray($data, $participants)->toArray()['kickoff'])->toBe('2026-08-01T19:00:00Z');
    });
});

describe('the current time', function (): void {
    // Part 1. The fields are compared in the declared timezone, in zones
    // that have no clock changes: there the wall-clock time parsed is the
    // wall-clock time written, for every string.
    it('gives no field of an accepted datetime', function (string $timezone): void {
        $corpus = [...DatetimeCorpus::absolute(), ...DatetimeCorpus::offsetFromAStatedDate()];
        expect(count(DatetimeCorpus::absolute()))->toBeGreaterThan(4000)
            ->and(count(DatetimeCorpus::offsetFromAStatedDate()))->toBeGreaterThan(100);

        // A date without a time of day is in the corpus, with midnight as
        // the time it must be read to
        expect(array_column($corpus, 'fields', 'value')['2026-08-01'] ?? null)->toBe('2026-08-01 00:00:00');

        $wrong = [];
        foreach ($corpus as ['value' => $value, 'layout' => $layout, 'fields' => $fields]) {
            $parsed = ZonedTime::parse($value, $timezone, 'start');
            if ($parsed->format('Y-m-d H:i:s') !== $fields || $parsed->format('u') !== '000000') {
                $wrong[] = "{$layout}: " . var_export($value, true) . " parsed to {$parsed->format('Y-m-d H:i:s.u')}, not {$fields}";
            }
        }

        expect($wrong)->toBe([]);
    })->with(['UTC', 'Asia/Kolkata', '-03:30']);

    // The same strings give the instant PHP alone gives for them: the check
    // in front of the parser changes which strings are read, never what a
    // string means.
    it('leaves the instant of an accepted datetime to PHP', function (): void {
        $timezone = new DateTimeZone('Europe/London');

        $different = [];
        foreach ([...DatetimeCorpus::absolute(), ...DatetimeCorpus::offsetFromAStatedDate()] as ['value' => $value]) {
            $library = ZonedTime::parse($value, 'Europe/London', 'start')->format('Y-m-d\TH:i:s.uP e');
            if ($library !== (new DateTimeImmutable($value, $timezone))->format('Y-m-d\TH:i:s.uP e')) {
                $different[] = $value;
            }
        }

        expect($different)->toBe([]);
    });

    // Part 2
    it('is never read for a relative or partial datetime: each one is rejected', function (): void {
        $corpus = DatetimeCorpus::clockDependent();
        expect(count($corpus))->toBeGreaterThan(130);

        // Not one of them is even handed to PHP for an error: each is a
        // string PHP accepts, and would answer from the clock
        foreach ($corpus as $value) {
            expect(DateTimeString::isMalformed($value))->toBeFalse();
        }

        $accepted = [];
        foreach ([...$corpus, ...DatetimeCorpus::notTheInstantWritten()] as $value) {
            try {
                $accepted[] = var_export($value, true) . ' => ' . ZonedTime::parse($value, 'UTC', 'start')->format(DATE_ATOM);
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::UnparseableTime);
            }
        }

        expect($accepted)->toBe([]);
    });

    // Part 3. Kiritimati is fourteen hours ahead of UTC and Pago Pago eleven
    // behind: the two are never on the same calendar day, and at every moment
    // at least one of them is on another day than this process. A string
    // resolved against "today" or "now" could not give the same verdict in
    // all three.
    it('gives the same verdict under another default timezone', function (): void {
        $here = DatetimeCorpus::verdicts('Europe/London');

        $ahead = datetimeVerdictsInChildProcess('Pacific/Kiritimati', 'Europe/London');
        $behind = datetimeVerdictsInChildProcess('Pacific/Pago_Pago', 'Europe/London');

        expect($ahead['timezone'])->toBe('Pacific/Kiritimati')
            ->and($behind['timezone'])->toBe('Pacific/Pago_Pago')
            ->and($ahead['today'])->not->toBe($behind['today'])
            ->and($ahead['verdicts'])->toBe($here)
            ->and($behind['verdicts'])->toBe($here);

        // And the verdicts are the two the corpora are named for
        $rejected = array_filter($here, fn(string $verdict): bool => $verdict === 'rejected: unparseable_time');
        $toReject = array_unique([...DatetimeCorpus::clockDependent(), ...DatetimeCorpus::notTheInstantWritten()]);
        $toAccept = array_unique(array_column([...DatetimeCorpus::absolute(), ...DatetimeCorpus::offsetFromAStatedDate()], 'value'));
        expect(count($rejected))->toBe(count($toReject))
            ->and(count($here) - count($rejected))->toBe(count($toAccept));
    });
});
