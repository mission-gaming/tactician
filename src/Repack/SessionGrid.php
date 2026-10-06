<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
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
 * A grid comes in two forms. An instant-based grid (the constructor given
 * session starts, or fromArray() given `sessions`) knows when every
 * position is. A shape-only grid ({@see self::shapeOnly()}, or fromArray()
 * given `session_count`) knows only which positions exist: how many
 * sessions, how many slots each, and the capacity. It is for a caller that
 * keeps its own times (local wall-clock times, say) and needs the repack
 * for positions alone. Everything about positions works the same on both
 * forms. The accessors that return a time (getSessionStart(),
 * getSlotInterval(), getSlotTime(), and positionOf() given an instant)
 * throw on a shape-only grid, with the reason
 * InvalidConfigurationReason::GridWithoutInstants: a shape-only grid never
 * invents an instant. hasInstants() says which form a grid is.
 *
 * The capacity of a slot is a positive integer or null. Null is unbounded:
 * a slot hosts any number of concurrent events, and the only limit left is
 * that no participant is in two of them. The default is 1.
 *
 * The grid owns the mechanism only: which positions exist. Which events
 * are movable, and policy about times, stay application-side. Events that
 * fall outside the grid entirely cannot collide with it and are the
 * caller's to filter — collision is exact (session, slot) identity, by
 * design; there is no fuzzy time-overlap detection.
 */
