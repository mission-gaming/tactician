<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents top-seeded participants from meeting each other early in the tournament.
 *
 * The protection window is a fraction of the stage's total rounds, read
 * from the stage plan. When the plan cannot know its total rounds up
 * front (getTotalRounds() returns null), no window can be computed and
 * the constraint is satisfied — protection is effectively off for such
 * stages rather than guessed from a fabricated round count.
 */
readonly class SeedProtectionConstraint implements ConstraintInterface
{
    public function __construct(
        private int $topSeedsToProtect,
        private float $protectionPeriod
    ) {
        if ($topSeedsToProtect < 1) {
            throw new \InvalidArgumentException('Must protect at least 1 seed');
        }
        if ($protectionPeriod < 0.0 || $protectionPeriod > 1.0) {
            throw new \InvalidArgumentException('Protection period must be between 0.0 and 1.0');
        }
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $totalRounds = $context->getPlan()->getTotalRounds();
        if ($totalRounds === null) {
            return true; // No knowable stage length, so no protection window
        }

        $participants = $event->getParticipants();
        $currentRound = $event->getRound()?->getNumber() ?? 0;

        $protectedRound = (int) ($totalRounds * $this->protectionPeriod);

        if ($currentRound > $protectedRound) {
            return true; // Protection period ended
        }

        $topSeeds = $this->getTopSeeds($context->getParticipants());
        $eventTopSeeds = array_filter($participants, fn ($p) => in_array($p, $topSeeds, true));

        return count($eventTopSeeds) <= 1; // Max 1 top seed per event during protection
    }

    #[\Override]
    public function getName(): string
    {
        return "Seed Protection (top {$this->topSeedsToProtect}, {$this->protectionPeriod}% period)";
    }

    /**
     * Get the top seeds from participants.
     *
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function getTopSeeds(array $participants): array
    {
        // Sort by seed (lower number = better seed)
        $seededParticipants = array_filter($participants, fn ($p) => $p->getSeed() !== null);
        usort($seededParticipants, fn ($a, $b) => $a->getSeed() <=> $b->getSeed());

        return array_slice($seededParticipants, 0, $this->topSeedsToProtect);
    }
}
