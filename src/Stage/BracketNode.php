<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * One node of a bracket (see Bracket): a tie at its place in the bracket,
 * with its slots, where it stands, and who went on from it.
 *
 * A node is identified by its side, its structural round and its position
 * in that round, which its ID puts together as
 * `<side>:<round>:<position>` (`main:2:1`, `losers:3:2`,
 * `grand-final:2:1`). A slot of a later node names the node it is filled
 * from by that ID.
 *
 * A round has a node for every pair of slots, also where the
 * pair holds one entrant (status Bye) or nobody (Empty), so a round of a
 * bracket of 8 slots always has 4 nodes. A single elimination bracket with
 * a third-place tie has one node more in its last round, after the final,
 * whose kind is BracketTie::ThirdPlace.
 *
 * Built by the elimination engines (BracketViewEngine::getBracket()).
 *
 * @experimental
 */
final readonly class BracketNode
{
    /** @var list<BracketSlot> */
    private array $slots;

    /** @var list<Participant> */
    private array $losers;

    /** @var list<Event> */
    private array $events;

    /**
     * @param int $round The structural round of the node on its side, 1-based
     * @param int $position The position of the node in its round, 1-based, top of the bracket first
     * @param BracketTie|null $kind Which tie of a last round the node is, where it has a kind
     * @param array<BracketSlot> $slots The slots of the node in bracket order: two, or one where a
     *                                  round drawn by hand left a pair of slots split
     * @param Participant|null $advancer The entrant who went on from the node, or null
     * @param array<Participant> $losers The entrants of the node who did not go on
     * @param array<Event> $events The recorded events of the node, in leg order
     * @param int|null $roundNumber The number of the recorded round the node was played in
     */
    public function __construct(
        private BracketSide $side,
        private int $round,
        private int $position,
        private ?BracketTie $kind,
        array $slots,
        private BracketNodeStatus $status,
        private ?Participant $advancer = null,
        array $losers = [],
        array $events = [],
        private ?int $roundNumber = null
    ) {
        $this->slots = array_values($slots);
        $this->losers = array_values($losers);
        $this->events = array_values($events);
    }

    /**
     * The ID of the node, `<side>:<round>:<position>`, unique in its
     * bracket: what a slot of a later node names it by.
     */
    public function getId(): string
    {
        return self::idOf($this->side, $this->round, $this->position);
    }

    /**
     * The ID a node at the given place has (see getId()).
     */
    public static function idOf(BracketSide $side, int $round, int $position): string
    {
        return $side->value . ':' . $round . ':' . $position;
    }

    /**
     * The side of the bracket the node is on.
     */
    public function getSide(): BracketSide
    {
        return $this->side;
    }

    /**
     * The structural round of the node on its side, 1-based: its round in
     * the shape of the bracket, whether or not it was played, and not the
     * number of a recorded round (getRoundNumber()).
     */
    public function getRound(): int
    {
        return $this->round;
    }

    /**
     * The position of the node in its round, 1-based, top of the bracket
     * first.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Which tie of the last round of a single elimination bracket the node
     * is: BracketTie::Final for the final, BracketTie::ThirdPlace for the
     * third-place tie. Null for every other node, the grand final of a
     * double elimination bracket included.
     */
    public function getKind(): ?BracketTie
    {
        return $this->kind;
    }

    /**
     * The slots of the node in bracket order, top first: two, or one where
     * a round drawn by hand left a pair of slots split.
     *
     * @return list<BracketSlot>
     */
    public function getSlots(): array
    {
        return $this->slots;
    }

    /**
     * The entrants who stand in the slots of the node, in slot order.
     *
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        $participants = [];
        foreach ($this->slots as $slot) {
            $participant = $slot->getParticipant();
            if ($participant instanceof Participant) {
                $participants[] = $participant;
            }
        }

        return $participants;
    }

    /**
     * Where the node stands.
     */
    public function getStatus(): BracketNodeStatus
    {
        return $this->status;
    }

    /**
     * The entrant who went on from the node, or null while it is not
     * settled and where nobody went on.
     */
    public function getAdvancer(): ?Participant
    {
        return $this->advancer;
    }

    /**
     * The entrants of the node who did not go on, in slot order: one where
     * the node was decided or is uncontested, both of a void node, none
     * while it is not settled and for a bye. In a double elimination
     * winners bracket the one who did not go on drops to the losers
     * bracket.
     *
     * @return list<Participant>
     */
    public function getLosers(): array
    {
        return $this->losers;
    }

    /**
     * The recorded events of the node, in leg order: none for a node that
     * is not recorded, and none for a pair of slots a recorded round holds
     * no event for (a bye, an uncontested tie the round paired without its
     * event).
     *
     * @return list<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * The number of the recorded round the node was read in, or null for
     * a node of a round that is not recorded.
     */
    public function getRoundNumber(): ?int
    {
        return $this->roundNumber;
    }
}
