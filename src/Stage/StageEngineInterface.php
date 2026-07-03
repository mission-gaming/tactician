<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Results-driven stage engine: resolves the next round from the recorded
 * state, one interface for every format.
 *
 * Formats whose later rounds depend on results (Swiss, brackets) cannot be
 * generated whole. An engine consumes a StageState and produces the next
 * RoundPairing; the platform plays it, records it back onto the state, and
 * repeats — one driver loop total, not one integration per format:
 *
 *     $state = StageState::start($participants);
 *     while (!$engine->isComplete($state)) {
 *         $pairing = $engine->pairNextRound($state);
 *         $results = playRound($pairing);              // application-side
 *         $state = $state->withRoundPlayed($pairing, $results);
 *     }
 *     $outcome = $engine->getOutcome($state);          // feed progression
 */
interface StageEngineInterface
{
    /**
     * The shape declaration for the stage in its current state.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot form a plan (e.g. too few participants)
     */
    public function getPlan(StageState $state): StagePlan;

    /**
     * Pair the next round from the recorded state.
     *
     * @throws \MissionGaming\Tactician\Exceptions\NoValidPairingException When no complete pairing exists for the round
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be paired (too few participants, malformed configuration)
     */
    public function pairNextRound(StageState $state): RoundPairing;

    /**
     * Whether the stage has no further rounds. Structural, not
     * interpretive: "someone won" is the consumer's reading of the
     * outcome, "no more rounds exist" is the engine's.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the recorded state is malformed
     */
    public function isComplete(StageState $state): bool;

    /**
     * The uniform completion product; null while the stage is unfinished.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the recorded state is malformed
     */
    public function getOutcome(StageState $state): ?StageOutcome;
}
