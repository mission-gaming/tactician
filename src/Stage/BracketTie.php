<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;

/**
 * Which tie of a bracket's last round an event belongs to, where that
 * round holds more than one.
 *
 * A single elimination bracket with a third-place tie
 * (EliminationOptions::$thirdPlace) ends on a round of two ties: the one
 * that decides the first two places and the one that decides the next two.
 * Both are in one round and carry one round label, so each of their events
 * says which tie it is, as the backing string of a case under
 * METADATA_KEY in the event's metadata. The metadata is plain data and is
 * stored with the event; of() reads it back as a case.
 *
 * The engine marks the events of the last round of a bracket that was
 * given the option, and no others: an event of an earlier round, and every
 * event of a bracket without the option, carries no mark. Both legs of a
 * two-legged tie carry the mark of their tie.
 *
 * The backing strings are stable identifiers for storage. New cases may be
 * added in a later release, so a `match` over a case needs a `default`
 * arm.
 *
 * @experimental
 */
enum BracketTie: string
{
    /** The tie between the two entrants still in the bracket: its winner is placed first, the other second. */
    case Final = 'final';

    /** The tie between the two entrants who went out in the round before the last: its winner is placed third, the other fourth. */
    case ThirdPlace = 'third-place';

    /**
     * The key of an event's metadata under which the engine writes the
     * backing string of a case.
     */
    public const string METADATA_KEY = 'bracket_tie';

    /**
     * The tie an event is marked as belonging to, or null when it carries
     * no mark or a value that is not the backing string of a case.
     *
     * Nothing but the event's metadata is read: the round label and the
     * participants are not consulted.
     */
    public static function of(Event $event): ?self
    {
        $value = $event->getMetadataValue(self::METADATA_KEY);

        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * The place in the bracket's table of the entrant who advances from
     * the tie: 1 for the final, 3 for the third-place tie. The other
     * entrant of the tie has the place below it.
     */
    public function winnerRank(): int
    {
        return match ($this) {
            self::Final => 1,
            self::ThirdPlace => 3,
        };
    }
}
