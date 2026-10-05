<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Examples;

use DateTimeInterface;
use InvalidArgumentException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use Throwable;

/*
 * The one place the examples turn results into something to look at.
 *
 * An example script is ordinary library usage from top to bottom. Its last
 * statement hands a named set of results to Example::present():
 *
 *     return Example::present(__FILE__, 'Title', 'What this shows.', [
 *         'Schedule' => $schedule,
 *         'Rounds' => 3,
 *     ]);
 *
 * It returns that set unchanged. It also displays it, but only when
 * the script is the one PHP was started with: as text on the command line
 * (`php examples/01-basic-round-robin.php`) and as an HTML page under a web
 * server (`php -S localhost:8000 -t examples`). A script that is included
 * from somewhere else, which is how the test suite reads the results,
 * displays nothing.
 *
 * A result may be a library object (Schedule, ScheduledSchedule,
 * RoundPairing, Result, Standings, RepackOutcome, Participant, Event), an
 * exception, a Measured value, a scalar, or an array of any of those: a list
 * of rows (arrays with the same string keys) is shown as a table, an array
 * with string keys as named parts, and any other list as a list.
 *
 * Nothing here is part of the library, and none of it ships in the dist
 * archive.
 */

/**
 * A value that is measured while the example runs and differs on every run,
 * such as a duration. The reason is mandatory: it is what tells a reader,
 * and the test suite, why the value is not pinned.
 */
final readonly class Measured
{
    /**
     * @throws InvalidArgumentException When no reason is given
     */
    public function __construct(
        public int|float $value,
        public string $unit,
        public string $reason
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A measured value needs the reason it cannot be the same on every run.');
        }
    }
}

/**
 * Displays an example's results: as text on the command line, as an HTML
 * page under a web server.
 */
final class Example
{
    /**
     * Hand an example's results over: display them when the script was run
     * directly, and return them either way.
     *
     * @param string $script The example's own path: pass __FILE__
     * @param string $title A short name for the example
     * @param string $summary One or two sentences saying what the example shows
     * @param array<string, mixed> $results The results, by name, in display order
     * @return array<string, mixed> The same results
     */
    public static function present(string $script, string $title, string $summary, array $results): array
    {
        // The first included file is the script PHP was started with (or, under
        // a web server, the script that was requested)
        if ((get_included_files()[0] ?? null) !== $script) {
            return $results;
        }

        if (PHP_SAPI === 'cli') {
            echo self::renderText($title, $summary, $results);

            return $results;
        }

        echo self::renderHtml($title, $summary, $results, basename($script), self::siblings($script), (string) file_get_contents($script));

        return $results;
    }

