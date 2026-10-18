<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The algorithm identifiers of the library's stage plans, as cases.
 *
 * Each case is backed by the string StagePlan::getAlgorithm() returns for
 * the plans of that algorithm, which a generated schedule also records under
 * the `algorithm` key of its metadata (DTO\MetadataKey::ALGORITHM). The
 * strings are part of the public contract and do not change between
 * releases, so an application that maps its configuration to the library
 * can match on a case where it matched on a string:
 * `Algorithm::from($plan->getAlgorithm())`, or `tryFrom()` for a plan of
 * its own whose identifier the library does not know.
 *
 * @experimental
 */
enum Algorithm: string
{
    /**
     * Every participant meets every other one, once in each leg
     * (RoundRobinPlan).
     */
    case RoundRobin = 'round-robin';

    /**
     * Rounds paired from the table of the rounds before, results-driven or
     * drawn up front by the whole-schedule preset (SwissPlan).
     */
    case Swiss = 'swiss';

    /**
     * Opponents drawn from pots of equal size (PotDrawPlan).
     */
    case PotDraw = 'pot-draw';

    /**
     * A knockout bracket in which one loss puts a participant out
     * (EliminationPlan).
     */
    case SingleElimination = 'single-elimination';

    /**
     * A knockout bracket with a losers bracket, in which a participant goes
     * out on its second loss (EliminationPlan).
     */
    case DoubleElimination = 'double-elimination';
}
