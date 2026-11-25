<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * The role extractor of the factories of ConsecutiveRoleConstraint: the
 * participant's position in the event's participant list, found by its ID,
 * either as that index (position()) or as `home` for the first-named and
 * `away` for any other (homeAway()). False for an event that does not hold
 * the participant.
 *
 * It is a class of its own, and not a closure, so that a constraint built
 * by a factory can be told apart from one given a callable of the caller's
 * (ConsecutiveRoleConstraint::extractsRolesByPosition()): this one keeps no
 * state and reads nothing but its two arguments.
 *
 * @internal Not public API.
 */
final readonly class PositionRole
{
    /**
     * @param bool $homeAway Whether the role is `home` or `away`, rather than the index
     */
    public function __construct(private bool $homeAway) {}

    public function __invoke(Event $event, Participant $participant): int|string|false
    {
        $position = false;
        foreach (array_values($event->getParticipants()) as $index => $eventParticipant) {
            if ($eventParticipant->getId() === $participant->getId()) {
                $position = $index;

                break;
            }
        }

        if (!$this->homeAway) {
            return $position;
        }

        return $position === 0 ? 'home' : 'away';
    }
}
