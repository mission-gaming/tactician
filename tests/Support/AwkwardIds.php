<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Participant ids that are legal and that a pairing map is likely to get
 * wrong, for the sweeps over whole tournaments.
 *
 * An id is any string, so the generators and engines must treat it as a
 * name and nothing else. Each set below is six ids that differ as strings
 * and collide under some other reading: as numbers, around the `|` that
 * joins a pair key, around the `:` of a result key, around the `\` that
 * escapes them, or as text a careless check calls empty.
 */
final class AwkwardIds
{
    /**
     * @return array<string, list<string>> Six ids per set, keyed by what the set is about
     */
    public static function sets(): array
    {
        return [
            'ordinary ids' => ['a', 'b', 'c', 'd', 'e', 'f'],
            'plain decimals' => ['8', '9', '10', '11', '100', '2'],
            // PHP turns each of these into an integer array key, and the
            // keys 0 to 5 are the ones a renumbered list takes.
            'decimals from zero' => ['1', '2', '3', '4', '5', '0'],
            'leading zeros' => ['01', '1', '001', '2', '02', '3'],
            'exponent forms' => ['1e3', '1000', '1E3', '10e2', '0e1', '0e2'],
            'decimal forms of one' => ['1.0', '1', '1.', '+1', ' 1', '1 '],
            // In this order a bracket of four folds to "a v b|c" and
            // "a|b v c": two ties of one round whose ids join to one text.
            'the pair separator' => ['a', 'a|b', 'c', 'b|c', '|', '||'],
            'the result-key separator' => ['a', 'a:1', '1:a', ':', '1:a|b:1', 'b'],
            'the escape character' => ['a\\', '\\|b', 'a', '|b', '\\', '\\\\'],
            'empty-looking ids' => ['0', ' ', '00', "\t", '0.0', '-0'],
            'unicode ids' => ['é', 'e', "e\u{301}", '日本', '🏆', 'É'],
            'control characters' => ["\0bye", "\0", 'bye', "\n", "a\0", 'a'],
        ];
    }

    /**
     * The sets as a Pest dataset: one argument, the list of ids.
     *
     * @return array<string, array{list<string>}>
     */
    public static function dataset(): array
    {
        return array_map(fn(array $ids): array => [$ids], self::sets());
    }

    /**
     * The first $count ids of a set as participants, seeded in list order.
     * The label is the position, so a failure message stays readable.
     *
     * @param array<string> $ids
     * @return list<Participant>
     */
    public static function participants(array $ids, ?int $count = null): array
    {
        $participants = [];
        foreach (array_slice(array_values($ids), 0, $count) as $index => $id) {
            $participants[] = new Participant($id, 'P' . ($index + 1), $index + 1);
        }

        return $participants;
    }

    /**
     * How often each unordered pairing occurs, keyed by the JSON list of
     * its ids in byte order: a key that no two different pairings share,
     * built without the library's own key helper.
     *
     * @param iterable<Event> $events
     * @return array<string, int>
     * @throws \JsonException When an id is not valid UTF-8
     */
    public static function pairingCounts(iterable $events): array
    {
        $counts = [];
        foreach ($events as $event) {
            $key = self::pairing($event);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The key pairingCounts() files an event under.
     *
     * @throws \JsonException When an id is not valid UTF-8
     */
    public static function pairing(Event $event): string
    {
        $ids = array_map(fn(Participant $participant) => $participant->getId(), $event->getParticipants());
        sort($ids, SORT_STRING);

        return json_encode($ids, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
