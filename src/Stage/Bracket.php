<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The structured view of an elimination bracket as its engine reads a stage
 * state: every round of every side, each with its nodes, and each node with
 * its slots, where it stands and who went on from it.
 *
 * It is the whole bracket at every moment, not only the rounds that are
 * recorded: the rounds still to come are there with the slots they will be
 * filled from (a placeholder naming the node: SlotPlaceholder::AdvancerOf,
 * LoserOf, Reseeded) and the slots that will hold nobody (Bye, Void,
 * NotPlayed). A bracket with byes, two-legged ties, a third-place tie or a
 * losers bracket can be drawn from it alone, and the English labels of the
 * rounds are a rendering of it (RoundLabel::ofBracketRound()).
 *
 * It is the engine's reading and not a second record: it is built from the
 * same walk through the state as getReadiness(), getRecordedRounds(),
 * getStandings() and getOutcome(), so it agrees with them, and it is never
 * stored (it has no toArray()). Reading it throws nothing for a state that
 * can be read at all: a round that awaits results, a tie that awaits its
 * decider, withdrawals and void ties are all shown, not refused.
 *
 * Built by the elimination engines (BracketViewEngine::getBracket()).
 *
 * @experimental
 */
final readonly class Bracket
{
    /** @var list<BracketRound> */
    private array $rounds;

    /**
     * @param array<BracketRound> $rounds Every round of the bracket, in the order they are played
     * @param bool $complete Whether the bracket is complete (StageEngineInterface::isComplete())
     */
    public function __construct(array $rounds, private bool $complete)
    {
        $this->rounds = array_values($rounds);
    }

    /**
     * Every round of the bracket in the order they are played: for single
     * elimination the rounds of the main side; for double elimination
     * winners round 1, losers round 1, then each later winners round
     * followed by the losers rounds it feeds, the grand final, and its
     * reset where the engine plays one.
     *
     * @return list<BracketRound>
     */
    public function getRounds(): array
    {
        return $this->rounds;
    }

    /**
     * The rounds of one side, in structural order.
     *
     * @return list<BracketRound>
     */
    public function getRoundsOn(BracketSide $side): array
    {
        return array_values(array_filter(
            $this->rounds,
            static fn(BracketRound $round): bool => $round->getSide() === $side
        ));
    }

    /**
     * The sides the bracket has, in the order their first rounds are
     * played: Main for single elimination; Winners, Losers (where the
     * field is larger than 2) and GrandFinal for double elimination.
     *
     * @return list<BracketSide>
     */
    public function getSides(): array
    {
        $sides = [];
        foreach ($this->rounds as $round) {
            if (!in_array($round->getSide(), $sides, true)) {
                $sides[] = $round->getSide();
            }
        }

        return $sides;
    }

    /**
     * Every node of the bracket, round by round in the order of
     * getRounds().
     *
     * @return list<BracketNode>
     */
    public function getNodes(): array
    {
        $nodes = [];
        foreach ($this->rounds as $round) {
            foreach ($round->getNodes() as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The node with the given ID (BracketNode::getId(), and what
     * BracketSlot::getSourceNodes() names), or null where the bracket has
     * none.
     */
    public function getNode(string $id): ?BracketNode
    {
        foreach ($this->rounds as $round) {
            foreach ($round->getNodes() as $node) {
                if ($node->getId() === $id) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * The round the engine pairs next, or null where the stage awaits
     * results or is complete.
     */
    public function getNextRound(): ?BracketRound
    {
        foreach ($this->rounds as $round) {
            if ($round->isNext()) {
                return $round;
            }
        }

        return null;
    }

    /**
     * Whether the bracket is complete: what the engine's isComplete() says
     * of the same state.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }
}
