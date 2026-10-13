<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * How a tiebreaker that is computed from opponents treats a bye.
 *
 * A bye is a round a participant sat out: it has no opponent and no result.
 * A tiebreaker that adds up something about the opponents a participant met
 * (BuchholzTiebreaker, SonnebornBergerTiebreaker) has two questions to
 * answer about it, and a case answers both:
 *
 * - what the participant's own bye adds to its value;
 * - whether the ranking value read for an opponent includes what that
 *   opponent was credited for byes of its own
 *   (StandingEntry::getEarnedRankingValue()) or is the value of its results
 *   alone (StandingEntry::getRankingValueOfResults()).
 *
 * An adjustment is part of neither: no case reads an opponent's adjustment.
 *
 * The treatment changes nothing in a table without byes: every case gives
 * the same value there.
 *
 * @experimental
 */
enum ByeTreatment: string
{
    /**
     * No bye counts, as before a bye could be valued: the participant's own
     * bye adds nothing, and an opponent's ranking value is the value of its
     * results, without the credit of its byes. The default of the library's
     * tiebreakers, so that a table calculated by an earlier release is
     * calculated the same.
     */
    case Uncounted = 'uncounted';

    /**
     * A bye is an opponent worth nothing: the participant's own bye adds
     * zero. An opponent's ranking value is the one it earned, the credit of
     * its byes included, which is the value its table shows before any
     * adjustment.
     */
    case Zero = 'zero';

    /**
     * A bye is an opponent with the participant's own ranking value: each
     * of the participant's byes adds the ranking value the participant
     * earned (the credit of its byes included, no adjustment). An
     * opponent's ranking value is the one it earned, as under Zero.
     */
    case OwnRankingValue = 'own-ranking-value';
}
