<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * One round of a bracket (see Bracket): a structural round of one side,
 * with its nodes.
 *
 * A structural round is a round of the shape of the bracket, whether or
 * not it is played: the rounds of a side are numbered 1, 2, 3 from its
 * first. It is not the number of a recorded round, which counts only the
 * rounds that had an event to play, across sides, in the order they were
 * played (getRoundNumber()).
 *
 * Built by the elimination engines (BracketViewEngine::getBracket()).
 *
 * @experimental
 */
final readonly class BracketRound
{
    /** @var list<BracketNode> */
    private array $nodes;

    /**
     * @param int $round The structural round on its side, 1-based
     * @param int $roundsOnSide How many rounds the side has, as far as the bracket can say now
     * @param int $slotCount How many slots the round is entered with
     * @param array<BracketNode> $nodes The nodes of the round, in bracket order
     * @param int|null $roundNumber The number of the recorded round this round was read from
     * @param bool $manual Whether that recorded round pairs other entrants than the engine would
     * @param bool $next Whether this is the round the engine pairs next
     */
    public function __construct(
        private BracketSide $side,
        private int $round,
        private int $roundsOnSide,
        private int $slotCount,
        array $nodes,
        private ?int $roundNumber = null,
        private bool $manual = false,
        private bool $next = false
    ) {
        $this->nodes = array_values($nodes);
    }

    /**
     * The side of the bracket the round is on.
     */
    public function getSide(): BracketSide
    {
        return $this->side;
    }

    /**
     * The structural round on its side, 1-based.
     */
    public function getRound(): int
    {
        return $this->round;
    }

    /**
     * How many rounds the side has. For a single elimination bracket it is
     * what the bracket can say now: a round drawn by hand, or void ties in
     * a re-seeded bracket, can leave fewer entrants for a later round than
     * its shape had, and the rounds after it are counted from that.
     */
    public function getRoundsOnSide(): int
    {
        return $this->roundsOnSide;
    }

    /**
     * How many slots the round is entered with: twice its nodes, without
     * the third-place tie.
     */
    public function getSlotCount(): int
    {
        return $this->slotCount;
    }

    /**
     * The English label of the round (see BracketRoundLabel): the label the
     * engine puts on the round when it pairs it.
     */
    public function getLabel(): string
    {
        return BracketRoundLabel::of($this->side, $this->round, $this->roundsOnSide, $this->slotCount);
    }

    /**
     * The nodes of the round, in bracket order, top first; in the last
     * round of a single elimination bracket with a third-place tie, the
     * final and then the third-place tie.
     *
     * @return list<BracketNode>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * The number of the recorded round this round was read from, or null
     * where it is not recorded: a round still to be paired or played, or
     * a round in which no tie was to be played because of withdrawals,
     * which takes no number.
     */
    public function getRoundNumber(): ?int
    {
        return $this->roundNumber;
    }

    /**
     * Whether the round is recorded on the state.
     */
    public function isRecorded(): bool
    {
        return $this->roundNumber !== null;
    }

    /**
     * Whether the recorded round pairs other entrants than the engine would
     * have paired (RecordedRound::isManual()). False for a round that is not
     * recorded.
     */
    public function isManual(): bool
    {
        return $this->manual;
    }

    /**
     * Whether this is the round the engine pairs next
     * (StageEngineInterface::pairNextRound()): the readiness of the stage
     * is ready, and the pairing is this round's nodes that are to be
     * paired, with those that hold one entrant, or one who has not
     * withdrawn, as its byes.
     */
    public function isNext(): bool
    {
        return $this->next;
    }
}
