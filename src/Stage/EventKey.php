<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * The one place that tells one recorded event from another: by its round
 * number, its participants in either order (PairKey) and its tie leg
 * (FormatMetadataKey::TIE_LEG, 1 when the event carries none, and 1 when
 * it carries one that is no integer, a string such as '2' included).
 *
 * A result names its event by value, and object identity does not survive
 * plain data, so a stage state, the engines that read it and the selectors
 * that read an outcome match an event to its result, and to a mention of
 * it elsewhere, by this key. They have to agree on it: an event that two
 * of them keyed differently would have a result to one and none to the
 * other. An event without a round number is keyed as round 0, which no
 * event with one has; a caller that must not match such an event refuses
 * it first.
 *
 * @internal Not public API: the format of a key carries no compatibility
 *           guarantee, and no key is stored or serialized.
 */
final class EventKey
{
    /**
     * The key of the event.
     */
    public static function of(Event $event): string
    {
        $leg = $event->getMetadataValue(FormatMetadataKey::TIE_LEG);

        return ($event->getRound()?->getNumber() ?? 0) . ':' . PairKey::of(...array_map(
            static fn(Participant $participant): string => $participant->getId(),
            array_values($event->getParticipants())
        )) . ':' . (is_int($leg) ? $leg : 1);
    }
}
