<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * A withdrawal from a live stage with the time it happened: who withdrew,
 * and after which recorded round.
 *
 * A stage state keeps one for every participant withdrawn with
 * StageState::withoutParticipant() once a round is recorded, in the order
 * they withdrew (StageState::getWithdrawals()). An application that builds
 * a state from its own rows says the same by passing these to
 * StageState::fromHistory() in the place of plain participants.
 *
 * The round is the number of the last round that was recorded when the
 * participant withdrew: 2 for a participant who left after round 2 was
 * recorded, whether round 2 was still being played or was finished. It was
 * in the stage when round 2 was paired and not when round 3 was. A bracket
 * engine reads that: a tie the participant was in is given to its opponent,
 * and where both entrants of a tie have withdrawn, the one who withdrew
 * first gave it to the other, who went on. Of two withdrawals after the
 * same round, the one listed first happened first.
 *
 * A withdrawal before the first round is recorded is not one of these: the
 * participant is removed from the entrant list and the stage is drawn
 * without it.
 *
 * @experimental
 */
final readonly class Withdrawal
{
    /**
     * @param Participant $participant The participant who withdrew
     * @param int $afterRound The number of the last round recorded when it withdrew, 1 or above
     *
     * @throws InvalidInputException When the round is below 1
     */
    public function __construct(
        private Participant $participant,
        private int $afterRound
    ) {
        if ($afterRound < 1) {
            throw new InvalidInputException(
                "The withdrawal of participant {$participant->getId()} cannot be after round {$afterRound}: a withdrawal"
                    . ' is recorded with the number of the last recorded round, which is 1 or above. Before the first'
                    . ' round a withdrawn participant is left out of the entrant list.'
            );
        }
    }

    /**
     * The participant who withdrew.
     */
    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    /**
     * The number of the last round that was recorded when the participant
     * withdrew.
     */
    public function getAfterRound(): int
    {
        return $this->afterRound;
    }
}
