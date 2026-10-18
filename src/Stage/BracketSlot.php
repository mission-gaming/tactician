<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * One slot of a node of a bracket (see Bracket): the entrant who stands in
 * it, or what stands in its place, and where it is filled from.
 *
 * A slot holds an entrant (getParticipant()) or a placeholder
 * (getPlaceholder()), never both and never neither. A placeholder says
 * whether the slot is still to be filled, and from what: the entrant who
 * goes on from a node (AdvancerOf), the one who does not (LoserOf), one of
 * the entrants a re-seeded round places (Reseeded); or why it will hold
 * nobody: a bye, a void node before it, a node that is not played.
 *
 * The source (getSource(), getSourceNodes()) is the bracket's structure and
 * is there whether or not the slot is filled, so a renderer can draw the
 * lines between the nodes from it.
 *
 * Built by the elimination engines (BracketViewEngine::getBracket()).
 *
 * @experimental
 */
final readonly class BracketSlot
{
    /** @var list<string> */
    private array $sourceNodes;

    /**
     * @param array<string> $sourceNodes The IDs of the nodes the slot is filled from: one for
     *                                   AdvancerOf and LoserOf, every node of the round before
     *                                   for a re-seeded slot, none for Draw and Recorded
     * @param int|null $drawPosition The 1-based position in the entrant list that the entry
     *                               fold places in the slot (Draw only); a position beyond the
     *                               field is a bye
     *
     * @throws InvalidInputException When the slot has both an entrant and a placeholder, or
     *                                  neither
     */
    public function __construct(
        private ?Participant $participant,
        private ?SlotPlaceholder $placeholder,
        private SlotSource $source,
        array $sourceNodes = [],
        private bool $reseeded = false,
        private ?int $drawPosition = null,
        private bool $withdrawn = false
    ) {
        if (($participant === null) === ($placeholder === null)) {
            throw new InvalidInputException('A bracket slot holds an entrant or a placeholder: exactly one of the two.');
        }

        $this->sourceNodes = array_values($sourceNodes);
    }

    /**
     * The entrant who stands in the slot, or null where a placeholder does.
     */
    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * What stands in the slot where no entrant does, or null where one
     * does.
     */
    public function getPlaceholder(): ?SlotPlaceholder
    {
        return $this->placeholder;
    }

    /**
     * Whether the slot is still to be filled: its placeholder is pending.
     */
    public function isPending(): bool
    {
        return $this->placeholder?->isPending() ?? false;
    }

    /**
     * Where the slot is filled from.
     */
    public function getSource(): SlotSource
    {
        return $this->source;
    }

    /**
     * The IDs of the nodes the slot is filled from (BracketNode::getId(),
     * Bracket::getNode()): one for AdvancerOf and LoserOf, every node of
     * the round before for a slot of a re-seeded round (isReseeded()), and
     * none for Draw and Recorded.
     *
     * @return list<string>
     */
    public function getSourceNodes(): array
    {
        return $this->sourceNodes;
    }

    /**
     * Whether the slot is placed by re-seeding: the entrants who go on from
     * the round before are ranked and folded again, so which of them stands
     * here is known only once that round is settled.
     */
    public function isReseeded(): bool
    {
        return $this->reseeded;
    }

    /**
     * The 1-based position in the entrant list that the entry fold places
     * in the slot, for a slot of the first round (source Draw), or null. A
     * position beyond the field is a bye: the slot holds nobody.
     */
    public function getDrawPosition(): ?int
    {
        return $this->drawPosition;
    }

    /**
     * Whether the entrant in the slot has withdrawn from the stage, as the
     * bracket reads it: a withdrawal after the bracket was over is not
     * read (see Withdrawal). False for a slot without an entrant.
     */
    public function isWithdrawn(): bool
    {
        return $this->withdrawn;
    }
}
