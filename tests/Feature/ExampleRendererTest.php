<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Examples\Measured;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Tests\Support\ExampleResults;

/*
 * examples/support/Example.php is the one place the examples turn results
 * into a page or into command-line text. It is tested here, once, on its
 * own: the examples are checked on what they compute
 * (tests/Feature/ExamplesTest.php), not on how that is drawn.
 *
 * Gap left knowingly: Example::present() displays only when it is called from the
 * script PHP was started with, and chooses HTML when the SAPI is not the
 * command line. The command-line branch is exercised by ExamplesTest, which
 * runs every example as a process. The web-server branch needs a server and
 * is not run by the suite; the HTML it would print is Example::renderHtml(), tested
 * below.
 */

require_once ExampleResults::directory() . '/support/Example.php';

describe('Example renderer', function (): void {
    it('writes the title, the summary and one section per result as text', function (): void {
        $home = new Participant('a', 'Alpha');
        $away = new Participant('b', 'Beta');
        $schedule = new Schedule([new Event([$home, $away], new Round(1))]);

        expect(Example::renderText('A title', 'What it shows.', ['Schedule' => $schedule, 'Events' => 1]))->toBe(
            "A title\n=======\n\nWhat it shows.\n\n"
            . "== Schedule ==\nRound 1:\n  - Alpha v Beta\n\n"
            . "== Events ==\n1\n"
        );
    });

    it('says so when there are no results', function (): void {
        expect(Example::renderText('Empty', 'Nothing.', []))->toBe("Empty\n=====\n\nNothing.\n\n(no results)\n")
            ->and(Example::renderHtml('Empty', 'Nothing.', []))->toContain('<p class="empty">(no results)</p>');
    });

    it('says so when a result is empty', function (mixed $empty, string $expected): void {
        expect(Example::renderText('T', 'S', ['Result' => $empty]))->toContain("== Result ==\n{$expected}\n")
            ->and(Example::renderHtml('T', 'S', ['Result' => $empty]))->toContain($expected);
    })->with([
        'an empty array' => [[], '(none)'],
        'a schedule without events' => [new Schedule(), '(no events)'],
        'standings without entries' => [new Standings([]), '(no entries)'],
        'null' => [null, '-'],
    ]);

    it('escapes everything that comes from a result in the HTML page', function (): void {
        $hostile = '<script>alert("x")</script> & Sons';
        $participant = new Participant('h', $hostile, 1, ['note' => '<b>bold</b>']);
        $other = new Participant('o', "O'Neill");
        $event = new Event([$participant, $other], new Round(1));

        $html = Example::renderHtml('<i>Title</i>', 'A & B <em>summary</em>', [
            '<u>Name</u>' => [$participant],
            'Schedule' => new Schedule([$event]),
            'Result' => new Result($event, $participant),
            'Pairing' => new RoundPairing(1, '<label>', [$event], [$other]),
            'Standings' => new Standings([new StandingEntry($participant, 1, 1, 0, 0, 3.0)]),
            'Table' => [['<th>' => '<td>']],
            'Parts' => ['<key>' => ['<item>', ['nested' => '<deep>']]],
            'Text' => '<plain>',
            'Failure' => new RuntimeException('<message>'),
        ], '<file>.php', ['previous' => '<prev>.php', 'next' => '"next".php'], '<?php echo "<source>";');

        foreach ([
            '<script>', '<b>bold</b>', '<i>Title</i>', '<em>', '<u>', '<key>', '<item>', '<deep>', '<plain>',
            '<message>', '<file>', '<prev>', 'href=""next"', '<source>', '<?php', '<label>',
        ] as $raw) {
            expect($html)->not->toContain($raw);
        }

        expect($html)->toContain('<th>&lt;th&gt;</th>')
            ->toContain('<td>&lt;td&gt;</td>')
            ->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; Sons')
            ->toContain('O&#039;Neill')
            ->toContain('&lt;i&gt;Title&lt;/i&gt;')
            ->toContain('RuntimeException: &lt;message&gt;');

        // The hostile label is there, escaped, wherever the participant is drawn: the
        // list, the schedule, the result (its event and its winner), the pairing and
        // the standings row
        expect(substr_count($html, '&lt;script&gt;'))->toBe(6);
    });

    it('is a complete HTML document that loads nothing from another host', function (): void {
        $html = Example::renderHtml('Title', 'Summary', ['Count' => 3], '01-first.php');

        expect($html)->toStartWith("<!DOCTYPE html>\n<html lang=\"en\">")
            ->toContain('<title>Title - Tactician examples</title>')
            ->toContain('<h1>Title</h1>')
            ->toContain('<h2>Count</h2>')
            ->toContain('<code>php examples/01-first.php</code>')
            ->toContain('<a href="index.php">')
            ->toEndWith("</html>\n");

        foreach (['src=', 'http', '<link', '<script'] as $external) {
            expect($html)->not->toContain($external);
        }
    });

    it('draws a list of rows as a table, named values as a list, and nested arrays as parts', function (): void {
        $text = Example::renderText('T', 'S', [
            'Rows' => [['Name' => 'São Paulo', 'Points' => 3], ['Name' => 'Ajax', 'Points' => 10]],
            'Facts' => ['Rounds' => 3, 'Complete' => true, 'Share' => 0.5, 'Whole' => 2.0, 'Missing' => null],
            'Parts' => ['First' => ['a', 'b'], 'Second' => ['Count' => 1]],
        ]);

        expect($text)->toContain("== Rows ==\nName       Points\nSão Paulo  3\nAjax       10\n")
            ->toContain("== Facts ==\n- Rounds: 3\n- Complete: yes\n- Share: 0.5\n- Whole: 2\n- Missing: -\n")
            ->toContain("== Parts ==\nFirst:\n  - a\n  - b\nSecond:\n  - Count: 1\n");
    });

    it('draws results, byes, standings with tiebreakers and exceptions', function (): void {
        $first = new Participant('a', 'Alpha', 1);
        $second = new Participant('b', 'Beta');
        $third = new Participant('c', 'Gamma');
        $event = new Event([$first, $second], new Round(2));

        $text = Example::renderText('T', 'S', [
            'Played' => [new Result($event, $first, ['a' => 2, 'b' => 1]), new Result($event)],
            'Pairing' => new RoundPairing(2, 'semifinal', [$event], [$third]),
            'Table' => new Standings([
                (new StandingEntry($first, 1, 1, 0, 0, 3.0))->withTiebreakers(['buchholz' => 1.5]),
                (new StandingEntry($second, 1, 0, 0, 1, 0.0))->withTiebreakers(['buchholz' => 3.0]),
            ]),
            'Failure' => new InvalidArgumentException('Not like that'),
            'Who' => $first,
        ]);

        expect($text)->toContain("== Played ==\n- Alpha v Beta 2-1: Alpha won\n- Alpha v Beta: drawn\n")
            ->toContain("== Pairing ==\n- Alpha v Beta\n- Bye: Gamma\n")
            ->toContain("#  Participant  Played  Won  Drawn  Lost  Ranking value  buchholz\n1  Alpha        1       1    0      0     3              1.5\n")
            ->toContain("== Failure ==\nInvalidArgumentException: Not like that\n")
            ->toContain("== Who ==\nAlpha (seed 1)\n");
    });

    it('shows a measured value with its unit and says it was measured', function (): void {
        $measured = new Measured(12.3456, 'ms', 'A duration differs on every run.');

        expect(Example::renderText('T', 'S', ['Took' => $measured]))->toContain("== Took ==\n12.346 ms (measured on this run)\n");
    });

    it('refuses a measured value without the reason it is not fixed', function (string $reason): void {
        expect(fn () => new Measured(1, 'ms', $reason))->toThrow(InvalidArgumentException::class);
    })->with(['empty' => [''], 'blank' => ["  \n"]]);

    it('writes instants in UTC and numbers with a point, whatever the timezone and locale', function (): void {
        $instant = new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('Europe/London'));
        $timezone = date_default_timezone_get();
        $locale = setlocale(LC_ALL, '0');

        date_default_timezone_set('Pacific/Auckland');
        setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8');
        try {
            $text = Example::renderText('T', 'S', ['When' => $instant, 'Share' => 0.25]);
        } finally {
            date_default_timezone_set($timezone);
            setlocale(LC_ALL, (string) $locale);
        }

        expect($text)->toContain("== When ==\nSat 1 Aug 2026 17:00\n")
            ->toContain("== Share ==\n0.25\n");
    });

    it('returns the results and displays nothing when the calling script was not run directly', function (): void {
        $results = ['Count' => 3];

        ob_start();
        try {
            $returned = Example::present(__FILE__, 'Title', 'Summary', $results);
        } finally {
            $displayed = ob_get_clean();
        }

        expect($returned)->toBe($results)
            ->and($displayed)->toBe('');
    });

    it('finds the numbered examples either side of a script', function (): void {
        $names = ExampleResults::names();
        $directory = ExampleResults::directory();

        expect(Example::siblings("{$directory}/{$names[0]}.php"))->toBe(['previous' => null, 'next' => $names[1] . '.php'])
            ->and(Example::siblings("{$directory}/{$names[1]}.php"))->toBe(['previous' => $names[0] . '.php', 'next' => $names[2] . '.php'])
            ->and(Example::siblings("{$directory}/index.php"))->toBe(['previous' => null, 'next' => null]);
    });
});
