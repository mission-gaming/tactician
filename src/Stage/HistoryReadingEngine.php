<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Standings\Standings;

/**
 * An engine that can be asked how it reads a stage in progress, without
 * anything being paired and without an exception for a round that is still
 * being played.
 *
 * The three engines of the library implement it. It is separate from
 * StageEngineInterface so that adding it broke no engine written against
 * that interface; check for it with instanceof where the engine is held as
 * a StageEngineInterface.
 *
 * None of the three methods throws for a round that has some or none of
 * its results. Each throws what every call of the engine throws for a
 * state it cannot read at all: one stamped with another engine's
 * fingerprint, one with too few participants, and, in a bracket, a tie
 * that is level without a decision or an event with two results.
 *
 * @experimental
 */
interface HistoryReadingEngine
{
    /**
     * Whether the next round can be paired now and, when it cannot, the
     * events that await a result.
     *
     * The answer agrees with pairNextRound(): ready means it pairs a
     * round, awaiting results means it throws an UnresolvedRoundException
     * with the same events, and complete means isComplete() is true.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be read at all
     */
    public function getReadiness(StageState $state): PairingReadiness;

    /**
     * Every round recorded on the state as the engine reads it, in play
     * order: the round as recorded, the round the engine would have paired
     * in its place, whether the two differ, and the events that await a
     * result (see RecordedRound).
     *
     * @return list<RecordedRound>
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be read at all
     */
    public function getRecordedRounds(StageState $state): array;

    /**
     * The table of the stage as recorded so far, whether or not the stage
     * is complete: the table getOutcome() carries once it is.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be read at all
     */
    public function getStandings(StageState $state): Standings;
}
