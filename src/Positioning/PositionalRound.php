<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Ordering\ParticipantOrderer;
use MissionGaming\Tactician\Ordering\StaticParticipantOrderer;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Represents a single round of a tournament in positional terms.
 *
 * A positional round contains a collection of positional pairings,
 * defining the structure of a round without specifying actual participants.
 * This enables round structures to be defined and inspected before
 * participants are assigned.
 */
readonly class PositionalRound
{
    /**
     * @param int $roundNumber The round number (1-based)
     * @param array<PositionalPairing> $pairings The positional pairings for this round
     */
    public function __construct(
        private int $roundNumber,
        private array $pairings
    ) {
        if ($roundNumber < 1) {
            throw new \InvalidArgumentException('Round number must be at least 1');
        }
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * @return array<PositionalPairing>
     */
    public function getPairings(): array
    {
        return $this->pairings;
    }

    /**
     * Get the number of pairings in this round.
     */
    public function getPairingCount(): int
    {
        return count($this->pairings);
    }

    /**
     * Resolve this round's positional pairings to actual events.
     *
     * @param PositionResolver $resolver The resolver to use for position lookup
     * @param ParticipantOrderer|null $orderer The orderer to apply to participants (uses StaticParticipantOrderer if null)
     * @param SchedulingContext|null $context The scheduling context for the orderer (required if orderer provided)
     * @param int|null $leg The leg number for multi-leg tournaments (null for single-leg)
     * @return array<Event> Resolved events for this round
     */
    public function resolve(
        PositionResolver $resolver,
        ?ParticipantOrderer $orderer = null,
        ?SchedulingContext $context = null,
        ?int $leg = null
    ): array {
        $events = [];
        $roundObject = new Round($this->roundNumber);
        $orderer ??= new StaticParticipantOrderer();

        foreach ($this->pairings as $eventIndex => $pairing) {
            $participants = $pairing->resolve($resolver);

            if ($participants !== null) {
                // Apply participant ordering if context provided
                if ($context !== null) {
                    $orderingContext = new EventOrderingContext(
                        $this->roundNumber,
                        $eventIndex,
                        $leg,
                        $context
                    );
                    $participants = $orderer->order($participants, $orderingContext);
                }

                $events[] = new Event($participants, $roundObject);
            }
        }

        return $events;
    }

    /**
     * Check if all pairings in this round can be resolved with the given resolver.
     */
    public function canFullyResolve(PositionResolver $resolver): bool
    {
        foreach ($this->pairings as $pairing) {
            if (!$pairing->canResolve($resolver)) {
                return false;
            }
        }

        return true;
    }
}
