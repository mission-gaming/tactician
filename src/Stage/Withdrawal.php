<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * A withdrawal from a stage with the time it happened: who withdrew, and
 * after which recorded round.
 *
 * A stage state keeps one for every participant withdrawn with
 * StageState::withoutParticipant() (StageState::getWithdrawals()). An
 * application that builds a state from its own rows says the same by
 * passing these to StageState::fromHistory(): once the rows hold a round,
 * every withdrawal is one of these, and a plain participant is refused.
 *
 * The round is the number of the last round that was recorded when the
 * participant withdrew: 2 for a participant who left after round 2 was
 * recorded, whether round 2 was still being played or was finished, and 0
 * for one who left before any round was recorded. It was in the stage when
 * round 2 was paired, and not when round 3 was unless round 3 names it: a
 * round that is recorded only once it has been played was paired before a
 * withdrawal during it, which is then recorded with the round before. So a
 * round that names a participant, in an event or as a bye, is read as
 * paired while the participant was in the stage, and so is every round
 * before it, whatever round its withdrawal states: a round is not to name a
 * participant who had left before it was paired (see
 * StageState::withoutParticipant()). A round that does not name a
 * participant says nothing of it, and a withdrawal recorded before that
 * round is read as made before it was paired. In double elimination that
 * includes a winners round for an entrant of a losers round that had no
 * tie to play and was not recorded: an entrant who went on alone from
 * such a round and leaves while the next winners round is played is
 * placed one stage lower when the withdrawal is recorded before that
 * winners round than when the round is recorded first.
 *
 * Round 0 covers the first round in the same way. A participant who
 * withdrew after round 0 was in the draw when the first round names it
 * (it left while that round was played, before it was recorded), and
 * keeps its place in the entrant list; where the first round does not name
 * it, it left before the draw and is no entrant: the stage is drawn
 * without it, and the withdrawal is not kept once the first round is
 * recorded.
 *
 * A bracket engine reads a tie the participant was in and has no complete
 * result for as given to its opponent. Where both entrants of such a tie
 * have withdrawn before the bracket moved on from it, nobody went on from
 * it: the tie is void (see StageState::withoutParticipant()). The order of
 * two withdrawals after the same round is kept as given and decides
 * nothing.
 *
 * @experimental
 */
final readonly class Withdrawal
{
    /**
     * @param Participant $participant The participant who withdrew
     * @param int $afterRound The number of the last round recorded when it withdrew, 0 before
     *                        the first
     *
     * @throws InvalidInputException When the round is below 0
     */
    public function __construct(
        private Participant $participant,
        private int $afterRound
    ) {
        if ($afterRound < 0) {
            throw new InvalidInputException(
                "The withdrawal of participant {$participant->getId()} cannot be after round {$afterRound}: a withdrawal"
                    . ' is recorded with the number of the last recorded round, which is 0 before the first round is'
                    . ' recorded.'
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
     * withdrew: 0 before the first round was recorded.
     */
    public function getAfterRound(): int
    {
        return $this->afterRound;
    }
}
