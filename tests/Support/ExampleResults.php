<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use DateTimeInterface;
use JsonException;
use LogicException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Measured;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use Throwable;

/**
 * The results the scripts in examples/ compute, read without displaying
 * them, and their canonical text for the golden fixtures.
 *
 * An example ends with `return Example::present(__FILE__, ...)`, which returns its
 * named results and displays nothing when the script is included. of()
 * includes a script once per process and hands those results back, so a
 * test can assert on the objects the example built. text() writes the same
 * results in the form the other golden fixtures use (GoldenText): ids, not
 * labels, with explicit separators. How an example is displayed, as a page
 * or as command-line text, plays no part in either.
 */
final class ExampleResults
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    /**
     * Every example, by file name without the extension, in order. An
     * example is a script in examples/ whose name starts with its two-digit
     * number; index.php is a page of links and computes nothing.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map(
            static fn(string $script): string => basename($script, '.php'),
            glob(self::directory() . '/[0-9][0-9]-*.php') ?: []
        );
        sort($names);

        return $names;
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/examples';
    }

    /**
     * The named results an example hands to Example::present().
     *
     * @return array<string, mixed>
     *
     * @throws LogicException When the script displays something while included, or does not return its results
     */
    public static function of(string $example): array
    {
        return self::$loaded[$example] ??= self::read(self::directory() . '/' . $example . '.php');
    }

    /**
     * Include a script and return the named results it hands over, without
     * keeping them. This is what refuses a script that is not built as an
     * example: one that displays something itself, or that does not end by
     * returning at least one named result.
     *
     * @param string $script The path of the script
     * @return array<string, mixed>
     *
     * @throws LogicException When the script is missing, displays something while included, or does not return its results
     */
    public static function read(string $script): array
    {
        $name = basename(dirname($script)) . '/' . basename($script);
        if (!is_file($script)) {
            throw new LogicException("{$name} does not exist.");
        }

        // A scope of its own, so no variable of one example reaches the next
        $include = static fn(string $path): mixed => require $path;

        ob_start();
        try {
            $results = $include($script);
        } finally {
            $displayed = (string) ob_get_clean();
        }

        if ($displayed !== '') {
            throw new LogicException(
                "{$name} displayed something while it was included. An example computes its "
                . 'results and hands them to Example::present(), which only displays them when the script is run directly.'
            );
        }

        if (!is_array($results) || $results === [] || array_is_list($results)) {
            throw new LogicException(
                "{$name} must end with `return Example::present(__FILE__, \$title, \$summary, [...])` "
                . 'and hand over at least one named result.'
            );
        }

        $named = [];
        foreach ($results as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /**
     * The fixture text for an example: one section per named result, in the
     * order the example presents them.
     *
     * @throws JsonException
     * @throws LogicException When the script cannot be read as an example
     */
    public static function text(string $example): string
    {
        $sections = [];
        foreach (self::of($example) as $name => $value) {
            $sections[$name] = self::lines($value);
        }

        return GoldenText::document([
            "The results of examples/{$example}.php, one section per named result.",
            'Schedules, pairings and repack outcomes are written as in the other',
            'golden files; a played event is `R<round>: a-b => winner`, a standings',
            'row lists its counts, ranking value and tiebreaker values, and a value',
            'measured while the example runs is written as its unit and the reason',
            'it is not pinned.',
            '',
            'A difference from this file means the example now computes something',
            'else, because the library changed or because the script was edited.',
            'Review it, then regenerate with `composer golden-update`.',
        ], $sections);
    }

    /**
     * Whether a result, or anything inside it, is a measured value.
     */
    public static function containsMeasured(mixed $value): bool
    {
        if ($value instanceof Measured) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsMeasured($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One result as fixture lines.
     *
     * @return list<string>
     *
     * @throws JsonException
     * @throws LogicException When a result is of a kind the fixtures cannot write
     */
    public static function lines(mixed $value): array
    {
        if ($value instanceof Schedule) {
            return GoldenText::schedule($value);
        }

        if ($value instanceof RepackOutcome) {
            return GoldenText::repackOutcome($value);
        }

        if ($value instanceof ScheduledSchedule) {
            $lines = [];
            foreach ($value->getScheduledEvents() as $scheduled) {
                $lines[] = self::round($scheduled->getEvent()) . ': ' . GoldenText::event($scheduled->getEvent())
                    . ' @ ' . self::instant($scheduled->getKickoff())
                    . ($scheduled->getResource() === null ? '' : ' on ' . $scheduled->getResource());
            }

            return $lines === [] ? ['(no events)'] : $lines;
        }

        if ($value instanceof Standings) {
            $lines = [];
            foreach ($value->getEntries() as $position => $entry) {
                $line = ($position + 1) . '. ' . $entry->getParticipant()->getId()
                    . ' played=' . $entry->getPlayed()
                    . ' wins=' . $entry->getWins()
                    . ' draws=' . $entry->getDraws()
                    . ' losses=' . $entry->getLosses()
                    . ' value=' . self::number($entry->getRankingValue())
                    . ' for=' . self::number($entry->getScoreFor())
                    . ' against=' . self::number($entry->getScoreAgainst());
                foreach ($entry->getTiebreakers() as $name => $tiebreaker) {
                    $line .= ' ' . $name . '=' . self::number($tiebreaker);
                }
                $lines[] = $line;
            }

            return $lines === [] ? ['(no entries)'] : $lines;
        }

        if (is_array($value)) {
            if ($value === []) {
                return ['(none)'];
            }

            $lines = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $itemLines = self::lines($item);
                $prefix = $isList ? '-' : $key . ':';
                if (count($itemLines) === 1 && !is_array($item)) {
                    $lines[] = $prefix . ' ' . $itemLines[0];

                    continue;
                }
                $lines[] = $prefix;
                foreach ($itemLines as $line) {
                    $lines[] = rtrim('  ' . $line);
                }
            }

            return $lines;
        }

        return explode("\n", self::scalar($value));
    }

    /**
     * @throws JsonException
     * @throws LogicException When the value is of a kind the fixtures cannot write
     */
    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            is_string($value) => $value,
            $value instanceof Measured => 'measured (' . $value->unit . '): ' . $value->reason,
            $value instanceof RoundPairing => GoldenText::pairing($value),
            $value instanceof Event => self::round($value) . ': ' . GoldenText::event($value),
            $value instanceof Result => self::result($value),
            $value instanceof Participant => self::participant($value),
            $value instanceof DateTimeInterface => self::instant($value),
            $value instanceof Throwable => $value::class . ': ' . $value->getMessage(),
            default => throw new LogicException(
                'An example result of type ' . get_debug_type($value) . ' has no fixture form: add one to '
                . self::class . '::scalar().'
            ),
        };
    }

    /**
     * @throws JsonException
     */
    private static function result(Result $result): string
    {
        $event = $result->getEvent();
        $line = self::round($event) . ': ' . GoldenText::event($event)
            . ' => ' . ($result->getWinner()?->getId() ?? 'draw');

        $scores = [];
        foreach ($event->getParticipants() as $participant) {
            $score = $result->getScoreFor($participant);
            if ($score !== null) {
                $scores[] = self::number($score);
            }
        }

        return $scores === [] ? $line : $line . ' (' . implode('-', $scores) . ')';
    }

    /**
     * @throws JsonException
     */
    private static function participant(Participant $participant): string
    {
        $line = $participant->getId() . ' "' . $participant->getLabel() . '"';
        if ($participant->getSeed() !== null) {
            $line .= ' seed=' . $participant->getSeed();
        }
        if ($participant->getMetadata() !== []) {
            $line .= ' ' . json_encode($participant->getMetadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $line;
    }

    private static function round(Event $event): string
    {
        $round = $event->getRound()?->getNumber();

        return $round === null ? 'R?' : 'R' . $round;
    }

    /**
     * An instant in UTC, whatever zone it carries and whatever the default
     * timezone is.
     */
    private static function instant(DateTimeInterface $instant): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $instant->getTimestamp());
    }

    /**
     * A number with a fixed decimal separator and at most six decimals,
     * whatever the locale and the precision settings.
     */
    private static function number(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        $text = sprintf('%.6F', $number);

        return rtrim(rtrim($text, '0'), '.');
    }
}
