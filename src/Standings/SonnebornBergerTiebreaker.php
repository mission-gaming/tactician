<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the ranking values of defeated opponents plus half those of
 * drawn opponents (Sonneborn-Berger system).
 *
 * Rewards beating strong opposition rather than merely facing it.
 *
 * How a bye counts is the caller's choice (ByeTreatment):
 *
 * - Uncounted, the default: a bye adds nothing, and an opponent's ranking
 *   value is the value of its results, without the credit of its byes.
 * - Zero: a bye is an opponent worth nothing, so the participant's own bye
 *   adds nothing; an opponent's ranking value is the one it earned, with
 *   the credit of its byes.
 * - OwnRankingValue: each of the participant's byes adds the ranking value
 *   the participant itself earned, in full, as an opponent of its own
 *   strength that it beat would; an opponent's ranking value is the one it
 *   earned. The bye is counted in full whatever the ranking strategy says
 *   a bye is worth: choose Zero where sitting out is not to count as an
 *   opponent beaten.
 *
 * In the example of BuchholzTiebreaker (P had a bye, beat A on 3 and lost
 * to B on 6, of which 3 for a bye; P earned 6) the value of P is 3 under
 * Uncounted, 3 under Zero (the opponent it beat has no bye) and 3 + 6 = 9
 * under OwnRankingValue. The value of B, who beat P and had a bye, is 3
 * under Uncounted (P's results are worth 3), 6 under Zero and 6 + 6 = 12
 * under OwnRankingValue.
 *
 * An opponent's adjustment is read under no treatment. In a table without
 * byes the three give the same value.
 *
 * @experimental
 */
final readonly class SonnebornBergerTiebreaker implements TiebreakerWithByeTreatment
{
    /**
     * @param ByeTreatment $byes How a bye counts (see the class docblock)
     */
    public function __construct(private ByeTreatment $byes = ByeTreatment::Uncounted) {}

    /**
     * Always 'sonneborn-berger', whatever the bye treatment: the key of
     * this tiebreaker's value in StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'sonneborn-berger';
    }

    #[Override]
    public function getByeTreatment(): ByeTreatment
    {
        return $this->byes;
    }

    /**
     * The sum, over every result whose event the participant is in, of the
     * ranking value of each other participant of that event: in full when
     * the participant won it, halved when it was drawn, and not at all when
     * the participant lost it; plus what the participant's own byes add
     * under the bye treatment.
     *
     * The ranking value is the opponent's final one in the table: the
     * value of its results under ByeTreatment::Uncounted, and the value it
     * earned, byes included, under the other two. An opponent beaten twice
     * is counted twice. An event without a result adds nothing, and so
     * does an opponent that has no entry.
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

            $factor = match (true) {
                $result->isDraw() => 0.5,
                $result->isWinFor($participant) => 1.0,
                default => 0.0,
            };

            if ($factor === 0.0) {
                continue;
            }

            foreach ($event->getParticipants() as $opponent) {
                if ($opponent->getId() === $participant->getId()) {
                    continue;
                }

                $opponentEntry = $entries[$opponent->getId()] ?? null;
                if ($opponentEntry !== null) {
                    $sum += $factor * ($this->byes === ByeTreatment::Uncounted
                        ? $opponentEntry->getRankingValueOfResults()
                        : $opponentEntry->getEarnedRankingValue());
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
