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
 *
 * @experimental
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
     * The state is not changed: the caller records the returned pairing
     * with StageState::withRoundPlayed(), and until it does the engine is
     * asked about the same round. The round number is the engine's and
     * 1-based. The same state gives the same pairing from an engine that
     * draws nothing; an engine that was given a randomizer draws from it
     * on every call.
     *
     * Ask isComplete() first. What an engine does when it is asked to
     * pair a complete stage is not part of this contract: today a bracket
     * engine refuses it, and the Swiss engine pairs a round beyond its
     * planned ones, or refuses a stage that has fewer than 2 active
     * participants.
     *
     * @throws \MissionGaming\Tactician\Exceptions\NoValidPairingException When no complete pairing exists for the round
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the state cannot be paired (too few
     *                                                                          participants, malformed configuration, a
     *                                                                          bracket round with only some of its
     *                                                                          results, a bracket that is complete)
     */
    public function pairNextRound(StageState $state): RoundPairing;

    /**
     * Whether the stage has no further rounds. Structural, not
     * interpretive: "someone won" is the consumer's reading of the
     * outcome, "no more rounds exist" is the engine's.
     *
     * A stage with no length of its own (a Swiss stage configured without
     * a number of rounds) does not become complete by being played: the
     * driver loop then needs a stopping rule of the application's.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the recorded state is malformed
     */
    public function isComplete(StageState $state): bool;

    /**
     * The uniform completion product; null while the stage is unfinished,
     * that is, exactly while isComplete() is false.
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the recorded state is malformed
     */
    public function getOutcome(StageState $state): ?StageOutcome;
}
