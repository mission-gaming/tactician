<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents top-seeded participants from meeting each other early in the tournament.
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
        $participants = $event->getParticipants();
        $currentRound = $event->getRound()?->getNumber() ?? 0;
        $totalRounds = $this->estimateTotalRounds($context);

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

    /**
     * Estimate total rounds in the tournament.
     * This is a rough estimate based on current context.
     */
    private function estimateTotalRounds(SchedulingContext $context): int
    {
        $configuredTotalRounds = $context->getTotalRounds();
        if ($configuredTotalRounds > 0) {
            return $configuredTotalRounds;
        }

        $participantCount = count($context->getParticipants());
        if ($participantCount < 2) {
            return 1;
        }

        $roundsPerLeg = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;

        return $roundsPerLeg * $context->getTotalLegs();
    }
}
