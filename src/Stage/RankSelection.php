<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use Countable;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * What a rank selection selected, with the mark of every place that a
 * decision settled.
 *
 * The participants are the list `select()` returns, in the same order. A
 * selected participant is marked when results did not decide the place it
 * was selected at and a decision did (`RankRangeSelector::withDecisions()`,
 * `BestOfRankSelector::withDecisions()`, or a decision the table itself
 * carried): the selection then holds that decision, with the label and the
 * reason the application gave it, so that the place can be shown and
 * audited as decided outside the results. A participant that results
 * placed has no decision.
 *
 * A best-of-rank selection can settle two things about one participant by
 * decision: that it holds the rank in its pool, and where it stands among
 * the holders. Such a participant has both decisions, in that order.
 *
 * @experimental
 */
final readonly class RankSelection implements Countable
{
    /** @var list<Participant> */
    private array $participants;

    /** @var array<string, list<Decider>> */
    private array $decisions;

    /**
     * Built by the rank selectors. The arguments are stored as given.
     *
     * @param array<Participant> $participants The selected participants, in the selector's order
     * @param array<array<Decider>> $decisions For each marked participant, by participant ID, the
     *                                         decisions that settled its place, in the order
     *                                         they were applied
     */
    public function __construct(array $participants, array $decisions = [])
    {
        $this->participants = array_values($participants);

        $kept = [];
        foreach ($decisions as $participantId => $settledBy) {
            if ($settledBy !== []) {
                $kept[(string) $participantId] = array_values($settledBy);
            }
        }
        $this->decisions = $kept;
    }

    /**
     * The selected participants, in the selector's order: the list
     * `select()` returns, which the destination stage takes as its
     * entrants.
     *
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * Whether a decision settled the place this participant was selected
     * at. False for a participant that results placed, and for one that is
     * not selected.
     */
    public function isSettledByDecision(Participant $participant): bool
    {
        return isset($this->decisions[$participant->getId()]);
    }

    /**
     * The decisions that settled the place this participant was selected
     * at, in the order they were applied: empty for a participant that
     * results placed, one decision for a tie in a table, and two where a
     * best-of-rank selection settled both who holds the rank in the pool
     * and the order of the holders.
     *
     * @return list<Decider>
     */
    public function getDecisionsFor(Participant $participant): array
    {
        return $this->decisions[$participant->getId()] ?? [];
    }

    /**
     * Every decision that settled the place of a selected participant,
     * each once, in the order of the selected list. A decision the
     * selector was given for a tie that touches no selected place is not
     * among them.
     *
     * @return list<Decider>
     */
    public function getDecisions(): array
    {
        $decisions = [];
        foreach ($this->participants as $participant) {
            foreach ($this->getDecisionsFor($participant) as $decision) {
                if (!in_array($decision, $decisions, true)) {
                    $decisions[] = $decision;
                }
            }
        }

        return $decisions;
    }

    /**
     * The number of selected participants.
     */
    #[Override]
    public function count(): int
    {
        return count($this->participants);
    }
}
