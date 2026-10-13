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
 * A chain usually names several, most decisive first. Each is computed for
 * the participants still level when its turn comes: three participants
 * level on the ranking value who each beat one of the others are level on
 * the ranking value they took from each other too, and a score difference
 * named after it is then that of the three events among them. Where the
 * first measure separates one of the three, the next is computed over the
 * two it left level, from the one event between them: several of these in
 * a row narrow the set step by step and are not one small table of
 * everyone level on the ranking value.
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
