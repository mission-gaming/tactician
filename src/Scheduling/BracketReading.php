<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;

/**
 * What an elimination engine has read of a bracket in one walk through the
 * recorded state: the rounds it found recorded, and where the walk stopped.
 *
 * A walk stops in one of three ways, and exactly one is true afterwards:
 * at a round that is not recorded and has a tie to play (`$pending` is
 * that round), at a recorded round that still awaits results
 * (`$awaitingRound` and `$unresolved`), or at the end of the bracket
 * (neither is set).
 *
 * It is filled in by the walk and is not kept: an engine holds no state.
 *
 * @internal Not public API: the working record of the two elimination engines.
 */
final class BracketReading
{
    /** The number of the last recorded round the walk has read. */
    public int $number = 0;

    /**
     * Who advanced from each single-leg event that finished level, by leg key.
     *
     * @var array<string, Participant>
     */
    public array $levelAdvancers = [];

    /**
     * The recorded rounds as read, in play order.
     *
     * @var list<RecordedRound>
     */
    public array $rounds = [];

    /** The next round to pair, when the walk stopped at one. */
    public ?RoundPairing $pending = null;

    /** The recorded round that awaits results, when the walk stopped at one. */
    public ?int $awaitingRound = null;

    /**
     * The events of that round that await a result.
     *
     * @var list<Event>
     */
    public array $unresolved = [];

    /**
     * The tier of each participant by ID, 0 best: the placement of a
     * finished bracket, and of an unfinished one the round each entrant
     * went out in, with those still in it level in the first tier.
     *
     * @var array<int|string, int>
     */
    public array $placement = [];

    /**
     * @param array<int, RoundPairing> $recorded The recorded rounds by round number
     * @param array<string, Result> $resultIndex The recorded results by round, pairing and leg
     * @param array<int|string, true> $withdrawn The IDs of the participants who have withdrawn:
     *                                           everyone the state has seen and no longer
     *                                           lists as active
     * @param array<int|string, int> $lastNamedIn The number of the last recorded round that names
     *                                            each participant, in an event or as a bye, by ID
     */
    public function __construct(
        public readonly array $recorded,
        public readonly array $resultIndex,
        public readonly array $withdrawn,
        public readonly array $lastNamedIn = []
    ) {}

    /**
     * Whether the walk reached the end of the bracket.
     */
    public function isComplete(): bool
    {
        return !$this->pending instanceof RoundPairing && $this->awaitingRound === null;
    }
}
