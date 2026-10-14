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
 * that round), at a recorded round that still awaits a result or the
 * decision of a level tie (`$awaitingRound`, with `$unresolved` and
 * `$undecided`), or at the end of the bracket (neither is set).
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

    /** The recorded round that awaits results or a decision, when the walk stopped at one. */
    public ?int $awaitingRound = null;

    /**
     * The events of that round whose tie has every result, is level and
     * awaits its decider.
     *
     * @var list<Event>
     */
    public array $undecided = [];

    /**
     * What is to be said of the first tie of the round that is level
     * without a decider, in bracket order and whatever the order of the
     * recorded events: the message and the context (the round and the two
     * participants of the tie) of the refusal pairNextRound() gives for
     * the round.
     *
     * @var array{issue: string, context: array<string, mixed>}|null
     */
    public ?array $undecidedTie = null;

    /**
     * Whether this walk has raised the deprecation of the 'tie_winner'
     * metadata key: it is raised once for a call of the engine, however
     * many ties the key decides.
     */
    public bool $tieWinnerDeprecationRaised = false;

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
     * The ties of the last round the walk read to its end, in bracket
     * order: of a finished bracket, the ties that decided it.
     *
     * @var list<array{winner: Participant|null, losers: list<Participant>}>
     */
    public array $decidingTies = [];

    /**
     * @param array<int, RoundPairing> $recorded The recorded rounds by round number
     * @param array<string, Result> $resultIndex The recorded results by round, pairing and leg
     * @param array<int|string, true> $withdrawn The IDs of the participants who have withdrawn:
     *                                           everyone the state has seen and no longer
     *                                           lists as active
     * @param array<int|string, int> $lastNamedIn The number of the last recorded round that names
     *                                            each participant, in an event or as a bye, by ID
     * @param array<int|string, array{int, int}> $withdrawals Of each withdrawal whose time the
     *                                                        state knows, by ID: the number of
     *                                                        the last round recorded when it
     *                                                        happened, and its place in the order
     *                                                        the withdrawals happened in
     */
    public function __construct(
        public readonly array $recorded,
        public readonly array $resultIndex,
        public readonly array $withdrawn,
        public readonly array $lastNamedIn = [],
        public readonly array $withdrawals = []
    ) {}

    /**
     * Who had withdrawn when a round was paired, by ID.
     *
     * A round that names a participant, in an event or as a bye, was
     * paired while it was in the stage, and so was every round before it:
     * nobody who has withdrawn is paired. That is read before anything the
     * state says of when the participant left, because a withdrawal is
     * recorded with the last round recorded at that moment, and a round
     * can be recorded after it was paired: an application that pairs a
     * round, has it played and records it with its results records a
     * withdrawal during that round before the round.
     *
     * A participant no round from N on names had withdrawn when round N
     * was paired if its withdrawal has a round before N, or has no round:
     * when it left is then not known, and no round shows it in the stage.
     *
     * @param int|null $pairedAt The number of the recorded round the pairing was made for, or
     *                           null for a pairing made now, from the state as it stands
     *
     * @return array<int|string, true>
     */
    public function withdrawnWhenPaired(?int $pairedAt): array
    {
        if ($pairedAt === null) {
            return $this->withdrawn;
        }

        $out = [];
        foreach ($this->withdrawn as $id => $_) {
            if (($this->lastNamedIn[$id] ?? 0) >= $pairedAt) {
                continue;
            }

            if (!isset($this->withdrawals[$id]) || $this->withdrawals[$id][0] < $pairedAt) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * Whether the state says when the participant withdrew.
     */
    public function knowsWithdrawalOf(string $id): bool
    {
        return isset($this->withdrawals[$id]);
    }

    /**
     * Whether the walk reached the end of the bracket.
     */
    public function isComplete(): bool
    {
        return !$this->pending instanceof RoundPairing && $this->awaitingRound === null;
    }
}
