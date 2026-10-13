<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * The declarative slot model a stage's rounds map onto.
 *
 * Round-aligned assignment ("everyone plays round N at time T") is the
 * one-slot-per-round case; staggered kickoffs are the same model with
 * more slots. One definition per stage — a group stage playing weekly
 * slots and a finals weekend are two definitions, not one.
 *
 * An interval is added to the start in the start's timezone, the way
 * `DateTimeImmutable::add()` adds it, and what that means across a
 * daylight-saving change depends on how the interval is written. Its date
 * part (years, months, weeks, days: `P7D`, `P1D`, `P1M`) is calendar
 * arithmetic and keeps the local time of day: a weekly 19:00 kickoff
 * written as `P7D` stays 19:00. The exception is a local time the clocks
 * skip (01:30 on the night they go forward): it is moved on by the skipped
 * hour and, because the intervals are added one at a time, every later
 * round keeps the moved time. An interval's time part (hours, minutes, seconds:
 * `PT168H`, `PT24H`) is elapsed time: a weekly kickoff written as `PT168H`
 * is 168 hours later, which is 20:00 local after the clocks go forward and
 * 18:00 after they go back. A start in a fixed-offset timezone (`+01:00`)
 * has no such change and the two forms agree. Assigned kickoffs are
 * emitted in UTC (timezone-explicit in, UTC-normalized out).
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
     * Nothing bounds the number of rounds: a definition answers for any
     * round number from 1 up. A slot interval given with one slot per round
     * is checked, kept and written by toArray(), and no slot time uses it. Nothing checks that a round's last slot is
     * before the next round's first.
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
     *                                       does not move the start forward, or a resource
     *                                       that is not a non-empty string or is named twice
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

        if ($start->add($roundInterval) <= $start) {
            throw new InvalidConfigurationException(
                'The round interval must move time forward',
                ['round_interval' => self::formatInterval($roundInterval)],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }

        if ($slotInterval !== null && $start->add($slotInterval) <= $start) {
            throw new InvalidConfigurationException(
                'The slot interval must move time forward',
                ['slot_interval' => self::formatInterval($slotInterval)],
                reason: InvalidConfigurationReason::NonAdvancingTime
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
     * 8601 duration strings, read by parseInterval().
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value is missing or malformed, or the start
     *                                       does not state an instant by itself
     */
    public static function fromArray(array $config): self
    {
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
     * @return array{start: string, timezone: string, round_interval: string, slots_per_round: int, slot_interval?: string, resources?: array<string>}
     *
     * @throws InvalidConfigurationException When an interval has no ISO 8601 duration that reads
     *                                       back as the same interval (reason `NotSerializable`);
     *                                       writeInterval() lists which
     */
    public function toArray(): array
    {
        $data = [
            'start' => $this->start->format($this->start->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'),
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

        return $data;
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
     * The intervals are added one at a time in the start's timezone, the
     * round intervals first and then the slot intervals, and the result is
     * normalized to UTC. An interval's date part keeps the local time of
     * day across a daylight-saving change (`P7D`: a weekly 19:00 stays
     * 19:00) and its time part is elapsed time (`PT168H`: the same kickoff
     * moves by the hour the clocks moved); see the class description.
     *
     * @param int $round 1-based round number
     * @param int $slot 0-based slot index within the round
     *
     * @throws InvalidConfigurationException When the round or slot is out of range
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

        $time = $this->start;
        for ($i = 1; $i < $round; ++$i) {
            $time = $time->add($this->roundInterval);
        }
        for ($i = 0; $i < $slot; ++$i) {
            /** @var DateInterval $slotInterval */
            $slotInterval = $this->slotInterval;
            $time = $time->add($slotInterval);
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
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
     * date string itself (`next weekday`). One thing is not shown: PHP adds
     * the hours, minutes and seconds of an interval from
     * `createFromDateString()` or from `diff()` as local time, and `PT2H`
     * looks the same for it. To write an interval as plain data use
     * writeInterval(), which refuses all of these.
     */
    public static function formatInterval(DateInterval $interval): string
    {
        $relative = self::relativeDateString($interval);
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
     * interval that adds what this one adds. Any other interval is refused,
     * and never written as a duration that means something else.
     *
     * Refused, because no duration PHP reads can state it:
     *
     * - an interval made by `DateInterval::createFromDateString()` from
     *   anything but amounts of time (`next weekday`, `3 weekdays`, `last day
     *   of next month`, `1 day ago`). Amounts are a number and a unit, with
     *   or without a `+`: `1 week`, `+2 days`, `1 month 2 days`;
     * - an inverted interval;
     * - an interval with a fraction of a second;
     * - an interval from `createFromDateString()` or from
     *   `DateTimeImmutable::diff()` that has hours, minutes or seconds. PHP
     *   adds those as local time, and the hours of a duration read back are
     *   elapsed time: across a daylight-saving change the two are an hour
     *   apart. Such an interval of whole days, months and years is written,
     *   because days are calendar days in both.
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
        $relative = self::relativeDateString($interval);
        $hasTime = $interval->h !== 0 || $interval->i !== 0 || $interval->s !== 0;

        $why = match (true) {
            $relative !== null && !self::isAmountsOnly($relative) => 'it was made from a relative date string, which a duration cannot state',
            $interval->invert === 1 => 'it is negative, and a duration PHP reads has no sign',
            round(abs($interval->f) * 1_000_000) >= 1.0 => 'it has a fraction of a second, which a duration PHP reads cannot hold',
            $hasTime && ($relative !== null || $interval->days !== false) => 'PHP adds its hours, minutes and seconds as local time, and those of a duration read back as elapsed time',
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
     * The relative date string an interval was made from by
     * `DateInterval::createFromDateString()`; null for any other interval.
     */
    private static function relativeDateString(DateInterval $interval): ?string
    {
        // Read through an array cast: the two properties are listed for
        // such an interval and cannot be read by name
        $properties = (array) $interval;
        $dateString = $properties['date_string'] ?? null;

        return ($properties['from_string'] ?? false) === true && is_string($dateString) ? $dateString : null;
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
