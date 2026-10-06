<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use JsonException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Standings\Standings;

/**
 * Canonical text renderings for the golden-output fixtures.
 *
 * Every rendering is built from integers, participant ids and enum
 * values with explicit separators, so the text is the same on every PHP
 * version, locale and default timezone. The renderings preserve
 * generated order (events within a round, rounds within a schedule,
 * assignments within an outcome): order is part of the output being
 * pinned.
 */
final class GoldenText
{
    /**
     * A schedule as a `meta:` line carrying the schedule metadata, then
     * one `R<round>: a-b c-d` line per run of events sharing a round, in
     * event order with the first-named participant first, followed by a
     * `byes:` line when the schedule metadata records byes.
     *
     * The `meta:` line lists every metadata key in generated order as
     * `key=value`, apart from a non-empty `byes` map, which gets its own
     * line. Metadata is part of the generated schedule and of its wire
     * shape, so it is pinned with the events.
     *
     * Lines are run-length grouped rather than sorted, so events emitted
     * out of round order would show up as a repeated round line instead
     * of being silently regrouped.
     *
     * @return list<string>
     *
     * @throws JsonException
     */
    public static function schedule(Schedule $schedule): array
    {
        $byes = $schedule->getMetadataValue('byes');
        $hasByes = is_array($byes) && $byes !== [];

        $metadata = $schedule->getMetadata();
        if ($hasByes) {
            unset($metadata['byes']);
        }
        $lines = [rtrim('meta: ' . self::fields($metadata))];
        $currentRound = null;
        $currentEvents = [];

        foreach ($schedule->getEvents() as $event) {
            $round = $event->getRound()?->getNumber();
            if ($currentEvents !== [] && $round !== $currentRound) {
                $lines[] = self::roundLabel($currentRound) . ': ' . implode(' ', $currentEvents);
                $currentEvents = [];
            }
            $currentRound = $round;
            $currentEvents[] = self::event($event);
        }
        if ($currentEvents !== []) {
            $lines[] = self::roundLabel($currentRound) . ': ' . implode(' ', $currentEvents);
        }

        if ($hasByes) {
            $parts = [];
            foreach ($byes as $round => $participantId) {
                $parts[] = 'R' . $round . '=' . self::scalar($participantId);
            }
            $lines[] = 'byes: ' . implode(' ', $parts);
        }

        return $lines;
    }

    /**
     * One engine round as `R<round> [<label>]: a-b c-d | byes: e f`.
     * The label is omitted for rounds without one, the byes for rounds
     * that award none.
     *
     * @throws JsonException
     */
    public static function pairing(RoundPairing $pairing): string
    {
        $line = 'R' . $pairing->getRoundNumber();
        if ($pairing->getLabel() !== null) {
            $line .= ' [' . $pairing->getLabel() . ']';
        }
        $line .= ': ' . implode(' ', array_map(self::event(...), $pairing->getEvents()));

        if ($pairing->hasByes()) {
            $line .= ' | byes: ' . implode(' ', array_map(
                static fn (Participant $participant): string => $participant->getId(),
                $pairing->getByes()
            ));
        }

        return $line;
    }

    /**
     * Final standings as `standings: id(wins-losses) ...` in ranked order.
     */
    public static function standings(Standings $standings): string
    {
        $parts = [];
        foreach ($standings->getEntries() as $entry) {
            $parts[] = $entry->getParticipant()->getId()
                . '(' . $entry->getWins() . '-' . $entry->getLosses() . ')';
        }

        return 'standings: ' . implode(' ', $parts);
    }

    /**
     * A repack outcome: every assignment in outcome order as
     * `<event id> => session <s>, slot <t>, kickoff <UTC instant>`, then
     * the unplaced events with their reason, then every violation as its
     * kind followed by its fields.
     *
     * @return list<string>
     *
     * @throws JsonException
     */
    public static function repackOutcome(RepackOutcome $outcome): array
    {
        $lines = ['assignments: ' . count($outcome->getAssignments())];
        foreach ($outcome->getAssignments() as $assignment) {
            $data = $assignment->toArray();
            $lines[] = '  ' . $data['event_id']
                . ' => session ' . $data['session']
                . ', slot ' . $data['slot']
                . ', kickoff ' . $data['kickoff'];
        }

        $lines[] = 'unplaced: ' . count($outcome->getUnplaced());
        foreach ($outcome->getUnplaced() as $unplaced) {
            $data = $unplaced->toArray();
            unset($data['event_id']);
            $lines[] = '  ' . $unplaced->getEventId() . ' => ' . self::fields($data);
        }

        $lines[] = 'violations: ' . count($outcome->getViolations());
        foreach ($outcome->getViolations() as $violation) {
            $data = $violation->toArray();
            unset($data['kind']);
            $lines[] = '  ' . $violation->getKind()->value . ' ' . self::fields($data);
        }

        return $lines;
    }

    /**
     * Assemble a fixture file: a `#` comment header, then one
     * `== title ==` section per entry, separated by blank lines.
     *
     * @param list<string> $header
     * @param array<string, list<string>> $sections Section title => lines
     */
    public static function document(array $header, array $sections): string
    {
        $blocks = [implode("\n", array_map(
            static fn (string $line): string => rtrim('# ' . $line),
            $header
        ))];

        foreach ($sections as $title => $lines) {
            $blocks[] = implode("\n", ['== ' . $title . ' ==', ...$lines]);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * An event as its participant ids joined by `-`, first-named first.
     * Event metadata (for example the `tie_leg` of a two-legged tie) is
     * appended as compact JSON when present.
     *
     * @throws JsonException
     */
    public static function event(Event $event): string
    {
        $text = implode('-', array_map(
            static fn (Participant $participant): string => $participant->getId(),
            $event->getParticipants()
        ));

        if ($event->getMetadata() !== []) {
            $text .= json_encode($event->getMetadata(), JSON_THROW_ON_ERROR);
        }

        return $text;
    }

    private static function roundLabel(?int $round): string
    {
        return $round === null ? 'R?' : 'R' . $round;
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @throws JsonException
     */
    private static function fields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name . '=' . self::scalar($value);
        }

        return implode(' ', $parts);
    }

    /**
     * @throws JsonException
     */
    private static function scalar(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
