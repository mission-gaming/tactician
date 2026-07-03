<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * The declarative slot model a stage's rounds map onto.
 *
 * Round-aligned assignment ("everyone plays round N at time T") is the
 * one-slot-per-round case; staggered kickoffs are the same model with
 * more slots. One definition per stage — a group stage playing weekly
 * slots and a finals weekend are two definitions, not one.
 *
 * Interval arithmetic is wall-clock in the definition's timezone, so a
 * weekly 19:00 kickoff stays 19:00 across DST transitions; assigned
 * kickoffs are emitted in UTC (timezone-explicit in, UTC-normalized out).
 *
 * The definition owns the mechanism only: which slots exist. Parsing
 * competition config into a slot pattern, persistence, notifications,
 * and rescheduling policy stay application-side.
 */
final readonly class TimelineDefinition
{
    /**
     * @param DateTimeImmutable $start The first round's first slot, in the stage's timezone
     * @param DateInterval $roundInterval Time between one round's first slot and the next's
     * @param int $slotsPerRound How many kickoff slots each round has (1 = round-aligned)
     * @param DateInterval|null $slotInterval Time between a round's slots; required when slotsPerRound > 1
     *
     * @throws InvalidConfigurationException When the slot configuration is invalid
     */
    public function __construct(
        private DateTimeImmutable $start,
        private DateInterval $roundInterval,
        private int $slotsPerRound = 1,
        private ?DateInterval $slotInterval = null
    ) {
        if ($slotsPerRound < 1) {
            throw new InvalidConfigurationException(
                'A round needs at least 1 slot',
                ['slots_per_round' => $slotsPerRound]
            );
        }

        if ($slotsPerRound > 1 && $slotInterval === null) {
            throw new InvalidConfigurationException(
                'Staggered slots need a slot interval',
                ['slots_per_round' => $slotsPerRound]
            );
        }

        if ($start->add($roundInterval) <= $start) {
            throw new InvalidConfigurationException(
                'The round interval must move time forward',
                ['round_interval' => self::formatInterval($roundInterval)]
            );
        }

        if ($slotInterval !== null && $start->add($slotInterval) <= $start) {
            throw new InvalidConfigurationException(
                'The slot interval must move time forward',
                ['slot_interval' => self::formatInterval($slotInterval)]
            );
        }
    }

    /**
     * Build from plain configuration data:
     * ['start' => '2026-08-01 19:00', 'timezone' => 'Europe/London',
     *  'round_interval' => 'P7D', 'slots_per_round' => 3, 'slot_interval' => 'PT1H'].
     *
     * The timezone is required — policy about times is only expressible
     * against an explicit zone.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value is missing or malformed
     */
    public static function fromArray(array $config): self
    {
        $start = ZonedTime::parse($config['start'] ?? null, $config['timezone'] ?? null, 'start');

        $slotsPerRound = $config['slots_per_round'] ?? 1;
        if (!is_int($slotsPerRound)) {
            throw new InvalidConfigurationException(
                'slots_per_round must be an integer',
                ['slots_per_round' => $slotsPerRound]
            );
        }

        return new self(
            $start,
            self::parseInterval($config['round_interval'] ?? null, 'round_interval'),
            $slotsPerRound,
            isset($config['slot_interval'])
                ? self::parseInterval($config['slot_interval'], 'slot_interval')
                : null
        );
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts.
     *
     * @return array{start: string, timezone: string, round_interval: string, slots_per_round: int, slot_interval?: string}
     */
    public function toArray(): array
    {
        $data = [
            'start' => $this->start->format('Y-m-d H:i:s'),
            'timezone' => $this->start->getTimezone()->getName(),
            'round_interval' => self::formatInterval($this->roundInterval),
            'slots_per_round' => $this->slotsPerRound,
        ];

        if ($this->slotInterval !== null) {
            $data['slot_interval'] = self::formatInterval($this->slotInterval);
        }

        return $data;
    }

    public function getStart(): DateTimeImmutable
    {
        return $this->start;
    }

    public function getRoundInterval(): DateInterval
    {
        return $this->roundInterval;
    }

    public function getSlotsPerRound(): int
    {
        return $this->slotsPerRound;
    }

    public function getSlotInterval(): ?DateInterval
    {
        return $this->slotInterval;
    }

    /**
     * The kickoff time of one slot, in UTC.
     *
     * Round numbers are absolute offsets: round N lands at
     * start + (N−1) round intervals whether or not earlier rounds exist,
     * so cross-leg-continuous numbering and partial schedules map stably.
     * Arithmetic is wall-clock in the definition's timezone (a weekly
     * 19:00 stays 19:00 across DST); the result is normalized to UTC.
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
                ['round' => $round]
            );
        }

        if ($slot < 0 || $slot >= $this->slotsPerRound) {
            throw new InvalidConfigurationException(
                'Slot index is out of range for this timeline',
                ['slot' => $slot, 'slots_per_round' => $this->slotsPerRound]
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
     * Parse a configuration value as an ISO 8601 duration.
     *
     * @param string $key The configuration key, for diagnostics
     * @throws InvalidConfigurationException When the value is not an ISO 8601 duration
     */
    public static function parseInterval(mixed $value, string $key): DateInterval
    {
        if (!is_string($value)) {
            throw new InvalidConfigurationException(
                "{$key} must be an ISO 8601 duration string (e.g. 'P7D', 'PT1H')",
                [$key => $value]
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
                $exception
            );
        }
    }

    /**
     * Format an interval as a canonical ISO 8601 duration.
     */
    public static function formatInterval(DateInterval $interval): string
    {
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
        if ($interval->s !== 0) {
            $time .= $interval->s . 'S';
        }

        if ($date === '' && $time === '') {
            return 'PT0S';
        }

        return 'P' . $date . ($time !== '' ? 'T' . $time : '');
    }
}
