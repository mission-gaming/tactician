<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the sum of all opponents' ranking values (Buchholz system).
 *
 * Rewards having faced stronger opposition; commonly used in Swiss events.
 * This is the plain sum: no opponent is left out (no "median" or "cut"
 * variant).
 *
 * How a bye counts is the caller's choice (ByeTreatment):
 *
 * - Uncounted, the default: a bye adds nothing, and an opponent's ranking
 *   value is the value of its results, without the credit of its byes.
 * - Zero: a bye is an opponent worth nothing, so the participant's own bye
 *   adds nothing; an opponent's ranking value is the one it earned, with
 *   the credit of its byes.
 * - OwnRankingValue: each of the participant's byes adds the ranking value
 *   the participant itself earned, as an opponent of its own strength would;
 *   an opponent's ranking value is the one it earned.
 *
 * An example on a 3/1/0 scale with a bye worth a win. P had a bye, beat A
 * and lost to B. A beat B and has 3, all from results. B has 6, of which 3
 * for a bye of its own. P earned 6. The value of P is 3 + 3 = 6 under
 * Uncounted, 3 + 6 = 9 under Zero and 3 + 6 + 6 = 15 under
 * OwnRankingValue.
 *
 * An opponent's adjustment is read under no treatment. In a table without
 * byes the three give the same value.
 *
 * @experimental
 */
final readonly class BuchholzTiebreaker implements TiebreakerWithByeTreatment
{
    /**
     * @param ByeTreatment $byes How a bye counts (see the class docblock)
     */
    public function __construct(private ByeTreatment $byes = ByeTreatment::Uncounted) {}

    /**
     * Always 'buchholz', whatever the bye treatment: the key of this
     * tiebreaker's value in StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'buchholz';
    }

    #[Override]
    public function getByeTreatment(): ByeTreatment
    {
        return $this->byes;
    }

    /**
     * The sum, over every result whose event the participant is in, of the
     * ranking value of each other participant of that event, plus what the
     * participant's own byes add under the bye treatment.
     *
     * The ranking value is the opponent's final one in the table, whatever
     * the result between the two was: the value of its results under
     * ByeTreatment::Uncounted, and the value it earned, byes included,
     * under the other two. An opponent met twice is counted twice. An
     * event without a result adds nothing, and so does an opponent that
     * has no entry.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $sum = 0.0;

        foreach ($results as $result) {
            $event = $result->getEvent();
            if (!$event->hasParticipant($participant)) {
                continue;
            }

            foreach ($event->getParticipants() as $opponent) {
                if ($opponent->getId() === $participant->getId()) {
                    continue;
                }

                $opponentEntry = $entries[$opponent->getId()] ?? null;
                if ($opponentEntry !== null) {
                    $sum += $this->byes === ByeTreatment::Uncounted
                        ? $opponentEntry->getRankingValueOfResults()
                        : $opponentEntry->getEarnedRankingValue();
                }
            }
        }

        if ($this->byes === ByeTreatment::OwnRankingValue) {
            $own = $entries[$participant->getId()] ?? null;
            if ($own !== null && $own->getByes() > 0) {
                $sum += $own->getByes() * $own->getEarnedRankingValue();
            }
        }

        return $sum;
    }
}
