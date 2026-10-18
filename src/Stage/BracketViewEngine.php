<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * An engine that can show a stage as a bracket: the structured view of
 * every round, node and slot (see Bracket).
 *
 * The two elimination engines implement it. Like HistoryReadingEngine it
 * is separate from StageEngineInterface, so that adding it broke no engine
 * written against that interface; check for it with instanceof where the
 * engine is held as a StageEngineInterface.
 *
 * @experimental
 */
interface BracketViewEngine
{
    /**
     * The bracket as this engine reads the state, at any point of the
     * stage: before the first round, with a round that awaits results or a
     * tie that awaits its decider, and once the bracket is complete.
     *
     * It throws only what every call of the engine throws for a state it
     * cannot read at all (see HistoryReadingEngine): never for a round in
     * play, a level tie without its decider, a withdrawal or a void tie.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be read at all
     */
    public function getBracket(StageState $state): Bracket;
}
