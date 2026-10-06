<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;

/**
 * Computes a participant's primary ranking value for the standings table.
 *
 * Ordering is the contract; scoring is one means of producing it. "Points
 * from wins, draws, and losses" is one game family's paradigm — golf
 * leaderboards aggregate strokes, racing series score by finishing
 * position — so the standings calculator orders its table by this
 * pluggable value rather than assuming points. Tiebreakers already follow
 * the same shape (comparable values computed per participant); this is
 * the primary ranking made pluggable the same way.
 *
 * @experimental
 */
interface RankingStrategy
{
    /**
     * Compute a participant's primary ranking value from their results.
     *
     * Results not involving the participant are ignored, so callers may
     * pass either the participant's own results or a whole result set.
     * Higher is better — strategies for games where lower is better
     * (strokes, elapsed time) invert internally.
     *
     * The value must be a number: a table that holds a NAN has no defined
     * order. The standings calculator passes the participant's own results,
     * and an empty list for a participant that has none.
     *
     * @param array<Result> $results
     */
    public function rank(Participant $participant, array $results): float;
}