final readonly class SessionGrid
{
    /**
     * What plain data carries as `capacity_per_slot` for an unbounded
     * capacity. It is a string, and not null, because fromArray() has always
     * read a null there as "not given" and applied the default of 1.
     */
    public const string UNBOUNDED = 'unbounded';

    /** @var array<int, DateTimeImmutable> Re-indexed 0..N-1; empty on a shape-only grid */
    private array $sessionStarts;

    /** Null on a shape-only grid */
    private ?DateInterval $slotInterval;

    /** @var array<int, int> Slot count per session index, overrides applied */
    private array $slotCounts;

    /**
     * Build an instant-based grid from its session starts.
     *
     * The first two parameters also accept the shape-only form (a session
     * count and a null interval); that is how {@see self::shapeOnly()}
     * builds its grid, and shapeOnly() is the way to ask for one. A count
     * with an interval, or session starts without one, is rejected.
     *
     * @param array<DateTimeImmutable>|int $sessionStarts Ordered session start instants,
     *                                                    all in the same declared timezone;
     *                                                    or, for a shape-only grid, the
     *                                                    number of sessions
     * @param DateInterval|null $slotInterval Time between consecutive slots within a
     *                                        session; null for a shape-only grid, and
     *                                        only for one
     * @param int $slotsPerSession Default slot count for every session
     * @param array<int, int> $slotsPerSessionOverrides Session index => slot count, for
     *                                                  sessions deeper or shallower than
     *                                                  the default
     * @param int|null $capacityPerSlot How many events may share one slot; null for
     *                                  unbounded
     *
     * @throws InvalidConfigurationException When the grid configuration is invalid
     */
    public function __construct(
        array|int $sessionStarts,
        ?DateInterval $slotInterval,
        private int $slotsPerSession = 1,
        array $slotsPerSessionOverrides = [],
        private ?int $capacityPerSlot = 1
    ) {
        if (is_int($sessionStarts)) {
            if ($sessionStarts < 1) {
                throw new InvalidConfigurationException(
                    'A session grid needs at least 1 session',
                    ['session_count' => $sessionStarts],
                    reason: InvalidConfigurationReason::ValueOutOfRange
                );
            }

            if ($slotInterval instanceof DateInterval) {
                throw new InvalidConfigurationException(
                    'A shape-only grid has no slot interval',
                    [
                        'session_count' => $sessionStarts,
                        'slot_interval' => TimelineDefinition::formatInterval($slotInterval),
                    ],
                    reason: InvalidConfigurationReason::IncompatibleOptions
                );
            }

            $sessionCount = $sessionStarts;
            $sessionStarts = [];
        } else {
            if ($sessionStarts === []) {
                throw new InvalidConfigurationException(
                    'A session grid needs at least 1 session',
                    ['session_starts' => []],
                    reason: InvalidConfigurationReason::EmptyList
                );
            }

            if (!$slotInterval instanceof DateInterval) {
                throw new InvalidConfigurationException(
                    'A grid with session starts needs a slot interval',
                    ['slot_interval' => null],
                    reason: InvalidConfigurationReason::IncompatibleOptions
                );
            }

            $sessionCount = count($sessionStarts);
        }

        $starts = [];
        $previous = null;
        $timezone = null;
        foreach (array_values($sessionStarts) as $index => $start) {
            if (!$start instanceof DateTimeImmutable) {
                throw new InvalidConfigurationException(
                    'Every session start must be a DateTimeImmutable',
                    ['index' => $index, 'given' => get_debug_type($start)],
                    reason: InvalidConfigurationReason::WrongValueType
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
                    ],
                    reason: InvalidConfigurationReason::TimezoneMismatch
                );
            }

            if ($previous !== null && $start <= $previous) {
                throw new InvalidConfigurationException(
                    'Session starts must be strictly ascending',
                    ['index' => $index, 'start' => $start->format('Y-m-d H:i:s')],
                    reason: InvalidConfigurationReason::NonAdvancingTime
                );
            }

            $starts[$index] = $start;
            $previous = $start;
        }

        if ($slotsPerSession < 1) {
            throw new InvalidConfigurationException(
                'A session needs at least 1 slot',
                ['slots_per_session' => $slotsPerSession],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($slotInterval instanceof DateInterval && $starts[0]->add($slotInterval) <= $starts[0]) {
            throw new InvalidConfigurationException(
                'The slot interval must move time forward',
                ['slot_interval' => TimelineDefinition::formatInterval($slotInterval)],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }

        if ($capacityPerSlot !== null && $capacityPerSlot < 1) {
            throw new InvalidConfigurationException(
                'A slot needs capacity for at least 1 event',
                ['capacity_per_slot' => $capacityPerSlot],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        $slotCounts = array_fill(0, $sessionCount, $slotsPerSession);
        foreach ($slotsPerSessionOverrides as $session => $slots) {
            if (!is_int($session) || !isset($slotCounts[$session])) {
                throw new InvalidConfigurationException(
                    'Slot count overrides must target existing session indexes',
                    ['session' => $session, 'sessions' => $sessionCount],
                    reason: InvalidConfigurationReason::PositionOutOfRange
                );
            }

            if (!is_int($slots) || $slots < 1) {
                throw new InvalidConfigurationException(
                    'An overridden session still needs at least 1 slot',
                    ['session' => $session, 'slots' => $slots],
                    reason: InvalidConfigurationReason::ValueOutOfRange
                );
            }

            $slotCounts[$session] = $slots;
        }

        $this->sessionStarts = $starts;
        $this->slotInterval = $slotInterval;
        $this->slotCounts = $slotCounts;
    }

    /**
     * Build a shape-only grid: the positions and nothing about when they
     * are.
     *
     * For a caller that has no UTC instants to give, because it keeps its
     * times in another form, and needs positions back. The repacker treats
     * the grid exactly as it treats an instant-based grid of the same
     * shape, so the same request gets the same (session, slot) for every
     * event; the assignments carry no kickoff
     * ({@see SlotAssignment::hasKickoff()}).
     *
     * @param int $sessions How many sessions the grid has
     * @param int $slotsPerSession Default slot count for every session
     * @param array<int, int> $slotsPerSessionOverrides Session index => slot count
     * @param int|null $capacityPerSlot How many events may share one slot; null for
     *                                  unbounded
     *
     * @throws InvalidConfigurationException When the shape is invalid
     */
    public static function shapeOnly(
        int $sessions,
        int $slotsPerSession = 1,
        array $slotsPerSessionOverrides = [],
        ?int $capacityPerSlot = 1
    ): self {
        return new self($sessions, null, $slotsPerSession, $slotsPerSessionOverrides, $capacityPerSlot);
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
     * A shape-only grid is the same data with `session_count` in place of
     * `sessions`, `timezone` and `slot_interval`:
     * ['session_count' => 2, 'slots_per_session' => 4]. It is read that way
     * only when there is no `sessions` key at all; with `sessions` present
     * the grid is instant-based and `session_count` is not read. A
     * shape-only grid that also gives `timezone` or `slot_interval` is
     * rejected.
     *
     * `capacity_per_slot` is a positive integer or the string
     * {@see self::UNBOUNDED}; left out, or null, it is 1.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException When a value is missing or malformed
     */
    public static function fromArray(array $config): self
    {
        if (!array_key_exists('sessions', $config) && array_key_exists('session_count', $config)) {
            $sessionCount = $config['session_count'];
            if (!is_int($sessionCount)) {
                throw new InvalidConfigurationException(
                    'session_count must be an integer',
                    ['session_count' => $sessionCount],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }

            foreach (['timezone', 'slot_interval'] as $key) {
                if (array_key_exists($key, $config)) {
                    throw new InvalidConfigurationException(
                        'A shape-only grid declares no timezone and no slot interval',
                        ['key' => $key, 'session_count' => $sessionCount],
                        reason: InvalidConfigurationReason::IncompatibleOptions
                    );
                }
            }

            [$slotsPerSession, $overrides, $capacityPerSlot] = self::shapeFromArray($config);

            return new self($sessionCount, null, $slotsPerSession, $overrides, $capacityPerSlot);
        }

        $sessions = $config['sessions'] ?? null;
        if (!is_array($sessions) || $sessions === []) {
            throw new InvalidConfigurationException(
                'sessions must be a non-empty list of datetime strings',
                ['sessions' => $sessions],
                reason: is_array($sessions) ? InvalidConfigurationReason::EmptyList : InvalidConfigurationReason::WrongValueType
            );
        }

        $starts = [];
        foreach (array_values($sessions) as $index => $session) {
            $starts[] = ZonedTime::parse($session, $config['timezone'] ?? null, "sessions[{$index}]");
        }

        [$slotsPerSession, $overrides, $capacityPerSlot] = self::shapeFromArray($config);

        return new self(
            $starts,
            TimelineDefinition::parseInterval($config['slot_interval'] ?? null, 'slot_interval'),
            $slotsPerSession,
            $overrides,
            $capacityPerSlot
        );
    }

    /**
     * The values both forms of plain data share.
     *
     * @param array<string, mixed> $config
     *
     * @return array{int, array<int, int>, int|null} Slots per session, overrides, capacity per slot
     *
     * @throws InvalidConfigurationException When a value is malformed
     */
    private static function shapeFromArray(array $config): array
    {
        $slotsPerSession = $config['slots_per_session'] ?? 1;
        if (!is_int($slotsPerSession)) {
            throw new InvalidConfigurationException(
                'slots_per_session must be an integer',
                ['slots_per_session' => $slotsPerSession],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $overrides = $config['slots_per_session_overrides'] ?? [];
        if (!is_array($overrides)) {
            throw new InvalidConfigurationException(
                'slots_per_session_overrides must map session indexes to slot counts',
                ['slots_per_session_overrides' => $overrides],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $capacityPerSlot = $config['capacity_per_slot'] ?? 1;
        if ($capacityPerSlot === self::UNBOUNDED) {
            $capacityPerSlot = null;
        } elseif (!is_int($capacityPerSlot)) {
            throw new InvalidConfigurationException(
                'capacity_per_slot must be an integer',
                ['capacity_per_slot' => $capacityPerSlot],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        /** @var array<int, int> $overrides Key and value types are validated by the constructor */
        return [$slotsPerSession, $overrides, $capacityPerSlot];
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts.
     *
     * An instant-based grid with a finite capacity serializes exactly as
     * it always has. A shape-only grid has `session_count` and none of
     * `sessions`, `timezone` and `slot_interval`. An unbounded capacity is
     * the string {@see self::UNBOUNDED}.
     *
     * @return array{sessions: array<string>, timezone: string, slot_interval: string, slots_per_session: int, slots_per_session_overrides?: array<int, int>, capacity_per_slot: int|string}|array{session_count: int, slots_per_session: int, slots_per_session_overrides?: array<int, int>, capacity_per_slot: int|string}
     */
    public function toArray(): array
    {
        $overrides = [];
        foreach ($this->slotCounts as $session => $slots) {
            if ($slots !== $this->slotsPerSession) {
                $overrides[$session] = $slots;
            }
        }

        if ($this->slotInterval instanceof DateInterval) {
            $data = [
                'sessions' => array_map(
                    static fn(DateTimeImmutable $start): string => $start->format('Y-m-d H:i:s'),
                    $this->sessionStarts
                ),
                'timezone' => $this->sessionStarts[0]->getTimezone()->getName(),
                'slot_interval' => TimelineDefinition::formatInterval($this->slotInterval),
                'slots_per_session' => $this->slotsPerSession,
                'capacity_per_slot' => $this->capacityPerSlot ?? self::UNBOUNDED,
            ];
        } else {
            $data = [
                'session_count' => count($this->slotCounts),
                'slots_per_session' => $this->slotsPerSession,
                'capacity_per_slot' => $this->capacityPerSlot ?? self::UNBOUNDED,
            ];
        }

        if ($overrides !== []) {
            $data['slots_per_session_overrides'] = $overrides;
        }

        return $data;
    }

    public function getSessionCount(): int
    {
        return count($this->slotCounts);
    }

    /**
     * Whether the grid knows when its positions are. False for a
     * shape-only grid, on which every accessor that returns a time throws.
     */
    public function hasInstants(): bool
    {
        return $this->slotInterval instanceof DateInterval;
    }

    /**
     * @param int $session 0-based session index
     *
     * @throws InvalidConfigurationException When the session is out of range, or the
     *                                       grid is shape-only
     */
    public function getSessionStart(int $session): DateTimeImmutable
    {
        $this->requireInstants('a session start');

        if (!isset($this->sessionStarts[$session])) {
            throw new InvalidConfigurationException(
                'Session index is out of range for this grid',
                ['session' => $session, 'sessions' => $this->getSessionCount()],
                reason: InvalidConfigurationReason::PositionOutOfRange
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
                ['session' => $session, 'sessions' => $this->getSessionCount()],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }

        return $this->slotCounts[$session];
    }

    /**
     * @throws InvalidConfigurationException When the grid is shape-only
     */
    public function getSlotInterval(): DateInterval
    {
        return $this->requireInstants('a slot interval');
    }

    /**
     * How many events may share one slot, as a number.
     *
     * A grid of unbounded capacity has no such number, and this throws
     * with the reason InvalidConfigurationReason::UnboundedCapacity rather
     * than return a stand-in that arithmetic would then use. Code that may
     * be given either kind of grid reads {@see self::getCapacityLimit()}.
     *
     * @throws InvalidConfigurationException When the capacity is unbounded
     */
    public function getCapacityPerSlot(): int
    {
        if ($this->capacityPerSlot === null) {
            throw new InvalidConfigurationException(
                'The capacity of this grid is unbounded',
                ['capacity_per_slot' => self::UNBOUNDED],
                reason: InvalidConfigurationReason::UnboundedCapacity
            );
        }

        return $this->capacityPerSlot;
    }

    /**
     * How many events may share one slot, or null when the capacity is
     * unbounded.
     */
    public function getCapacityLimit(): ?int
    {
        return $this->capacityPerSlot;
    }

    /**
     * Whether a slot hosts any number of concurrent events.
     */
    public function hasUnboundedCapacity(): bool
    {
        return $this->capacityPerSlot === null;
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
     * The ordinal of a position: its 0-based index when the positions are
     * counted in grid order, session by session and slot by slot within a
     * session. Session 0 slot 0 is 0; the last position is
     * getPositionCount() - 1.
     *
     * @param int $session 0-based session index
     * @param int $slot 0-based slot index within the session
     *
     * @throws InvalidConfigurationException When the position is not on the grid
     */
    public function ordinalOf(int $session, int $slot): int
    {
        if (!$this->hasPosition($session, $slot)) {
            throw self::positionNotOnGrid($session, $slot, $this->slotCounts);
        }

        $ordinal = $slot;
        for ($earlier = 0; $earlier < $session; ++$earlier) {
            $ordinal += $this->slotCounts[$earlier];
        }

        return $ordinal;
    }

    /**
     * The position at an ordinal or at an instant: the reverse of
     * ordinalOf() and of getSlotTime().
     *
     * Given an integer, it is the position with that ordinal. Given an
     * instant, it is the position whose slot time is that instant; the
     * comparison is of the instants, so the timezone the argument is
     * written in does not matter. Where one session runs on past the start
     * of the next, two positions can share an instant, and the first in
     * grid order is returned.
     *
     * @param DateTimeImmutable|int $at A position's ordinal, or a slot's instant
     *
     * @return array{session: int, slot: int}|null Null when no position has that
     *                                             ordinal or that instant
     *
     * @throws InvalidConfigurationException When given an instant and the grid is
     *                                       shape-only
     */
    public function positionOf(DateTimeImmutable|int $at): ?array
    {
        if (is_int($at)) {
            if ($at < 0) {
                return null;
            }

            foreach ($this->slotCounts as $session => $slots) {
                if ($at < $slots) {
                    return ['session' => $session, 'slot' => $at];
                }
                $at -= $slots;
            }

            return null;
        }

        $interval = $this->requireInstants('the position of an instant');

        foreach ($this->slotCounts as $session => $slots) {
            $time = $this->sessionStarts[$session];
            for ($slot = 0; $slot < $slots; ++$slot) {
                if (($time <=> $at) === 0) {
                    return ['session' => $session, 'slot' => $slot];
                }
                $time = $time->add($interval);
            }
        }

        return null;
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
     * @throws InvalidConfigurationException When the position is not on the grid, or
     *                                       the grid is shape-only
     */
    public function getSlotTime(int $session, int $slot): DateTimeImmutable
    {
        $interval = $this->requireInstants('a slot time');

        if (!$this->hasPosition($session, $slot)) {
            throw self::positionNotOnGrid($session, $slot, $this->slotCounts);
        }

        $time = $this->sessionStarts[$session];
        for ($i = 0; $i < $slot; ++$i) {
            $time = $time->add($interval);
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The slot interval, which every time the grid can state is built
     * from.
     *
     * @param string $asked What the caller asked for, for the error context
     *
     * @throws InvalidConfigurationException When the grid is shape-only
     */
    private function requireInstants(string $asked): DateInterval
    {
        if (!$this->slotInterval instanceof DateInterval) {
            throw new InvalidConfigurationException(
                'A shape-only grid has no instants',
                ['asked_for' => $asked, 'sessions' => $this->getSessionCount()],
                reason: InvalidConfigurationReason::GridWithoutInstants
            );
        }

        return $this->slotInterval;
    }

    /**
     * @param array<int, int> $slotCounts Slot count per session index
     */
    private static function positionNotOnGrid(int $session, int $slot, array $slotCounts): InvalidConfigurationException
    {
        return new InvalidConfigurationException(
            'The position is not on this grid',
            [
                'session' => $session,
                'slot' => $slot,
                'sessions' => count($slotCounts),
                'slots_in_session' => $slotCounts[$session] ?? null,
            ],
            reason: InvalidConfigurationReason::PositionOutOfRange
        );
    }
}
