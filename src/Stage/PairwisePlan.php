<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A stage plan whose format guarantees pairwise meeting counts up front.
 *
 * Round-robin-family plans know exactly how often every pair of
 * participants meets, so diagnostics and validation can reason about
 * missing or duplicated pairings. This is a capability of that family,
 * not a universal plan method — Swiss and bracket pairings depend on
 * results, and N-participant formats (racing heats, lobbies) have no
 * pairwise meetings at all.
 */
interface PairwisePlan extends StagePlan
{
    /**
     * How many times these two participants are expected to meet across
     * the whole stage.
     *
     * Returns 0 when either participant is not part of the stage or the
     * two are the same participant.
     */
    public function getExpectedMeetings(Participant $a, Participant $b): int;
}
