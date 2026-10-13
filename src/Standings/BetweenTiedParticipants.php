<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Breaks ties by a measure taken over the events between the tied
 * participants only (a sport calls it head-to-head).
 *
 * It wraps another tiebreaker, the measure, and is a
 * TiedParticipantsTiebreaker: the standings calculator hands it, for each
 * participant, the results between that participant and the others it is
 * level with, and the entries of the table those results alone give. The
 * measure is asked with exactly that, so it answers for the small table
 * what it answers for a whole one:
 *
 * - new BetweenTiedParticipants(new RankingValueTiebreaker()): the ranking
 *   value taken from the others of the tied set;
 * - new BetweenTiedParticipants(new WinsTiebreaker()): the wins over them;
 * - new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()) and
 *   new BetweenTiedParticipants(new ScoresForTiebreaker()): the score
 *   difference and the scores-for of those events.
 *
 * A chain usually names several in a row, most decisive first, and those
 * that follow one another are one small table: every one of them is
 * computed over the same tied set, the participants level when the first
 * of them is asked, from the events among all of those. Three participants
 * level on the ranking value who each beat one of the others are level on
 * the ranking value they took from each other too, and a score difference
 * named after it is that of the three events among them. It is that of
 * the three events also where the first measure places one of the three
 * apart: the two it left level keep what each did against the third. A
 * tiebreaker of another kind between two of these ends the small table,
 * and the one after it is computed for those level at that point.
 *
 * Two participants who have not met have no events between them: every
 * measure gives each of them what it gives for no result (0.0 for the four
 * above), and they stay level.
 *
 * One measure has one place in a chain: the name is that of the measure,
 * and a calculator refuses a chain with one name twice
 * (StandingsCalculator::__construct()).
 *
 * @experimental
 */
final readonly class BetweenTiedParticipants implements TiedParticipantsTiebreaker
{
    /**
     * @param TiebreakerInterface $measure What is measured over the events between the tied
     *                                     participants. It is handed the results and the
     *                                     entries of the tied set only
     *
     * @throws InvalidConfigurationException When the measure is itself a tiebreaker between the
     *                                       tied participants: it is already computed that way
     *                                       (reason `IncompatibleOptions`)
     */
    public function __construct(private TiebreakerInterface $measure)
    {
        if ($measure instanceof TiedParticipantsTiebreaker) {
            throw new InvalidConfigurationException(
                'A tiebreaker between the tied participants cannot wrap another one of that kind',
                ['measure' => $measure->getName()],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }
    }

    /**
     * 'between-tied:' followed by the name of the measure
     * ('between-tied:score-difference'): the key of this tiebreaker's value
     * in StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'between-tied:' . $this->measure->getName();
    }

    /**
     * The measure this tiebreaker takes between the tied participants.
     */
    public function getMeasure(): TiebreakerInterface
    {
        return $this->measure;
    }

    /**
     * The value of the measure for the participant, from what is handed:
     * by the standings calculator, the results between the participants of
     * its tied set and the entries of that set (TiedParticipantsTiebreaker
     * has the contract). Called with anything else it passes that on as it
     * is.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        return $this->measure->calculate($participant, $results, $entries);
    }
}
