<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;

/**
 * One tie of a bracket as its engine settled it: who went on from it, and
 * who did not.
 *
 * It is the engine's reading and not a second record. The results decide a
 * tie where they are complete (with the decider of a level one, see
 * TieDecision). Where they are not, a tie an entrant has withdrawn from is
 * uncontested: the other entrant went on without it being played, and the
 * round may hold no event for it at all. The results alone do not say that,
 * which is why the outcome of a bracket carries its last ties in this form
 * (StageOutcome::getSettledTies()) and MatchOutcomeSelector reads them.
 *
 * Where both entrants have withdrawn and no result decides the tie, it is
 * void: nobody went on from it (the advancer is null) and both are
 * eliminated (see Withdrawal).
 *
 * There is no winner or loser here on purpose: an entrant can go on from a
 * tie nobody played.
 *
 * @experimental
 */
final readonly class SettledTie
{
    /** @var list<Participant> */
    private array $eliminated;

    /**
     * @param Participant|null $advancer The entrant who went on from the tie; null when nobody
     *                                   did, because the tie is void: both entrants withdrew
     * @param array<Participant> $eliminated The entrants of the tie who did not go on: one, both
     *                                       where nobody went on, or none for an entrant who
     *                                       stood alone
     */
    public function __construct(
        private ?Participant $advancer,
        array $eliminated = []
    ) {
        $this->eliminated = array_values($eliminated);
    }

    /**
     * The entrant who went on from the tie, or null when nobody did.
     */
    public function getAdvancer(): ?Participant
    {
        return $this->advancer;
    }

    /**
     * The entrants of the tie who did not go on, in the order of their
     * slots; empty for an entrant who stood alone.
     *
     * @return list<Participant>
     */
    public function getEliminated(): array
    {
        return $this->eliminated;
    }
}
