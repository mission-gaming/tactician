<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use MissionGaming\Tactician\Timeline\ZonedTime;

/**
 * The declarative slot model a repack assigns events onto.
 *
 * Where TimelineDefinition expresses a regular cadence (start + N
 * intervals), a session grid is an explicit ordered list of session start
 * instants — real repack grids are irregular, and the last session
 * routinely runs deeper than the rest, so the slot count is overridable
 * per session. Each (session, slot) pair is one assignable position; a
 * slot hosts up to capacityPerSlot concurrent events.
 *
 * Time convention matches the timeline family: session starts are
 * declared in an explicit timezone, slot arithmetic is wall-clock in that
 * zone, and emitted kickoffs are UTC.
 *
 * The grid owns the mechanism only: which positions exist. Which events
 * are movable, and policy about times, stay application-side. Events that
 * fall outside the grid entirely cannot collide with it and are the
 * caller's to filter — collision is exact (session, slot) identity, by
 * design; there is no fuzzy time-overlap detection.
 */
final readonly class SessionGrid
{
    /** @var array<int, DateTimeImmutable> Re-indexed 0..N-1 */
    private array $sessionStarts;

    /** @var array<int, int> Slot count per session index, overrides applied */
    private array $slotCounts;

    /**
     * @param array<DateTimeImmutable> $sessionStarts Ordered session start instants,
     *                                                all in the same declared timezone
     * @param DateInterval $slotInterval Time between consecutive slots within a session
     * @param int $slotsPerSession Default slot count for every session
     * @param array<int, int> $slotsPerSessionOverrides Session index => slot count, for
     *                                                  sessions deeper or shallower than
     *                                                  the default
     * @param int $capacityPerSlot How many events may share one slot
     *
     * @throws InvalidConfigurationException When the grid configuration is invalid
     */
    public function __construct(
        array $sessionStarts,
        private DateInterval $slotInterval,
        private int $slotsPerSession = 1,
        array $slotsPerSessionOverrides = [],
        private int $capacityPerSlot = 1
    ) {
        if ($sessionStarts === []) {
            throw new InvalidConfigurationException(
                'A session grid needs at least 1 session',
                ['session_starts' => []]
            );
        }

        $starts = [];
        $previous = null;
        $timezone = null;
        foreach (array_values($sessionStarts) as $index => $start) {
            if (!$start instanceof DateTimeImmutable) {
                throw new InvalidConfigurationException(
                    'Every session start must be a DateTimeImmutable',
                    ['index' => $index, 'given' => get_debug_type($start)]
                );
            }

            $timezone ??= $start->getTimezone()->getName();
            if ($start->getTimezone()->getName() !== $timezone) {
                throw new InvalidConfigurationException(
                    'Every session start must share one declared timezone',
                    [
                        'index' => $index,
                        'timezone' => $start->getTimezone()->getName(),
                        'expected' => $timezone,
                    ]
                );
            }

            if ($previous !== null && $start <= $previous) {
                throw new InvalidConfigurationException(
                    'Session starts must be strictly ascending',
                    ['index' => $index, 'start' => $start->format('Y-m-d H:i:s')]
                );
            }

            $starts[$index] = $start;
            $previous = $start;
        }

        if ($slotsPerSession < 1) {
            throw new InvalidConfigurationException(
                'A session needs at least 1 slot',
                ['slots_per_session' => $slotsPerSession]
            );
        }

        if ($starts[0]->add($slotInterval) <= $starts[0]) {
            throw new InvalidConfigurationException(
                'The slot interval must move time forward',
                ['slot_interval' => TimelineDefinition::formatInterval($slotInterval)]
            );
        }

        if ($capacityPerSlot < 1) {
            throw new InvalidConfigurationException(
                'A slot needs capacity for at least 1 event',
                ['capacity_per_slot' => $capacityPerSlot]
            );
        }

        $slotCounts = array_fill(0, count($starts), $slotsPerSession);
        foreach ($slotsPerSessionOverrides as $session => $slots) {
            if (!is_int($session) || !isset($starts[$session])) {
                throw new InvalidConfigurationException(
                    'Slot count overrides must target existing session indexes',
                    ['session' => $session, 'sessions' => count($starts)]
                );
            }

            if (!is_int($slots) || $slots < 1) {
                throw new InvalidConfigurationException(
                    'An overridden session still needs at least 1 slot',
                    ['session' => $session, 'slots' => $slots]
                );
            }

            $slotCounts[$session] = $slots;
        }

        $this->sessionStarts = $starts;
        $this->slotCounts = $slotCounts;
    }

    /**
     * Build from plain configuration data:
     * ['sessions' => ['2026-08-12 20:15', '2026-08-19 20:15'],
     *  'timezone' => 'Europe/London', 'slot_interval' => 'PT25M',
     *  'slots_per_session' => 4, 'slots_per_session_overrides' => [1 => 6],
     *  'capacity_per_slot' => 7].
     *
     * The timezone is required and authoritative for every session start,
     * same convention as the timeline family.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException When a value is missing or malformed
     */
    public static function fromArray(array $config): self
    {
        $sessions = $config['sessions'] ?? null;
        if (!is_array($sessions) || $sessions === []) {
            throw new InvalidConfigurationException(
                'sessions must be a non-empty list of datetime strings',
                ['sessions' => $sessions]
            );
        }

        $starts = [];
        foreach (array_values($sessions) as $index => $session) {
            $starts[] = ZonedTime::parse($session, $config['timezone'] ?? null, "sessions[{$index}]");
        }

        $slotsPerSession = $config['slots_per_session'] ?? 1;
        if (!is_int($slotsPerSession)) {
            throw new InvalidConfigurationException(
                'slots_per_session must be an integer',
                ['slots_per_session' => $slotsPerSession]
            );
        }

        $overrides = $config['slots_per_session_overrides'] ?? [];
        if (!is_array($overrides)) {
            throw new InvalidConfigurationException(
                'slots_per_session_overrides must map session indexes to slot counts',
                ['slots_per_session_overrides' => $overrides]
            );
        }

        $capacityPerSlot = $config['capacity_per_slot'] ?? 1;
        if (!is_int($capacityPerSlot)) {
            throw new InvalidConfigurationException(
                'capacity_per_slot must be an integer',
                ['capacity_per_slot' => $capacityPerSlot]
            );
        }

        /** @var array<int, int> $overrides Key and value types are validated by the constructor */
        return new self(
            $starts,
            TimelineDefinition::parseInterval($config['slot_interval'] ?? null, 'slot_interval'),
            $slotsPerSession,
            $overrides,
            $capacityPerSlot
        );
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts.
     *
     * @return array{sessions: array<string>, timezone: string, slot_interval: string, slots_per_session: int, slots_per_session_overrides?: array<int, int>, capacity_per_slot: int}
     */
    public function toArray(): array
    {
        $overrides = [];
        foreach ($this->slotCounts as $session => $slots) {
            if ($slots !== $this->slotsPerSession) {
                $overrides[$session] = $slots;
            }
        }

        $data = [
            'sessions' => array_map(
                static fn(DateTimeImmutable $start): string => $start->format('Y-m-d H:i:s'),
                $this->sessionStarts
            ),
            'timezone' => $this->sessionStarts[0]->getTimezone()->getName(),
            'slot_interval' => TimelineDefinition::formatInterval($this->slotInterval),
            'slots_per_session' => $this->slotsPerSession,
            'capacity_per_slot' => $this->capacityPerSlot,
        ];

        if ($overrides !== []) {
            $data['slots_per_session_overrides'] = $overrides;
        }

        return $data;
    }

    public function getSessionCount(): int
    {
        return count($this->sessionStarts);
    }

    /**
     * @param int $session 0-based session index
     *
     * @throws InvalidConfigurationException When the session is out of range
     */
    public function getSessionStart(int $session): DateTimeImmutable
    {
        if (!isset($this->sessionStarts[$session])) {
            throw new InvalidConfigurationException(
                'Session index is out of range for this grid',
                ['session' => $session, 'sessions' => $this->getSessionCount()]
            );
        }

        return $this->sessionStarts[$session];
    }

    /**
     * How many slots one session has, overrides applied.
     *
     * @param int $session 0-based session index
     *
     * @throws InvalidConfigurationException When the session is out of range
     */
    public function getSlotCount(int $session): int
    {
        if (!isset($this->slotCounts[$session])) {
            throw new InvalidConfigurationException(
                'Session index is out of range for this grid',
                ['session' => $session, 'sessions' => $this->getSessionCount()]
            );
        }

        return $this->slotCounts[$session];
    }

    public function getSlotInterval(): DateInterval
    {
        return $this->slotInterval;
    }

    /**
     * How many events may share one slot.
     */
    public function getCapacityPerSlot(): int
    {
        return $this->capacityPerSlot;
    }

    /**
     * Total assignable (session, slot) positions across the grid.
     */
    public function getPositionCount(): int
    {
        return array_sum($this->slotCounts);
    }

    /**
     * Whether a (session, slot) pair addresses a position on this grid.
     */
    public function hasPosition(int $session, int $slot): bool
    {
        return isset($this->slotCounts[$session]) && $slot >= 0 && $slot < $this->slotCounts[$session];
    }

    /**
     * The kickoff time of one slot, in UTC.
     *
     * Arithmetic is wall-clock in the grid's declared timezone (a 20:15
     * session start stays 20:15 across DST); the result is normalized to
     * UTC, matching the timeline family's convention.
     *
     * @param int $session 0-based session index
     * @param int $slot 0-based slot index within the session
     *
     * @throws InvalidConfigurationException When the position is not on the grid
     */
    public function getSlotTime(int $session, int $slot): DateTimeImmutable
    {
        if (!$this->hasPosition($session, $slot)) {
            throw new InvalidConfigurationException(
                'The position is not on this grid',
                [
                    'session' => $session,
                    'slot' => $slot,
                    'sessions' => $this->getSessionCount(),
                    'slots_in_session' => $this->slotCounts[$session] ?? null,
                ]
            );
        }

        $time = $this->sessionStarts[$session];
        for ($i = 0; $i < $slot; ++$i) {
            $time = $time->add($this->slotInterval);
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
