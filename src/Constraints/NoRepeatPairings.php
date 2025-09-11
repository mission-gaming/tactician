<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

readonly class NoRepeatPairings implements ConstraintInterface
{
    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();

        // For events with more than 2 participants, check all pairs
        for ($i = 0; $i < count($participants) - 1; ++$i) {
            for ($j = $i + 1; $j < count($participants); ++$j) {
                if ($context->haveParticipantsPlayed($participants[$i], $participants[$j])) {
                    return false;
                }
            }
        }

        return true;
    }

    #[Override]
    public function getName(): string
    {
        return 'No Repeat Pairings';
    }
}
