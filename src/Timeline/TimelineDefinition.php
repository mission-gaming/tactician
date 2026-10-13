<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * The declarative slot model a stage's rounds map onto.
 *
 * Round-aligned assignment ("everyone plays round N at time T") is the
 * one-slot-per-round case; staggered kickoffs are the same model with
 * more slots. One definition per stage — a group stage playing weekly
 * slots and a finals weekend are two definitions, not one.
 *
 * Every slot time is computed from the start, never from the slot before
 * it: round N is the start plus N−1 round intervals, taken as one step,
 * and a slot of that round is the round's first slot plus one slot
 * interval for each slot before it, taken as one step. The steps are added
 * in the start's timezone, and what that means depends on how the interval
 * is written.
 *
 * Its date part (years, months, weeks, days: `P7D`, `P1D`, `P1M`) is
 * calendar arithmetic and keeps the local time of day: a weekly 19:00
 * kickoff written as `P7D` stays 19:00 across a daylight-saving change.
 * Years and months keep the start's day of the month, and a month too short
 * for that day gives its last day: `P1M` from 31 January is 28 (or 29)
 * February, 31 March, 30 April; from 30 January it is the end of February
 * and the 30th of every other month. A local time the clocks skip (01:30 on
 * the night they go forward) is moved on by the skipped hour, for the round
 * that lands on it and for no other. A local time that occurs twice (01:30
 * on the night they go back) is the first of the two; where the interval
 * also has a time part, it is the one PHP takes, which is the first where
 * the timezone database marks the start as daylight-saving time and the
 * second where it does not.
 *
 * An interval's time part (hours, minutes, seconds: `PT168H`, `PT24H`) is
 * elapsed time: a weekly kickoff written as `PT168H` is 168 hours later,
 * which is 20:00 local after the clocks go forward and 18:00 after they go
 * back. A start in a fixed-offset timezone (`+01:00`) has no such change
 * and the two forms agree. Assigned kickoffs are emitted in UTC
 * (timezone-explicit in, UTC-normalized out).
 *
 * All of this is said of an interval built from an ISO 8601 duration
 * (`new DateInterval('P7D')`, or `round_interval` in plain data). PHP adds
 * the hours, minutes and seconds of an interval that `diff()` returned as
 * local time, and so does the timeline. An interval made by
 * `DateInterval::createFromDateString()` (`next weekday`) has no multiple:
 * it alone is added one step at a time, each step from the one before.
 *
 * The slots of one round are at rising times, and all of them before the
 * first slot of the next round. The constructor checks that for the first
 * two rounds, and {@see TimelineAssigner} for every round it assigns (the
 * length of a month, and a clock change, can make a later round shorter
 * than the first, or put two of its slots at one time).
 *
 * The definition owns the mechanism only: which slots exist. Parsing
 * competition config into a slot pattern, persistence, notifications,
 * and rescheduling policy stay application-side.
 *
 * @experimental
 */