    /**
     * The command-line form: the title, the summary, then one `== name ==`
     * section per result.
     *
     * @param array<string, mixed> $results
     */
    public static function renderText(string $title, string $summary, array $results): string
    {
        $lines = [$title, str_repeat('=', self::width($title)), '', ...explode("\n", wordwrap($summary, 76)), ''];

        if ($results === []) {
            $lines[] = '(no results)';
            $lines[] = '';
        }

        foreach ($results as $name => $value) {
            $lines[] = '== ' . $name . ' ==';
            array_push($lines, ...self::textLines(self::describe($value), ''));
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }

    /**
     * The browser form: a complete HTML document. Every piece of text that
     * comes from a result is escaped.
     *
     * @param array<string, mixed> $results
     * @param string|null $file The example's file name, shown with the command that runs it
     * @param array{previous: ?string, next: ?string} $siblings File names of the neighbouring examples
     * @param string|null $source The example's source code, listed under the results
     */
    public static function renderHtml(
        string $title,
        string $summary,
        array $results,
        ?string $file = null,
        array $siblings = ['previous' => null, 'next' => null],
        ?string $source = null
    ): string {
        $body = '<p class="summary">' . self::escape($summary) . '</p>';

        if ($file !== null) {
            $body .= '<p class="run">Also runs on the command line: <code>php examples/' . self::escape($file) . '</code></p>';
        }

        if ($results === []) {
            $body .= '<p class="empty">(no results)</p>';
        }

        foreach ($results as $name => $value) {
            $body .= '<section><h2>' . self::escape((string) $name) . '</h2>' . self::htmlBlock(self::describe($value)) . '</section>';
        }

        if ($source !== null) {
            $body .= '<section><h2>The code that produced this page</h2><pre class="source"><code>'
                . self::escape($source) . '</code></pre></section>';
        }

        $links = ['<a href="index.php">All examples</a>'];
        if ($siblings['previous'] !== null) {
            $links[] = '<a href="' . self::escape($siblings['previous']) . '">Previous: ' . self::escape($siblings['previous']) . '</a>';
        }
        if ($siblings['next'] !== null) {
            $links[] = '<a href="' . self::escape($siblings['next']) . '">Next: ' . self::escape($siblings['next']) . '</a>';
        }

        return self::page($title, '<nav>' . implode(' ', $links) . '</nav>' . $body);
    }

    /**
     * The HTML document every example page and the index share.
     *
     * @param string $body Markup that is already escaped
     */
    public static function page(string $title, string $body): string
    {
        $style = <<<'CSS'
            body { font: 16px/1.5 system-ui, sans-serif; color: #1f2933; background: #f5f7fa; margin: 0; }
            main { max-width: 60rem; margin: 0 auto; padding: 1.5rem; }
            h1 { margin: 0 0 .5rem; }
            h2 { font-size: 1.15rem; margin: 0 0 .75rem; }
            h3 { font-size: 1rem; margin: 1rem 0 .25rem; }
            nav a { margin-right: 1rem; }
            section { background: #fff; border: 1px solid #d9e2ec; border-radius: .5rem; padding: 1rem 1.25rem; margin: 1rem 0; }
            table { border-collapse: collapse; }
            th, td { text-align: left; padding: .2rem 1rem .2rem 0; border-bottom: 1px solid #e4e7eb; }
            ul { margin: 0; padding-left: 1.25rem; }
            pre { overflow-x: auto; margin: 0; }
            code, pre { font: 13px/1.45 ui-monospace, monospace; }
            .summary { max-width: 46rem; }
            .run, .empty { color: #52606d; }
            CSS;

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
            . '<title>' . self::escape($title) . " - Tactician examples</title>\n"
            . "<style>\n" . $style . "\n</style>\n</head>\n<body>\n<main>\n"
            . '<h1>' . self::escape($title) . "</h1>\n" . $body . "\n</main>\n</body>\n</html>\n";
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Reduce a result to one of four display shapes, so that the text and the
     * HTML form only differ in how they draw a shape:
     *
     * - `['text', string]`
     * - `['list', list<string>]`
     * - `['table', list<string> $headers, list<list<string>> $rows]`
     * - `['parts', array<string, shape>]`
     *
     * @return array{0: 'text', 1: string}|array{0: 'list', 1: list<string>}|array{0: 'table', 1: list<string>, 2: list<list<string>>}|array{0: 'parts', 1: array<string, array<int, mixed>>}
     */
    private static function describe(mixed $value): array
    {
        if ($value instanceof Schedule) {
            $parts = [];
            foreach ($value->getEventsByRound() as $round => $events) {
                $parts['Round ' . $round] = ['list', array_map(self::eventText(...), array_values($events))];
            }

            return $parts === [] ? ['text', '(no events)'] : ['parts', $parts];
        }

        if ($value instanceof ScheduledSchedule) {
            $rows = [];
            foreach ($value->getScheduledEvents() as $scheduled) {
                $rows[] = [
                    (string) ($scheduled->getEvent()->getRound()?->getNumber() ?? '-'),
                    self::instant($scheduled->getKickoff()),
                    $scheduled->getResource() ?? '-',
                    self::eventText($scheduled->getEvent()),
                ];
            }

            return $rows === [] ? ['text', '(no events)'] : ['table', ['Round', 'Kickoff (UTC)', 'Resource', 'Event'], $rows];
        }

        if ($value instanceof RoundPairing) {
            $items = array_map(self::eventText(...), array_values($value->getEvents()));
            foreach ($value->getByes() as $participant) {
                $items[] = 'Bye: ' . $participant->getLabel();
            }

            return ['list', $items];
        }

        if ($value instanceof Standings) {
            $names = [];
            foreach ($value->getEntries() as $entry) {
                $names += $entry->getTiebreakers();
            }

            $rows = [];
            foreach ($value->getEntries() as $position => $entry) {
                $row = [
                    (string) ($position + 1),
                    $entry->getParticipant()->getLabel(),
                    (string) $entry->getPlayed(),
                    (string) $entry->getWins(),
                    (string) $entry->getDraws(),
                    (string) $entry->getLosses(),
                    self::scalar($entry->getRankingValue()),
                ];
                foreach (array_keys($names) as $name) {
                    $row[] = self::scalar($entry->getTiebreakerValue((string) $name));
                }
                $rows[] = $row;
            }

            return $rows === []
                ? ['text', '(no entries)']
                : ['table', ['#', 'Participant', 'Played', 'Won', 'Drawn', 'Lost', 'Ranking value', ...array_map(strval(...), array_keys($names))], $rows];
        }

        if ($value instanceof RepackOutcome) {
            $rows = [];
            foreach ($value->getAssignments() as $assignment) {
                $rows[] = [
                    $assignment->getEventId(),
                    (string) $assignment->getSession(),
                    (string) $assignment->getSlot(),
                    self::instant($assignment->getKickoff()),
                ];
            }

            $problems = [];
            foreach ($value->getUnplaced() as $unplaced) {
                $problems[] = 'Unplaced: ' . $unplaced->getEventId() . ' (' . $unplaced->getReason()->value . ')';
            }
            foreach ($value->getViolations() as $violation) {
                $problems[] = $violation->getKind()->value . ': ' . json_encode($violation->toArray());
            }

            return ['parts', [
                'Assignments' => $rows === [] ? ['text', '(none)'] : ['table', ['Event', 'Session', 'Slot', 'Kickoff (UTC)'], $rows],
                'Compromises' => $problems === [] ? ['text', 'None: every event placed, nobody double-booked.'] : ['list', $problems],
            ]];
        }

        if (!is_array($value)) {
            return ['text', self::scalar($value)];
        }

        if ($value === []) {
            return ['text', '(none)'];
        }

        if (self::isTable($value)) {
            $headers = array_map(strval(...), array_keys($value[0]));

            return ['table', $headers, array_map(
                static fn (array $row): array => array_map(self::scalar(...), array_values($row)),
                $value
            )];
        }

        $shapes = array_map(self::describe(...), $value);

        // An array of plain values is a list; with string keys, a list of `name: value`
        $texts = [];
        foreach ($shapes as $name => $shape) {
            if ($shape[0] !== 'text') {
                $texts = null;

                break;
            }
            $texts[] = array_is_list($value) ? $shape[1] : $name . ': ' . $shape[1];
        }
        if ($texts !== null) {
            return ['list', $texts];
        }

        $parts = [];
        foreach ($shapes as $name => $shape) {
            $parts[is_int($name) ? (string) ($name + 1) : $name] = $shape;
        }

        return ['parts', $parts];
    }

    /**
     * Whether an array is a list of rows: arrays of scalars sharing the same
     * string keys.
     *
     * @param array<array-key, mixed> $value
     * @phpstan-assert-if-true non-empty-list<array<string, bool|float|int|string|null>> $value
     */
    private static function isTable(array $value): bool
    {
        if (!array_is_list($value) || !is_array($value[0] ?? null) || array_is_list($value[0])) {
            return false;
        }

        $keys = array_keys($value[0]);
        foreach ($value as $row) {
            if (!is_array($row) || array_keys($row) !== $keys) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && !is_scalar($cell)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * One value as one line of text.
     */
    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value), is_string($value) => (string) $value,
            // A fixed number of decimals and a fixed separator, whatever the locale and precision settings
            is_float($value) => str_contains($text = number_format($value, 3, '.', ''), '.') ? rtrim(rtrim($text, '0'), '.') : $text,
            $value instanceof Measured => self::scalar($value->value) . ' ' . $value->unit . ' (measured on this run)',
            $value instanceof Participant => self::participantText($value),
            $value instanceof Event => self::eventText($value),
            $value instanceof Result => self::resultText($value),
            $value instanceof DateTimeInterface => self::instant($value),
            $value instanceof Throwable => self::shortName($value::class) . ': ' . $value->getMessage(),
            is_object($value) => self::shortName($value::class),
            default => get_debug_type($value),
        };
    }

    private static function participantText(Participant $participant): string
    {
        $details = [];
        if ($participant->getSeed() !== null) {
            $details[] = 'seed ' . $participant->getSeed();
        }
        foreach ($participant->getMetadata() as $key => $value) {
            $details[] = $key . ': ' . self::scalar($value);
        }

        return $participant->getLabel() . ($details === [] ? '' : ' (' . implode(', ', $details) . ')');
    }

    private static function eventText(Event $event): string
    {
        return implode(' v ', array_map(
            static fn (Participant $participant): string => $participant->getLabel(),
            $event->getParticipants()
        ));
    }

    private static function resultText(Result $result): string
    {
        $text = self::eventText($result->getEvent());

        $scores = [];
        foreach ($result->getEvent()->getParticipants() as $participant) {
            $score = $result->getScoreFor($participant);
            if ($score !== null) {
                $scores[] = self::scalar($score);
            }
        }
        if ($scores !== []) {
            $text .= ' ' . implode('-', $scores);
        }

        $winner = $result->getWinner();

        return $text . ': ' . ($winner === null ? 'drawn' : $winner->getLabel() . ' won');
    }

    /**
     * An instant in UTC, so the text does not depend on the default timezone.
     */
    private static function instant(DateTimeInterface $instant): string
    {
        return gmdate('D j M Y H:i', $instant->getTimestamp());
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * @param array<int, mixed> $shape A shape from self::describe()
     * @return list<string>
     */
    private static function textLines(array $shape, string $indent): array
    {
        if ($shape[0] === 'text') {
            return array_map(static fn (string $line): string => rtrim($indent . $line), explode("\n", self::stringOf($shape[1])));
        }

        if ($shape[0] === 'list') {
            return array_map(static fn (string $item): string => $indent . '- ' . $item, self::stringsOf($shape[1]));
        }

        if ($shape[0] === 'table') {
            $rows = [self::stringsOf($shape[1]), ...array_map(self::stringsOf(...), is_array($shape[2] ?? null) ? array_values($shape[2]) : [])];
            $widths = [];
            foreach ($rows as $row) {
                foreach ($row as $column => $cell) {
                    $widths[$column] = max($widths[$column] ?? 0, self::width($cell));
                }
            }

            $lines = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $column => $cell) {
                    $cells[] = $cell . str_repeat(' ', $widths[$column] - self::width($cell));
                }
                $lines[] = rtrim($indent . implode('  ', $cells));
            }

            return $lines;
        }

        $lines = [];
        foreach (is_array($shape[1]) ? $shape[1] : [] as $name => $part) {
            $lines[] = $indent . $name . ':';
            array_push($lines, ...self::textLines(is_array($part) ? array_values($part) : [], $indent . '  '));
        }

        return $lines;
    }

    /**
     * @param array<int, mixed> $shape A shape from self::describe()
     */
    private static function htmlBlock(array $shape, int $level = 3): string
    {
        if ($shape[0] === 'text') {
            return '<pre>' . self::escape(self::stringOf($shape[1])) . '</pre>';
        }

        if ($shape[0] === 'list') {
            return '<ul>' . implode('', array_map(
                static fn (string $item): string => '<li>' . self::escape($item) . '</li>',
                self::stringsOf($shape[1])
            )) . '</ul>';
        }

        if ($shape[0] === 'table') {
            $html = '<table><thead><tr>' . implode('', array_map(
                static fn (string $header): string => '<th>' . self::escape($header) . '</th>',
                self::stringsOf($shape[1])
            )) . '</tr></thead><tbody>';
            foreach (is_array($shape[2] ?? null) ? $shape[2] : [] as $row) {
                $html .= '<tr>' . implode('', array_map(
                    static fn (string $cell): string => '<td>' . self::escape($cell) . '</td>',
                    self::stringsOf($row)
                )) . '</tr>';
            }

            return $html . '</tbody></table>';
        }

        $html = '';
        $tag = 'h' . min($level, 6);
        foreach (is_array($shape[1]) ? $shape[1] : [] as $name => $part) {
            $html .= "<{$tag}>" . self::escape((string) $name) . "</{$tag}>"
                . self::htmlBlock(is_array($part) ? array_values($part) : [], $level + 1);
        }

        return $html;
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : self::scalar($value);
    }

    /**
     * @return list<string>
     */
    private static function stringsOf(mixed $values): array
    {
        return is_array($values) ? array_map(self::stringOf(...), array_values($values)) : [];
    }

    /**
     * The number of characters in a UTF-8 string, without needing mbstring.
     */
    private static function width(string $text): int
    {
        return (int) preg_match_all('/./us', $text);
    }

    /**
     * The numbered examples either side of a script, by file name.
     *
     * @return array{previous: ?string, next: ?string}
     */
    public static function siblings(string $script): array
    {
        $scripts = array_map(basename(...), glob(dirname($script) . '/[0-9][0-9]-*.php') ?: []);
        sort($scripts);
        $position = array_search(basename($script), $scripts, true);

        if ($position === false) {
            return ['previous' => null, 'next' => null];
        }

        return ['previous' => $scripts[$position - 1] ?? null, 'next' => $scripts[$position + 1] ?? null];
    }
}