final readonly class TimelineDefinition
{
    /** @var array<string> Re-indexed 0..N-1 so index-based access always agrees with the capacity */
    private array $resources;

    /**
     * The definition has no number of rounds: it answers for any round
     * number from 1 up whose time PHP can compute (getSlotTime() says where
     * that ends). A slot interval given with one slot per round is checked,
     * kept and written by toArray(), and no slot time uses it. The first
     * round's slots must be at rising times and its last slot before the
     * second round's first slot; the assigner checks the same of every
     * round it assigns.
     *
     * @param DateTimeImmutable $start The first round's first slot, in the stage's timezone;
     *                                 its timezone is the one the intervals are added in
     * @param DateInterval $roundInterval Time between one round's first slot and the next's
     *                                    (see the class description for date parts and time
     *                                    parts across a daylight-saving change)
     * @param int $slotsPerRound How many kickoff slots each round has (1 = round-aligned)
     * @param DateInterval|null $slotInterval Time between a round's slots; required when slotsPerRound > 1
     * @param array<string> $resources Named resources hosting concurrent events per slot
     *                                 (venue, pitch, court, board...); empty means one
     *                                 anonymous resource, i.e. one event per slot. Their
     *                                 order is the order a slot's events are given them in
     *
     * @throws InvalidConfigurationException When there is less than one slot per round, several
     *                                       slots without a slot interval, an interval that
     *                                       does not move the start forward, a first round
     *                                       with two slots at one time or whose last slot is
     *                                       at or after the second round's first, an interval
     *                                       or a first round too far from the start to be
     *                                       computed, or a resource that is not a non-empty
     *                                       string or is named twice
     */
    public function __construct(
        private DateTimeImmutable $start,
        private DateInterval $roundInterval,
        private int $slotsPerRound = 1,
        private ?DateInterval $slotInterval = null,
        array $resources = []
    ) {
        if ($slotsPerRound < 1) {
            throw new InvalidConfigurationException(
                'A round needs at least 1 slot',
                ['slots_per_round' => $slotsPerRound],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($slotsPerRound > 1 && $slotInterval === null) {
            throw new InvalidConfigurationException(
                'Staggered slots need a slot interval',
                ['slots_per_round' => $slotsPerRound],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        if (IntervalArithmetic::advance($start, $roundInterval, 1) <= $start) {
            throw new InvalidConfigurationException(
                'The round interval must move time forward',
                ['round_interval' => self::formatInterval($roundInterval)],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }

        if ($slotInterval !== null && IntervalArithmetic::advance($start, $slotInterval, 1) <= $start) {
            throw new InvalidConfigurationException(
                'The slot interval must move time forward',
                ['slot_interval' => self::formatInterval($slotInterval)],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }

        // An interval PHP adds as local time (one from diff()) can put two
        // slots of a round at one instant where the clocks skip an hour
        $lastSlot = $this->slotTime(1, 0);
        for ($slot = 1; $slot < $slotsPerRound; ++$slot) {
            $time = $this->slotTime(1, $slot);
            if ($time <= $lastSlot) {
                throw new InvalidConfigurationException(
                    'The slots of a round must be at rising times: slot ' . $slot . ' of round 1 is at '
                        . $time->format('Y-m-d H:i:s') . ' and the slot before it at ' . $lastSlot->format('Y-m-d H:i:s'),
                    [
                        'slot_interval' => $slotInterval instanceof DateInterval ? self::formatInterval($slotInterval) : null,
                        'round' => 1,
                        'slot' => $slot,
                        'slot_time' => $time->format('Y-m-d H:i:s'),
                        'previous_slot_time' => $lastSlot->format('Y-m-d H:i:s'),
                    ],
                    reason: InvalidConfigurationReason::OverlappingTime
                );
            }
            $lastSlot = $time;
        }

        if ($slotInterval !== null && $slotsPerRound > 1 && $lastSlot >= $this->slotTime(2, 0)) {
            throw new InvalidConfigurationException(
                'The slots of a round must all be before the next round starts: '
                    . "{$slotsPerRound} slots " . self::formatInterval($slotInterval)
                    . ' apart do not fit in a round interval of ' . self::formatInterval($roundInterval),
                [
                    'round_interval' => self::formatInterval($roundInterval),
                    'slot_interval' => self::formatInterval($slotInterval),
                    'slots_per_round' => $slotsPerRound,
                    'last_slot_of_round_1' => $lastSlot->format('Y-m-d H:i:s'),
                    'first_slot_of_round_2' => $this->slotTime(2, 0)->format('Y-m-d H:i:s'),
                ],
                reason: InvalidConfigurationReason::OverlappingTime
            );
        }

        foreach ($resources as $resource) {
            if (!is_string($resource) || $resource === '') {
                throw new InvalidConfigurationException(
                    'Resources must be non-empty strings',
                    ['resources' => $resources],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
        }

        if (count($resources) !== count(array_unique($resources))) {
            throw new InvalidConfigurationException(
                'Resources must be unique',
                ['resources' => $resources],
                reason: InvalidConfigurationReason::DuplicateName
            );
        }

        // Index-based access (getResourceAt) must agree with the capacity,
        // so keys are normalized to 0..N-1 whatever the caller passed
        $this->resources = array_values($resources);
    }

    /**
     * Build from plain configuration data:
     * ['start' => '2026-08-01 19:00', 'timezone' => 'Europe/London',
     *  'round_interval' => 'P7D', 'slots_per_round' => 3, 'slot_interval' => 'PT1H'].
     *
     * The timezone is required — policy about times is only expressible
     * against an explicit zone. The start states its date in full (year,
     * month and day; the time of day is optional and defaults to midnight):
     * `tomorrow`, `+1 week`, an empty string or a time of day without a
     * date is rejected, because PHP would resolve it against the clock, and
     * so is a date or a time that does not exist (`2026-02-30`, `24:00`). A
     * timezone or offset written in the start itself is rejected unless it
     * names the declared timezone as it was declared: with `UTC` declared,
     * `Z` and `+00:00` are rejected. "Timeline Assignment" in the usage
     * guide describes the rule.
     *
     * `round_interval` is required; `slots_per_round` (default 1),
     * `slot_interval` and `resources` are optional. The intervals are ISO
     * 8601 duration strings, read by parseInterval(). A key that is none of
     * these is refused, so that a misspelt `slots_per_round` is not
     * silently read as 1.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), a
     *                                       value is missing or malformed, or the start does
     *                                       not state an instant by itself
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions(
            $config,
            ['start', 'timezone', 'round_interval', 'slots_per_round', 'slot_interval', 'resources'],
            'a timeline definition'
        );

        $start = ZonedTime::parse($config['start'] ?? null, $config['timezone'] ?? null, 'start');

        $slotsPerRound = $config['slots_per_round'] ?? 1;
        if (!is_int($slotsPerRound)) {
            throw new InvalidConfigurationException(
                'slots_per_round must be an integer',
                ['slots_per_round' => $slotsPerRound],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $resources = $config['resources'] ?? [];
        if (!is_array($resources)) {
            throw new InvalidConfigurationException(
                'resources must be a list of names',
                ['resources' => $resources],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        /** @var array<string> $resources Element types are validated by the constructor */
        return new self(
            $start,
            self::parseInterval($config['round_interval'] ?? null, 'round_interval'),
            $slotsPerRound,
            isset($config['slot_interval'])
                ? self::parseInterval($config['slot_interval'], 'slot_interval')
                : null,
            $resources
        );
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts.
     *
     * The start is written as local time in its own timezone
     * (`2026-08-01 19:00:00`), with that timezone's name beside it; a
     * fraction of a second is written after the seconds when the start has
     * one (`2026-08-01 19:00:00.250000`). `slot_interval` and `resources`
     * are left out when there is none. The intervals are written by
     * writeInterval(), which refuses one that would not read back as the
     * same interval: a definition that is written reads back to the same
     * slot times.
     *
     * The first key is `format_version`, the version of this form (1).
     *
     * @return array{format_version: int, start: string, timezone: string, round_interval: string, slots_per_round: int, slot_interval?: string, resources?: array<string>}
     *
     * @throws InvalidConfigurationException When an interval has no ISO 8601 duration that reads
     *                                       back as the same interval (reason `NotSerializable`);
     *                                       writeInterval() lists which
     */
    public function toArray(): array
    {
        $data = [
            'start' => ZonedTime::write($this->start),
            'timezone' => $this->start->getTimezone()->getName(),
            'round_interval' => self::writeInterval($this->roundInterval, 'round_interval'),
            'slots_per_round' => $this->slotsPerRound,
        ];

        if ($this->slotInterval !== null) {
            $data['slot_interval'] = self::writeInterval($this->slotInterval, 'slot_interval');
        }

        if ($this->resources !== []) {
            $data['resources'] = $this->resources;
        }

        return [PlainData::VERSION_KEY => PlainData::VERSION, ...$data];
    }

    /**
     * The first round's first slot, in the timezone it was given in (not
     * normalized to UTC; getSlotTime() is).
     */
    public function getStart(): DateTimeImmutable
    {
        return $this->start;
    }

    /**
     * The interval between one round's first slot and the next's, as given.
     */
    public function getRoundInterval(): DateInterval
    {
        return $this->roundInterval;
    }

    /**
     * How many kickoff slots each round has; at least 1.
     */
    public function getSlotsPerRound(): int
    {
        return $this->slotsPerRound;
    }

    /**
     * The interval between consecutive slots of a round, as given; null
     * when none was given, which only a definition with one slot per round
     * allows.
     */
    public function getSlotInterval(): ?DateInterval
    {
        return $this->slotInterval;
    }

    /**
     * The named resources hosting concurrent events per slot, in the order
     * given and keyed 0, 1, 2 and so on; empty means one anonymous
     * resource.
     *
     * @return array<string>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * How many events one slot can host: one per resource, or one when no
     * resources are declared.
     *
     * @return positive-int
     */
    public function getCapacityPerSlot(): int
    {
        return max(1, count($this->resources));
    }

    /**
     * The resource hosting a slot's nth concurrent event, or null when no
     * resources are declared.
     *
     * @param int $index 0-based index within the slot
     *
     * @throws InvalidConfigurationException When the index exceeds the slot capacity
     */
    public function getResourceAt(int $index): ?string
    {
        if ($index < 0 || $index >= $this->getCapacityPerSlot()) {
            throw new InvalidConfigurationException(
                'Resource index is out of range for this timeline',
                ['index' => $index, 'capacity_per_slot' => $this->getCapacityPerSlot()],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }

        return $this->resources[$index] ?? null;
    }

    /**
     * The kickoff time of one slot, in UTC.
     *
     * Round numbers are absolute offsets: round N lands at
     * start + (N−1) round intervals whether or not earlier rounds exist,
     * so cross-leg-continuous numbering and partial schedules map stably.
     * The N−1 round intervals are added to the start as one step, in the
     * start's timezone, which gives the round's first slot; the slot
     * intervals are then added to that as one step, and the result is
     * normalized to UTC. No slot is computed from the slot before it, so a
     * short month or a skipped local time moves the one slot it falls on
     * and no later one. An interval's date part keeps the local time of
     * day across a daylight-saving change (`P7D`: a weekly 19:00 stays
     * 19:00) and its time part is elapsed time (`PT168H`: the same kickoff
     * moves by the hour the clocks moved); see the class description.
     *
     * @param int $round 1-based round number
     * @param int $slot 0-based slot index within the round
     *
     * @throws InvalidConfigurationException When the round or slot is out of range, or the
     *                                       round is so far from the start that its time
     *                                       cannot be computed (reason `ValueOutOfRange`:
     *                                       some 270 billion years, the range of PHP's
     *                                       own timestamps)
     */
    public function getSlotTime(int $round, int $slot = 0): DateTimeImmutable
    {
        if ($round < 1) {
            throw new InvalidConfigurationException(
                'Round numbers are 1-based',
                ['round' => $round],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }

        if ($slot < 0 || $slot >= $this->slotsPerRound) {
            throw new InvalidConfigurationException(
                'Slot index is out of range for this timeline',
                ['slot' => $slot, 'slots_per_round' => $this->slotsPerRound],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }

        return $this->slotTime($round, $slot)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A slot's time in the start's timezone: the round's first slot from
     * the start, then the slot from the round's first slot.
     *
     * @param int $round 1-based round number, already checked
     * @param int $slot 0-based slot index, already checked
     *
     * @throws InvalidConfigurationException When the round is too far from the start to compute
     */
    private function slotTime(int $round, int $slot): DateTimeImmutable
    {
        $time = IntervalArithmetic::advance($this->start, $this->roundInterval, $round - 1);

        if ($slot === 0 || $this->slotInterval === null) {
            return $time;
        }

        return IntervalArithmetic::advance($time, $this->slotInterval, $slot);
    }

    /**
     * Parse a configuration value as an ISO 8601 duration, as PHP's
     * `DateInterval` constructor reads one: `P7D`, `PT1H`, `P1DT12H`.
     *
     * Only the form is checked. A duration of zero (`PT0S`) parses; the
     * constructors that take the interval reject one that does not move
     * time forward.
     *
     * @param string $key The configuration key, for diagnostics
     * @throws InvalidConfigurationException When the value is not a string, or not a duration
     *                                       PHP can parse
     */
    public static function parseInterval(mixed $value, string $key): DateInterval
    {
        if (!is_string($value)) {
            throw new InvalidConfigurationException(
                "{$key} must be an ISO 8601 duration string (e.g. 'P7D', 'PT1H')",
                [$key => $value],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        try {
            return new DateInterval($value);
        } catch (Exception $exception) {
            throw new InvalidConfigurationException(
                "{$key} is not a valid ISO 8601 duration",
                [$key => $value],
                '',
                0,
                $exception,
                reason: InvalidConfigurationReason::UnparseableTime
            );
        }
    }

    /**
     * Describe an interval as an ISO 8601 duration: the non-zero parts of
     * years, months, days, hours, minutes and seconds, in that order, and
     * `PT0S` when all are zero.
     *
     * The parts are written as the interval holds them and not carried over
     * (`PT90M` stays `PT90M`); weeks are written as days (`P1W` is `P7D`),
     * which is how PHP holds them. For an interval built from an ISO 8601
     * duration that is the duration, and parseInterval() reads it back.
     *
     * The method describes every interval, because errors quote it, and an
     * interval can hold what a duration PHP reads cannot. Those parts are
     * shown and not left out, in a form parseInterval() refuses: the sign of
     * an inverted interval (`-P7D`), a fraction of a second (`PT1.5S`), and,
     * for an interval made by `DateInterval::createFromDateString()` from
     * anything but amounts of time (`1 week`, `25 minutes`), the relative
     * date string itself (`next weekday`). A part that is negative is
     * written with its sign (`P8DT-1H`), which parseInterval() refuses too.
     * Two things are not shown: PHP adds the hours, minutes and seconds of
     * an interval from `createFromDateString()` or from `diff()` as local
     * time, and `PT2H` looks the same for it; and an interval made from
     * amounts of time in a string is added one step at a time, where the
     * duration shown is not. To write an interval as plain data use
     * writeInterval(), which refuses all of these.
     */
    public static function formatInterval(DateInterval $interval): string
    {
        $relative = IntervalArithmetic::relativeDateString($interval);
        if ($relative !== null && !self::isAmountsOnly($relative)) {
            return $relative;
        }

        $date = '';
        if ($interval->y !== 0) {
            $date .= $interval->y . 'Y';
        }
        if ($interval->m !== 0) {
            $date .= $interval->m . 'M';
        }
        if ($interval->d !== 0) {
            $date .= $interval->d . 'D';
        }

        $time = '';
        if ($interval->h !== 0) {
            $time .= $interval->h . 'H';
        }
        if ($interval->i !== 0) {
            $time .= $interval->i . 'M';
        }
        // The fraction is held in microseconds at most; `==` because PHP
        // holds it as a float and 0.0 is also -0.0
        $microseconds = min(999_999, (int) round(abs($interval->f) * 1_000_000));
        if ($microseconds !== 0) {
            $time .= $interval->s . '.' . rtrim(sprintf('%06d', $microseconds), '0') . 'S';
        } elseif ($interval->s !== 0) {
            $time .= $interval->s . 'S';
        }

        $sign = $interval->invert === 1 ? '-' : '';

        if ($date === '' && $time === '') {
            return 'PT0S';
        }

        return $sign . 'P' . $date . ($time !== '' ? 'T' . $time : '');
    }

    /**
     * Write an interval for plain data: the ISO 8601 duration
     * formatInterval() gives, where parseInterval() reads that back as an
     * interval that gives a timeline or a grid the slot times this one
     * gives. Any other interval is refused, and never written as a duration
     * that means something else.
     *
     * Refused:
     *
     * - an interval made by `DateInterval::createFromDateString()`, whatever
     *   the string. One that is more than amounts of time (`next weekday`,
     *   `3 weekdays`, `last day of next month`) has no duration at all. One
     *   that is amounts of time alone (`1 week`, `1 month`, `25 minutes`)
     *   has one, and it is another interval: PHP does not show the parts of
     *   an interval made from a string, so the timeline and the grid add it
     *   one step at a time, each step from the one before, and its hours,
     *   minutes and seconds as local time, where a duration read back is
     *   added as all its steps at once from the start and its hours as
     *   elapsed time. `1 month` from 31 January is 3 March and then 3
     *   April; `P1M` is 28 February and then 31 March;
     * - an inverted interval, and one with a negative part (hours set to
     *   -1): a duration PHP reads has no sign;
     * - an interval with a fraction of a second;
     * - an interval from `DateTimeImmutable::diff()` that has hours,
     *   minutes or seconds. PHP adds those as local time, and the hours of a
     *   duration read back are elapsed time: across a daylight-saving change
     *   the two are an hour apart. An interval from `diff()` of whole days,
     *   months and years is written, because those are calendar steps in
     *   both.
     *
     * Refusing to write an interval does not refuse to use it: a timeline or
     * a grid built with one gives its slot times as before. To have plain
     * data, build the interval from an ISO 8601 duration
     * (`new DateInterval('PT25M')`).
     *
     * @param string $key The configuration key the interval is written under, for the error
     *
     * @throws InvalidConfigurationException When the interval has no duration that reads back as
     *                                       the same interval (reason `NotSerializable`)
     */
    public static function writeInterval(DateInterval $interval, string $key): string
    {
        $relative = IntervalArithmetic::relativeDateString($interval);
        $hasTime = $interval->h !== 0 || $interval->i !== 0 || $interval->s !== 0;
        $hasNegativePart = min($interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s) < 0;

        $why = match (true) {
            $relative !== null && !self::isAmountsOnly($relative) => 'it was made from a relative date string, which a duration cannot state',
            $relative !== null => 'it was made from a relative date string: such an interval is added one step at a time and as local time, and a duration read back is not',
            $interval->invert === 1 => 'it is negative, and a duration PHP reads has no sign',
            $hasNegativePart => 'it has a negative part, which a duration PHP reads cannot hold',
            round(abs($interval->f) * 1_000_000) >= 1.0 => 'it has a fraction of a second, which a duration PHP reads cannot hold',
            $hasTime && $interval->days !== false => 'PHP adds its hours, minutes and seconds as local time, and those of a duration read back as elapsed time',
            default => null,
        };

        if ($why !== null) {
            throw new InvalidConfigurationException(
                "{$key} cannot be written as an ISO 8601 duration: {$why}",
                [$key => self::formatInterval($interval)],
                reason: InvalidConfigurationReason::NotSerializable
            );
        }

        return self::formatInterval($interval);
    }

    /**
     * Whether a relative date string is nothing but amounts of time: a
     * number and a unit, any number of times. Such a string has no part
     * that the years, months, days, hours, minutes and seconds of the
     * interval do not show. A weekday, `next`, `last`, `ago`, a sign and a
     * unit not listed here are not amounts.
     */
    private static function isAmountsOnly(string $dateString): bool
    {
        return preg_match(
            '/^(?:\s*\+?\d+\s*(?:years?|months?|weeks?|days?|hours?|minutes?|mins?|seconds?|secs?)\b)+\s*$/i',
            $dateString
        ) === 1;
    }
}
