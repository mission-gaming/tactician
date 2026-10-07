<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;
use MissionGaming\Tactician\Tests\Support\DocumentationSnippet;
use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use PHPUnit\Framework\Assert;

/*
 * Documentation snippets are executable documentation: every fenced `php`
 * block of README.md and docs/USAGE.md is run here, in a PHP process of its
 * own, under strict types and E_ALL, and anything PHP reports (a parse
 * error, an uncaught exception, a warning, a notice, a deprecation) fails
 * the block. Never-run samples have shipped broken before: a parse error
 * and a sample that could only throw sat in the usage guide unnoticed.
 *
 * The rules a document is written against (blocks of one section build on
 * each other, imports are written in the document, and the three markers
 * `skip reason="..."`, `throws="..."` and `setup`) are documented on
 * MissionGaming\Tactician\Tests\Support\DocumentationSnippets. A failure
 * names the document, the line of the block's opening fence and PHP's
 * message, with PHP's own line numbers translated to lines of the document.
 *
 * Gaps left knowingly:
 *
 * - docs/integrations/*.md is not executed. Those blocks are Symfony and
 *   Laravel code (service definitions, controllers, Eloquent casts) and
 *   neither framework is a dependency of this library, so they cannot run
 *   here.
 * - docs/ARCHITECTURE.md and docs/design/*.md are not executed. Their
 *   blocks are design sketches (signatures, interface outlines) rather than
 *   programs; covering them means marking almost every block as skipped.
 * - Inline value comments (`// 6`) are not compared with the values the
 *   code produces by the harness. The values the two documents state today
 *   are pinned one by one in "Documented values" below; a comment added
 *   later is unchecked until it is added there.
 * - A block that catches its own failure and prints a message exits
 *   cleanly, and PHP reports nothing. The harness cannot tell that from
 *   success; the blocks whose printed result the prose relies on are pinned
 *   in "Documented values" as well.
 *
 * A block that never returns does not hang the run: each block has a
 * wall-clock limit (DocumentationSnippets::TIME_LIMIT), after which it is
 * stopped and failed by name.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';

$extracted = [];
$blocks = [];
$skippedBlocks = [];
foreach (DocumentationSnippets::DOCUMENTS as $document) {
    $extracted[$document] = DocumentationSnippets::extract($document, (string) file_get_contents($root . '/' . $document));

    foreach ($extracted[$document]['snippets'] as $snippet) {
        if ($snippet->mode !== DocumentationSnippet::SETUP) {
            $blocks["{$snippet->location()} ({$snippet->section})"] = [$snippet];
        }
        if ($snippet->mode === DocumentationSnippet::SKIP) {
            $skippedBlocks["{$snippet->location()} ({$snippet->section})"] = [$snippet];
        }
    }
}

/**
 * The blocks of every document that carry the given mode, as
 * "document / section" => the marker's reason or exception class.
 *
 * @param array<string, array{snippets: list<DocumentationSnippet>, problems: list<string>}> $extracted
 * @param DocumentationSnippet::* $mode
 *
 * @return list<string>
 */
function snippetsMarked(array $extracted, string $mode): array
{
    $marked = [];
    foreach ($extracted as $document) {
        foreach ($document['snippets'] as $snippet) {
            if ($snippet->mode === $mode) {
                $marked[] = "{$snippet->file} / {$snippet->section}: " . ($snippet->reason ?? $snippet->exception ?? 'hidden setup');
            }
        }
    }

    return $marked;
}

/**
 * Extract a Markdown sample and run its last executable block.
 *
 * @return string|null The failure message, null when the block passed
 *
 * @throws RuntimeException When PHP cannot be started
 */
function snippetSampleFailure(string $markdown, float $timeLimit = DocumentationSnippets::TIME_LIMIT): ?string
{
    $extracted = DocumentationSnippets::extract('sample.md', $markdown);
    Assert::assertSame([], $extracted['problems']);

    $executed = array_values(array_filter(
        $extracted['snippets'],
        static fn(DocumentationSnippet $snippet): bool => $snippet->isExecuted()
    ));
    Assert::assertNotEmpty($executed, 'The sample has no executable block.');

    return DocumentationSnippets::run(
        $executed[count($executed) - 1],
        $extracted['snippets'],
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        $timeLimit
    );
}

/**
 * The one block of a document that contains the given line of code,
 * with every snippet of that document. Blocks are found by their code
 * rather than by line number, so editing the prose above one does not
 * break the lookup.
 *
 * @param array<string, array{snippets: list<DocumentationSnippet>, problems: list<string>}> $extracted
 *
 * @return array{0: DocumentationSnippet, 1: list<DocumentationSnippet>}
 *
 * @throws RuntimeException A failed assertion, when no block or more than one contains the line
 */
function documentedBlock(array $extracted, string $document, string $containing): array
{
    $found = array_values(array_filter(
        $extracted[$document]['snippets'],
        static fn(DocumentationSnippet $snippet): bool => $snippet->mode !== DocumentationSnippet::SETUP
            && str_contains(implode("\n", $snippet->code), $containing)
    ));
    Assert::assertSame(1, count($found), "Expected exactly one block of {$document} to contain `{$containing}`.");

    return [$found[0], $extracted[$document]['snippets']];
}

/**
 * Run assertions straight after one block of a document, with everything
 * that block and the earlier blocks of its section defined in scope. The
 * assertions are a block of their own, appended in the document's place.
 *
 * @param array<string, array{snippets: list<DocumentationSnippet>, problems: list<string>}> $extracted
 *
 * @return string|null The failure message, null when every assertion held
 *
 * @throws RuntimeException When PHP cannot be started
 */
function documentedValuesFailure(array $extracted, string $document, string $containing, string $assertions): ?string
{
    [$block, $all] = documentedBlock($extracted, $document, $containing);

    $upToBlock = array_slice($all, 0, (int) array_search($block, $all, true) + 1);
    $check = new DocumentationSnippet(
        $block->file,
        $block->line,
        $block->section,
        explode("\n", $assertions),
        sectionLine: $block->sectionLine,
    );

    return DocumentationSnippets::run($check, [...$upToBlock, $check], dirname(__DIR__, 2) . '/vendor/autoload.php');
}

/**
 * @return list<string> The problems extraction reports for a Markdown sample
 */
function snippetSampleProblems(string $markdown): array
{
    return DocumentationSnippets::extract('sample.md', $markdown)['problems'];
}

describe('Documentation snippets', function () use ($extracted, $blocks, $skippedBlocks, $autoload): void {
    it('finds every php block of the document and no malformed marker', function (string $document) use ($extracted): void {
        $root = dirname(__DIR__, 2);
        $fenced = array_filter(
            $extracted[$document]['snippets'],
            static fn(DocumentationSnippet $snippet): bool => $snippet->mode !== DocumentationSnippet::SETUP
        );

        // An empty problem list also means every skip marker gives its reason
        expect($extracted[$document]['problems'])->toBe([])
            ->and($fenced)->not->toBeEmpty()
            // Counted a second way, so a block the extractor walks past is noticed
            ->and(count($fenced))->toBe(DocumentationSnippets::countPhpFences((string) file_get_contents($root . '/' . $document)));
    })->with(DocumentationSnippets::DOCUMENTS);

    it('runs the block', function (DocumentationSnippet $snippet) use ($extracted, $autoload): void {
        if ($snippet->mode === DocumentationSnippet::SKIP) {
            // Reported as a skipped test, so the run's summary counts the blocks nothing executes
            $this->markTestSkipped("{$snippet->location()} is not executed: {$snippet->reason}");
        }

        $failure = DocumentationSnippets::run($snippet, $extracted[$snippet->file]['snippets'], $autoload);
        if ($failure !== null) {
            Assert::fail($failure);
        }

        expect($failure)->toBeNull();
    })->with($blocks);

    // The markers are the ways a block escapes plain execution, so the set
    // of marked blocks is pinned: marking another one means changing this
    // list, where the reason is reviewed.
    it('skips only the blocks listed here', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::SKIP))->toBe([
            'docs/USAGE.md / Advanced Patterns: A sketch of a custom scheduler: it omits getPlan() and '
                . 'generateCustomEvents(), so the class cannot be declared.',
        ]);
    });

    it('expects only the blocks listed here to throw', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::THROWS))->toBe([
            // A timeline that starts "tomorrow", which is read from the clock
            'docs/USAGE.md / Timeline Assignment: ' . InvalidConfigurationException::class,
            // Asking a shape-only session grid for a slot time
            'docs/USAGE.md / Schedule Repacking: ' . UnavailableValueException::class,
            'docs/USAGE.md / Real-World Examples: ' . IncompleteScheduleException::class,
        ]);
    });

    it('hides setup code only in the sections listed here', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::SETUP))->toBe([
            'README.md / Beyond Round Robin: hidden setup',
            'docs/USAGE.md / Pools, Progression, and Multi-Stage Tournaments: hidden setup',
        ]);
    });

    // A skip marker takes a block out of the suite, so it has to be earned:
    // the block must still be PHP a reader can read (it parses), and it must
    // really be unable to run. A marker left on a block that runs cleanly,
    // or put on one to hide a syntax error, fails here.
    it('keeps a skip marker only on a block that parses and cannot run', function (DocumentationSnippet $snippet) use ($extracted, $autoload): void {
        expect(DocumentationSnippets::syntaxError($snippet))->toBeNull();

        $spared = DocumentationSnippets::runSkipped($snippet, $extracted[$snippet->file]['snippets'], $autoload);
        if ($spared === null) {
            Assert::fail("{$snippet->location()} is marked skip but runs cleanly; remove the marker so the suite executes it.");
        }

        expect($spared)->toContain("{$snippet->location()}, under \"{$snippet->section}\"");
    })->with($skippedBlocks);

    // Blocks build on the earlier blocks under the same heading, so a
    // heading that appears twice must not join two sections into one
    it('gives every section that holds a block a heading of its own', function (string $document) use ($extracted): void {
        $headings = [];
        foreach ($extracted[$document]['snippets'] as $snippet) {
            $headings[$snippet->sectionLine] = $snippet->section;
        }

        expect(array_values($headings))->toBe(array_values(array_unique($headings)));
    })->with(DocumentationSnippets::DOCUMENTS);
});

/*
 * What the documents say their code produces. The harness proves a block
 * runs; these prove the output a block prints, and the values its inline
 * comments state, are the ones a reader gets. A block that catches its
 * own failure and prints a fallback message would pass the harness, so the
 * blocks with a `catch` branch are pinned to the branch the prose describes.
 */
describe('Documented values', function () use ($extracted, $autoload): void {
    it('prints what the document says the block prints', function (string $document, string $containing, array $expected, array $absent) use ($extracted, $autoload): void {
        [$block, $all] = documentedBlock($extracted, $document, $containing);
        $output = DocumentationSnippets::output($block, $all, $autoload);

        foreach ($expected as $text) {
            expect($output)->toContain($text);
        }
        foreach ($absent as $text) {
            expect($output)->not->toContain($text);
        }
    })->with([
        'participant accessors' => ['docs/USAGE.md', '$detailedPlayer->getId()', ["player3\nTeam Alpha\n2\nEurope\n"], []],
        'ids that are equal as numbers or hold a separator' => ['docs/USAGE.md', 'count($entrySchedule)', ["6 events\n"], []],
        'the leg count of a format without legs, and the exception a deprecated factory built' => [
            'docs/USAGE.md',
            '$swissContext->getPlan()->getLegs()',
            ["NULL\nint(1)\nbool(true)\nstring(49) \"Invalid participant count: 1. Must be at least 2.\"\n"],
            [],
        ],
        'a result replaced in the last round' => [
            'docs/USAGE.md',
            '$corrected = $state->withResultReplaced(',
            ["20 results before, 20 after\n"],
            [],
        ],
        // The block is there to show the refusal
        'a result of an earlier round refused' => [
            'docs/USAGE.md',
            '$firstRoundEvent = ',
            ["Refused: round 1 is not the last recorded round (5)\n"],
            ['Replaced'],
        ],
        'a stamped state refused by another engine' => [
            'docs/USAGE.md',
            '->withEngineFingerprint($engine->getFingerprint());',
            ["4 events\nRefused: format: recorded swiss, this engine single-elimination\n"],
            ['Paired as a bracket'],
        ],
        'what a fingerprint covers' => [
            'docs/USAGE.md',
            '$default = (new SwissPairingEngine())->getFingerprint();',
            [
                "bool(true)\nbool(true)\nbool(false)\n"
                . "Refused: ranking: recorded the default, this engine win-draw-loss,1,0.5,0\n",
            ],
            ['Paired on the chess scale'],
        ],
        'a state stamped through the engine interface' => [
            'docs/USAGE.md',
            'function startStage(StageEngineInterface $engine, array $participants): StageState',
            ["bool(true)\n"],
            [],
        ],
        'schedule metadata' => [
            'docs/USAGE.md',
            "getMetadataValue('participant_count')",
            ["Algorithm: round-robin\nParticipant count: 4\nTotal rounds: 3\n"],
            [],
        ],
        'multi-leg analysis' => [
            'docs/USAGE.md',
            'echo "Total legs: "',
            ["Total legs: 2\nRounds per leg: 3\nTotal rounds: 6\n", "Leg 1, Round 3:\n", "Leg 2, Round 4:\n", "Leg 2, Round 6:\n"],
            ['Leg 3', 'Round 7'],
        ],
        'balanced roles of four participants' => [
            'docs/USAGE.md',
            'foreach ($balanced as $event) {',
            [
                "Round 1: Red Star FC v Celtic\n"
                . "Round 1: Athletic Bilbao v AS Livorno\n"
                . "Round 2: Celtic v Athletic Bilbao\n"
                . "Round 2: AS Livorno v Red Star FC\n"
                . "Round 3: AS Livorno v Celtic\n"
                . "Round 3: Red Star FC v Athletic Bilbao\n",
            ],
            [],
        ],
        'standings table' => [
            'docs/USAGE.md',
            '$standings->getPosition(',
            ["1. Carol: 4 pts (1W 1D 0L)\n2. Alice: 4 pts (1W 1D 0L)\n3. Bob: 0 pts (0W 0D 1L)\n4. Dave: 0 pts (0W 0D 1L)\n"],
            [],
        ],
        'tied sets' => [
            'docs/USAGE.md',
            '$unplayed->getTiedSets()',
            ["Positions 1 to 4: Alice, Bob, Carol, Dave\nThe results do not decide who takes the top 2 positions\n0 tied sets\n"],
            [],
        ],
        'elimination round labels' => ['docs/USAGE.md', 'echo "{$pairing->getLabel()}\n";', ["quarterfinal\nsemifinal\nfinal\n"], []],
        'a level elimination event that names who advances' => [
            'docs/USAGE.md',
            '[TieDecision::TIE_WINNER_KEY => $dave->getId()]',
            ["Dave v Bob\n"],
            [],
        ],
        'who advanced, read from a level result' => [
            'docs/USAGE.md',
            'TieDecision::advancer([$recorded]',
            ["drawn, Dave advances\n"],
            [],
        ],
        // The block is there to show the refusal
        'a level elimination event that names nobody refused' => [
            'docs/USAGE.md',
            '$engine->pairNextRound($undecided);',
            [
                'undecided_tie: Invalid scheduler configuration: Elimination events cannot end in a draw (Alice vs Dave)'
                . ": the event is level, so record who advances as 'tie_winner' metadata on its result\n",
            ],
            ['Paired'],
        ],
        'a level elimination event given its missing decision' => [
            'docs/USAGE.md',
            '$decided = $undecided->withResultReplaced(',
            ["Alice v Bob\n"],
            [],
        ],
        // The restrictive constraint is there to show the failure branch
        'validation failure branch' => [
            'docs/USAGE.md',
            'echo "Schedule generated successfully with "',
            ['Cannot generate complete schedule: ', "Expected events: 6\n"],
            ['Schedule generated successfully'],
        ],
        'diagnostic report branch' => [
            'docs/USAGE.md',
            '$diagnostics = $e->getDiagnosticReport();',
            ['Incomplete schedule: ', '=== INCOMPLETE SCHEDULE DIAGNOSTIC REPORT ==='],
            ['Configuration error'],
        ],
        'exception hierarchy branch' => [
            'docs/USAGE.md',
            'handleIncompleteSchedule($e);',
            ["Schedule could not be completed:\n", "- Constraint 'Derby Ban' violated", "Completion: 5/6 events\n"],
            [],
        ],
        // The pin conflict names both events, and its report has no requirements block
        'pin conflict event ids and report' => [
            'docs/USAGE.md',
            '} catch (PinConflictException $e) {',
            [
                "Unpin one of: e1, e2\n"
                . "Reason: pin_conflict\n"
                . "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
                . "\n"
                . "Issue: A participant is pinned twice at one position\n"
                . "\n"
                . "=== CONFIGURATION DETAILS ===\n"
                . "• participant: team1\n"
                . "• session: 0\n"
                . "• slot: 3\n"
                . "• event_ids: [\"e1\", \"e2\"]\n",
            ],
            ['REQUIREMENTS', '[2 items]'],
        ],
        'configuration error reason and report' => [
            'docs/USAGE.md',
            'echo match ($e->getReason()) {',
            [
                "Check the date, the timezone name and the durations.\n"
                . "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
                . "\n"
                . "Issue: start or its timezone is not parseable\n"
                . "\n"
                . "=== CONFIGURATION DETAILS ===\n"
                . "• start: 2026-08-01 19:00:00\n"
                . "• timezone: Europe/Edinburg\n",
            ],
            ['REQUIREMENTS', 'See the report.'],
        ],
        'one catch clause for the library' => [
            'docs/USAGE.md',
            '} catch (TacticianException $e) {',
            [
                "A schedule the constraints rule out: MissionGaming\\Tactician\\Exceptions\\IncompleteScheduleException\n"
                . "A round numbered zero: MissionGaming\\Tactician\\Exceptions\\InvalidInputException\n"
                . "JSON that is cut short: MissionGaming\\Tactician\\Exceptions\\JsonConversionException\n",
            ],
            [],
        ],
        'catch clauses for the PHP parent types' => [
            'docs/USAGE.md',
            '} catch (\InvalidArgumentException $e) {',
            ["Round number must be positive\nSyntax error\n"],
            [],
        ],
        'pot draw plan and event count' => [
            'docs/USAGE.md',
            '$plan = $scheduler->getPlan($entrants, $drawOptions);',
            ["pot-draw\n9\n8\n18\n144\n144\n"],
            [],
        ],
        'pot draw roles of the top seed' => [
            'docs/USAGE.md',
            'echo "Pot {$pot}: "',
            ["Pot 1: first, second\nPot 2: first, second\nPot 3: first, second\nPot 4: first, second\n"],
            ['Pot 5'],
        ],
        'pot draw seeds' => [
            'docs/USAGE.md',
            'var_dump($events($again) === $events($schedule));',
            ["bool(true)\nbool(false)\n"],
            [],
        ],
        'pot draw refusals' => [
            'docs/USAGE.md',
            "'19 entrants, 1 pot, 2 per pot' => [19, 1, 2],",
            [
                "19 entrants, 1 pot, 2 per pot: odd_participant_count {\"participant_count\":19}\n"
                . "20 entrants, 3 pots, 1 per pot: unequal_pots {\"participant_count\":20,\"pots\":3,\"remainder\":2}\n"
                . "20 entrants, 5 pots of 4, 4 per pot: too_many_opponents_per_pot {\"opponents_per_pot\":4,\"pot_size\":4,\"maximum_opponents_per_pot\":3}\n"
                . "20 entrants, 4 pots of 5, 1 per pot: odd_pot_with_odd_opponents {\"pot_size\":5,\"opponents_per_pot\":1,\"participant_slots_inside_one_pot\":5}\n"
                . "36 entrants, 4 pots of 9, 4 per pot: configuration_not_yet_supported {\"participant_count\":36,\"pots\":4,\"pot_size\":9,\"opponents_per_pot\":4,\"supported_opponents_per_pot_for_odd_pot_size\":[2]}\n",
            ],
            [],
        ],
        'repack onto a shape-only grid' => [
            'docs/USAGE.md',
            '$shape->ordinalOf($assignment->getSession(), $assignment->getSlot())',
            [
                "e03: session 0, slot 1, ordinal 1\n"
                . "e04: session 0, slot 2, ordinal 2\n"
                . "{\"session\":1,\"slot\":2}\n"
                . "null\n"
                . "{\"session_count\":2,\"slots_per_session\":3,\"capacity_per_slot\":3,\"slots_per_session_overrides\":{\"1\":4}}\n",
            ],
            ['kickoff'],
        ],
        'position of an instant' => [
            'docs/USAGE.md',
            '$grid->positionOf($halfPastEight)',
            ["{\"session\":1,\"slot\":1}\n4\nbool(true)\n"],
            [],
        ],
        'repack with unbounded capacity' => [
            'docs/USAGE.md',
            '$open->hasUnboundedCapacity()',
            ["2 placed, 3 unplaced\n4 placed, 1 unplaced\nbool(true)\nNULL\nunbounded\n"],
            [],
        ],
        'repack violations by typed accessor' => [
            'docs/USAGE.md',
            '$crowded->getCapacityExceededViolations() as $violation',
            [
                "Celtic: 3 needed, 2 available, 1 short\n"
                . "the grid: 4 needed, 2 available, 2 short\n"
                . "Rayo Vallecano starts at slot 2 of session 0\n"
                . "e05: participant_over_capacity (Celtic)\n"
                . "e06: no_slot_available (nobody in particular)\n"
                . "e07: no_slot_available (nobody in particular)\n"
                . "3\n0\n0\n",
            ],
            [],
        ],
        'repack step budget flag' => ['docs/USAGE.md', '$starved->isBudgetExhausted()', ["bool(false)\nbool(true)\n"], []],
        // The literal is the documented fingerprint of the section's first repack
        'repack outcome fingerprint' => [
            'docs/USAGE.md',
            '$recomputed->fingerprint() === $outcome->fingerprint()',
            ["v1:170a5b5c2b8386a00fa959d26afcd76fab43b8521e08106b3bdbbf2334dbc945\nbool(true)\nbool(false)\n"],
            [],
        ],
        'premier league season' => ['docs/USAGE.md', 'echo "Premier League season: "', ["Premier League season: 380 matches\n"], []],
        'skill brackets on Swiss' => ['docs/USAGE.md', 'echo count($tournament) . " matches\n";', ["12 matches\n"], []],
        'spaced return fixtures succeed' => [
            'docs/USAGE.md',
            'echo "Tournament scheduled successfully!\n";',
            ["Tournament scheduled successfully!\nTotal matches: 30\nTotal rounds: 10\n"],
            ['Could not schedule'],
        ],
        // Rank 1 of an elimination outcome's table is not always the winner of the bracket.
        // The three rows below record a known defect (issue #55), not intended behaviour:
        // when the table is fixed their output changes, and that is the fix, not a regression
        'the best record of a double-elimination bracket is not its winner' => [
            'docs/USAGE.md',
            'MatchOutcomeSelector::winners()->select($doubleOutcome)',
            ["Rank 1: Heidi (5-2)\nRank 2: Alice (4-1)\nWon the bracket: Alice\n"],
            [],
        ],
        'a two-legged final decided by tie_winner leaves the finalists level' => [
            'docs/USAGE.md',
            'MatchOutcomeSelector::winners()->select($finalOutcome)',
            ["Rank 1: Alice\nWon the tie: Heidi\n1 tied set\n"],
            [],
        ],
        'a bye is no win in the table of a bracket' => [
            'docs/USAGE.md',
            'MatchOutcomeSelector::winners()->select($byeOutcome)',
            ["Alice (1-1)\nHeidi (1-0)\nWon the bracket: Heidi\n"],
            [],
        ],
        'a week in days keeps the time of day, a week in hours does not' => [
            'docs/USAGE.md',
            "foreach (['P7D', 'PT168H'] as \$roundInterval)",
            ["P7D: 17 Oct 19:00, 24 Oct 19:00, 31 Oct 19:00\nPT168H: 17 Oct 19:00, 24 Oct 19:00, 31 Oct 18:00\n"],
            [],
        ],
        'a seed repeats on a new scheduler and not on the second call of one' => [
            'docs/USAGE.md',
            '$seeded = fn (): RoundRobinScheduler',
            ["bool(true)\nbool(false)\n"],
            [],
        ],
    ]);

    it('prints one match per department in each of the two team-building rounds', function () use ($extracted, $autoload): void {
        [$block, $all] = documentedBlock($extracted, 'docs/USAGE.md', '$teamBuilding = ');
        $lines = explode("\n", trim(DocumentationSnippets::output($block, $all, $autoload)));

        // The pairing order is random, so the shape is pinned rather than the text
        expect($lines)->toHaveCount(4)
            ->and(preg_grep('/^Round 1: .+ vs .+$/', $lines))->toHaveCount(2)
            ->and(preg_grep('/^Round 2: .+ vs .+$/', $lines))->toHaveCount(2);
    });

    it('produces the values the inline comments state', function (string $document, string $containing, string $assertions) use ($extracted): void {
        $failure = documentedValuesFailure($extracted, $document, $containing, $assertions);
        if ($failure !== null) {
            Assert::fail($failure);
        }

        expect($failure)->toBeNull();
    })->with([
        'quick start is a full round robin' => ['README.md', '$schedule = $scheduler->schedule($participants);', 'assert(count($schedule) === 15);'],
        'options round trip' => [
            'docs/USAGE.md',
            '$options->toArray();',
            "assert(\$options->toArray() === ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false]);",
        ],
        'role balance of the two role assignments' => [
            'docs/USAGE.md',
            '(new RoleBalanceMetric())->measure($balanced);',
            "assert((new RoleBalanceMetric())->measure(\$parity) === 1.5);\nassert((new RoleBalanceMetric())->measure(\$balanced) === 1.0);",
        ],
        'balanced options round trip' => [
            'docs/USAGE.md',
            '$balancedOptions->toArray();',
            "assert(\$balancedOptions->toArray() === ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'balanced']);",
        ],
        // The custom role assignment of the document is honoured in every event
        'a custom role assignment names the better seed first' => [
            'docs/USAGE.md',
            'new RoundRobinOptions(roleAssignment: new BetterSeedFirst())',
            <<<'PHP'
                assert(count($seedFirst) === 3);
                foreach ($seedFirst as $event) {
                    [$first, $second] = $event->getParticipants();
                    assert($first->getSeed() < $second->getSeed());
                }
                PHP,
        ],
        // The prose says every rotation the greedy generator tries violates the policy
        'backtracking is needed for the placement policy' => [
            'docs/USAGE.md',
            'new RoundRobinOptions(backtracking: true)',
            <<<'PHP'
                assert(count($schedule) === 6);
                foreach ($schedule as $event) {
                    $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());
                    sort($ids);
                    assert(($placement[implode('|', $ids)] ?? $event->getRound()?->getNumber()) === $event->getRound()?->getNumber());
                }
                try {
                    (new RoundRobinScheduler($constraints))->schedule($participants);
                    $greedy = 'generated a schedule';
                } catch (\MissionGaming\Tactician\Exceptions\IncompleteScheduleException) {
                    $greedy = 'failed';
                }
                assert($greedy === 'failed');
                PHP,
        ],
        'swiss preset' => ['docs/USAGE.md', 'new SwissScheduler(null, new Randomizer())', 'assert(count($schedule) === 12);'],
        // What the pot draw section states in prose and in comments
        'pot draw options, pots and role counts' => [
            'docs/USAGE.md',
            '$stored = $drawOptions->toArray();',
            <<<'PHP'
                assert($stored === ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026]);
                assert(PotDrawOptions::fromArray([])->toArray() === ['pots' => 1, 'opponents_per_pot' => 1, 'seed' => 0]);
                assert($plan->getPotOf($entrants[0]) === 1 && $plan->getPotOf($entrants[8]) === 1 && $plan->getPotOf($entrants[9]) === 2);
                assert($plan->getPotMembers(1) === array_slice($entrants, 0, 9));
                // Every entrant has four events in each role
                $first = [];
                $second = [];
                foreach ($schedule as $event) {
                    [$a, $b] = $event->getParticipants();
                    $first[$a->getId()] = ($first[$a->getId()] ?? 0) + 1;
                    $second[$b->getId()] = ($second[$b->getId()] ?? 0) + 1;
                }
                assert(count($first) === 36 && array_unique(array_values($first)) === [4]);
                assert(count($second) === 36 && array_unique(array_values($second)) === [4]);
                try {
                    PotDrawOptions::fromArray(['pots' => 4, 'opponents_per_pots' => 2]);
                    $reason = null;
                } catch (\MissionGaming\Tactician\Exceptions\InvalidConfigurationException $e) {
                    $reason = $e->getReason();
                }
                assert($reason === \MissionGaming\Tactician\Exceptions\InvalidConfigurationReason::UnknownOptionKey);
                // One pot: a draw of non-repeat pairings over the whole field
                assert(count((new PotDrawScheduler())->schedule(array_slice($entrants, 0, 8), new PotDrawOptions(pots: 1, opponentsPerPot: 3))) === 12);
                PHP,
        ],
        // What "How a draw is made, and what it is uniform over" states
        'pot draw: the length of the walk, the measured figures and a field a move cannot change' => [
            'docs/USAGE.md',
            '$stored = $drawOptions->toArray();',
            <<<'PHP'
                // 16 steps for every round, and 1,024 divided by the entrants for a field of fewer than 64
                $rule = new \ReflectionClass(PotDrawScheduler::class);
                assert($rule->getConstant('STEPS_PER_ROUND') === 16);
                assert($rule->getConstant('STEPS_PER_ROUND_TIMES_ENTRANTS') === 1024);
                // The figures for 36 entrants in 4 pots of 9, over the seeds 0 to 39 (320 rounds, 240 pot pairs)
                $rounds = 0;
                $wholePotRounds = 0;
                $blocks = 0;
                $blocksWithOnePotFirst = 0;
                $potPairs = 0;
                $potPairsInRotation = 0;
                for ($seed = 0; $seed < 40; ++$seed) {
                    $regularity = \MissionGaming\Tactician\Tests\Support\PotDrawRegularity::of(
                        (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: $seed)),
                        $entrants,
                        4
                    );
                    $rounds += $regularity->rounds;
                    $wholePotRounds += $regularity->wholePotRounds;
                    $blocks += $regularity->blocks;
                    $blocksWithOnePotFirst += $regularity->blocksWithOnePotFirst;
                    $potPairs += $regularity->potPairs;
                    $potPairsInRotation += $regularity->potPairsInRotation;
                }
                assert($rounds === 320 && $wholePotRounds === 0);
                assert(abs($blocksWithOnePotFirst / $blocks - 0.38) < 0.07);
                assert($potPairs === 240 && abs($potPairsInRotation / $potPairs - 0.32) < 0.1);
                // 6 entrants in 3 pots of 2: every round has one pot playing inside itself and the other two meeting each other
                $six = array_slice($entrants, 0, 6);
                for ($seed = 0; $seed < 20; ++$seed) {
                    $potPairsByRound = [];
                    foreach ((new PotDrawScheduler())->schedule($six, new PotDrawOptions(pots: 3, opponentsPerPot: 1, seed: $seed)) as $event) {
                        [$a, $b] = $event->getParticipants();
                        $pots = [intdiv((int) substr($a->getId(), 1) - 1, 2), intdiv((int) substr($b->getId(), 1) - 1, 2)];
                        sort($pots);
                        $potPairsByRound[(int) $event->getRound()?->getNumber()][] = $pots;
                    }
                    assert(count($potPairsByRound) === 3);
                    foreach ($potPairsByRound as $potPairsOfRound) {
                        $inside = array_values(array_filter($potPairsOfRound, fn (array $pair): bool => $pair[0] === $pair[1]));
                        $between = array_values(array_filter($potPairsOfRound, fn (array $pair): bool => $pair[0] !== $pair[1]));
                        assert(count($inside) === 1 && count($between) === 2 && $between[0] === $between[1]);
                    }
                }
                PHP,
        ],
        'bracket placement' => [
            'docs/USAGE.md',
            '$titleHolder = ',
            <<<'PHP'
                assert($titleHolder->getLabel() === 'Alice');
                $records = [];
                foreach ($outcome->getStandings() as $entry) {
                    $records[] = "{$entry->getWins()}-{$entry->getLosses()}";
                }
                assert($records === ['3-0', '2-1', '1-1', '1-1', '0-1', '0-1', '0-1', '0-1']);
                PHP,
        ],
        'serpentine pools and qualifiers' => [
            'docs/USAGE.md',
            '$pools = PoolDistributor::serpentine(',
            <<<'PHP'
                $labels = fn (array $list): array => array_map(fn ($p) => $p->getLabel(), $list);
                assert(array_map($labels, $pools) === ['A' => ['Alice', 'Dave', 'Erin', 'Heidi'], 'B' => ['Bob', 'Carol', 'Frank', 'Grace']]);
                // Pool winners first, then the runners-up: A1, B1, A2, B2
                assert($labels($qualifiers) === ['Alice', 'Bob', 'Dave', 'Carol']);
                // Fold seeding pairs A1 with B2 and B1 with A2
                $semifinals = array_map(fn ($event) => $labels($event->getParticipants()), $knockout->pairNextRound($knockoutState)->getEvents());
                assert($semifinals === [['Alice', 'Carol'], ['Bob', 'Dave']]);
                PHP,
        ],
        'composition chain telescopes' => ['docs/USAGE.md', '->validateChain(16, [', 'assert($violations === []);'],
        'timeline kickoffs' => [
            'docs/USAGE.md',
            '$scheduled = (new TimelineAssigner())->assign($schedule, $timeline);',
            <<<'PHP'
                $kickoffs = [];
                foreach ($scheduled->getEventsByRound() as $round => $scheduledEvents) {
                    foreach ($scheduledEvents as $scheduledEvent) {
                        assert($scheduledEvent->getKickoff()->getTimezone()->getName() === 'UTC');
                        $kickoffs[] = $scheduledEvent->getKickoff()->format('Y-m-d H:i');
                    }
                }
                // 18:00 and 19:00 in London in August are 17:00 and 18:00 UTC, a week apart per round
                assert($kickoffs === ['2026-08-01 17:00', '2026-08-01 18:00', '2026-08-08 17:00', '2026-08-08 18:00', '2026-08-15 17:00', '2026-08-15 18:00']);
                PHP,
        ],
        'quality score and report' => [
            'docs/USAGE.md',
            '$report = $scorer->report($schedule);',
            "assert(\$score === 4.5);\nassert(\$report === ['Role Balance' => 1.5, 'Pairing Spacing' => 0.0]);",
        ],
        'optimizer samples' => [
            'docs/USAGE.md',
            '$result->getSamplesGenerated();',
            "assert(\$result->getSamplesGenerated() === 25);\nassert(\$result->getScore() <= \$scorer->score(\$result->getSchedule()) + 1e-9);",
        ],
        'json round trip' => ['docs/USAGE.md', '$restored = Schedule::fromJson($json);', 'assert($restored->toJson() === $json && count($restored) === 6);'],
        // The report quoted above the block is the one this configuration produces
        'derby ban report' => [
            'docs/USAGE.md',
            '$analysis = $e->getAnalysis();',
            <<<'PHP'
                $report = null;
                try {
                    $scheduler->schedule($participants);
                } catch (IncompleteScheduleException $e) {
                    $report = $e->getDiagnosticReport();
                    assert(count($e->getAnalysis()?->getImpossiblePairings() ?? []) === 1);
                }
                assert($report !== null);
                assert(str_contains($report, "=== BLOCKED PAIRINGS ===\n• Team 1 vs Team 2 cannot join the generated schedule in any round (blocked by: Derby Ban)\n"));
                assert(str_contains($report, "=== CONSTRAINT ATTRIBUTION ===\n• Derby Ban rejects Team 1 vs Team 2 in 3 of 3 rounds\n"));
                PHP,
        ],
        'stage plan shape' => [
            'docs/USAGE.md',
            '$swissPlan->getLegs();',
            <<<'PHP'
                assert($plan->getAlgorithm() === 'round-robin');
                assert($plan->getTotalRounds() === 6);
                assert($plan->getLegs() === 2);
                assert($plan->getRoundsPerLeg() === 3);
                assert($plan->getExpectedEventCount() === 12);
                assert($plan->getExpectedMeetings($participants[0], $participants[1]) === 2);
                assert($swissPlan->getAlgorithm() === 'swiss');
                assert($swissPlan->getTotalRounds() === 3);
                assert($swissPlan->getLegs() === null);
                // "bye-aware: 5 participants would need 5"
                $five = [...$participants, new Participant('rayo', 'Rayo Vallecano')];
                assert((new RoundRobinScheduler())->getPlan($five)->getRoundsPerLeg() === 5);
                PHP,
        ],
        'scheduling context' => [
            'docs/USAGE.md',
            '$newContext = $context->withEvents([$newEvent]);',
            <<<'PHP'
                assert($havePlayed === true);
                assert($totalRounds === 6);
                assert(count($playerEvents) === 1);
                // "contexts are immutable: this returns a new one"
                assert($newContext !== $context);
                assert(count($context->getExistingEvents()) === 1 && count($newContext->getExistingEvents()) === 2);
                PHP,
        ],
        // "the first call ... always returns the same schedule, the second call
        // always returns the same other one"
        'seeded generation repeats call by call' => [
            'docs/USAGE.md',
            'new Randomizer(new Mt19937(12345))',
            <<<'PHP'
                $again = $seeded();
                assert($again->schedule($participants)->toJson() === $schedule->toJson());
                $secondCall = $again->schedule($participants)->toJson();
                assert($secondCall !== $schedule->toJson());
                $third = $seeded();
                $third->schedule($participants);
                assert($third->schedule($participants)->toJson() === $secondCall);
                // "A scheduler without a Randomizer ... every call returns the same schedule"
                $plain = new RoundRobinScheduler();
                assert($plain->schedule($participants)->toJson() === $plain->schedule($participants)->toJson());
                PHP,
        ],
        // Where the legs fail is what the roles of today reach, not a promise: issues #47
        // (mirrored legs) and #51 (balanced roles by default) change it.
        // The table under "Role-Based Constraints", at its two smallest failing sizes
        // and one that holds
        'where a role balance limit of 3 or 4 holds and where it fails' => [
            'docs/USAGE.md',
            '$balanceConstraint = RoleBalanceConstraint::homeAway(3);',
            <<<'PHP'
                $completes = static function (int $size, \MissionGaming\Tactician\Scheduling\RoundRobinOptions $options): bool {
                    $field = [];
                    for ($i = 1; $i <= $size; ++$i) {
                        $field[] = new \MissionGaming\Tactician\DTO\Participant("p{$i}", "P{$i}");
                    }
                    $limit = ConstraintSet::create()->add(RoleBalanceConstraint::homeAway($size % 2 === 0 ? 3 : 4))->build();

                    try {
                        (new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler($limit))->schedule($field, $options);
                    } catch (\MissionGaming\Tactician\Exceptions\IncompleteScheduleException) {
                        return false;
                    }

                    return true;
                };
                $repeated = new \MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy();
                foreach ([4, 6, 7, 12] as $size) {
                    assert($completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions()));
                    assert($completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 2)));
                    assert($completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 3)));
                    assert(!$completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 4)));
                    assert(!$completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 2, strategy: $repeated)));
                    assert(!$completes($size, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 3, strategy: $repeated)));
                }
                // "three repeated legs fail for every size from 3"; 5 is a size four mirrored legs complete
                assert(!$completes(3, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 3, strategy: $repeated)));
                assert($completes(5, new \MissionGaming\Tactician\Scheduling\RoundRobinOptions(legs: 4)));
                PHP,
        ],
        // "In a single leg no pair meets twice, so there it rejects nothing"
        'a minimum of rounds between repeat meetings rejects nothing in a single leg' => [
            'docs/USAGE.md',
            '->add(new MinimumRestPeriodsConstraint(2))',
            <<<'PHP'
                $field = [];
                for ($i = 1; $i <= 7; ++$i) {
                    $field[] = new \MissionGaming\Tactician\DTO\Participant("p{$i}", "P{$i}");
                }
                $spaced = new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler(
                    ConstraintSet::create()->add(new MinimumRestPeriodsConstraint(1000))->build()
                );
                assert(count($spaced->schedule($field)) === 21);
                assert($spaced->getViolationCollector()->getViolations() === []);
                PHP,
        ],
        // "A rejected event is asked about twice ... an event it accepts once"
        'how often a constraint is asked' => [
            'docs/USAGE.md',
            "'Maximum Three Events'",
            <<<'PHP'
                $field = [];
                for ($i = 1; $i <= 4; ++$i) {
                    $field[] = new \MissionGaming\Tactician\DTO\Participant("p{$i}", "P{$i}");
                }
                $asked = 0;
                $accepting = ConstraintSet::create()->custom(function (Event $event) use (&$asked): bool {
                    ++$asked;

                    return true;
                })->build();
                (new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler($accepting))->schedule($field);
                assert($asked === 6);

                // A rule that rejects one pairing in the round the first ordering gives it:
                // that attempt fails and the next ordering completes. Each constraint is
                // asked twice about the event that was rejected (once by the set, once
                // more to record who rejected it) and once about every other event
                $firstRound = null;
                foreach ((new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler())->schedule($field) as $event) {
                    if ($event->hasParticipant($field[0]) && $event->hasParticipant($field[1])) {
                        $firstRound = $event->getRound()?->getNumber();
                    }
                }
                assert($firstRound !== null);
                $kept = [];
                $watched = [];
                $judged = [];
                $verdicts = [];
                $retried = ConstraintSet::create()
                    ->custom(function (Event $event) use (&$kept, &$watched): bool {
                        $kept[spl_object_id($event)] = $event;
                        $watched[spl_object_id($event)] = ($watched[spl_object_id($event)] ?? 0) + 1;

                        return true;
                    }, 'Watcher')
                    ->custom(function (Event $event) use (&$judged, &$verdicts, $field, $firstRound): bool {
                        $judged[spl_object_id($event)] = ($judged[spl_object_id($event)] ?? 0) + 1;

                        return $verdicts[spl_object_id($event)] = !($event->hasParticipant($field[0])
                            && $event->hasParticipant($field[1])
                            && $event->getRound()?->getNumber() === $firstRound);
                    }, 'Not in that round')
                    ->build();
                $completed = (new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler($retried))->schedule($field);
                assert(count($completed) === 6);
                foreach ($completed as $event) {
                    assert($watched[spl_object_id($event)] === 1 && $judged[spl_object_id($event)] === 1);
                }
                // The first event the rule rejected is the candidate of the first attempt
                // (the events asked about after it belong to that attempt's failure analysis)
                $rejectedId = array_keys($verdicts, false, true)[0];
                assert($watched[$rejectedId] === 2 && $judged[$rejectedId] === 2);

                // A generation that fails under every ordering asks far more than that
                $asked = 0;
                $rejecting = ConstraintSet::create()->custom(function (Event $event) use (&$asked, $field): bool {
                    ++$asked;

                    return !($event->hasParticipant($field[0]) && $event->hasParticipant($field[1]));
                })->build();
                try {
                    (new \MissionGaming\Tactician\Scheduling\RoundRobinScheduler($rejecting))->schedule($field);
                    assert(false);
                } catch (\MissionGaming\Tactician\Exceptions\IncompleteScheduleException) {
                    assert($asked > 4 * 7);
                }
                PHP,
        ],
        // Records a known defect (issue #53: duplicate results are detected by object
        // identity); the first assertion changes when it is fixed.
        // "two Event objects for one match ... are counted as two matches"
        'the calculator recognises an event by the object' => [
            'docs/USAGE.md',
            '$standings = $calculator->calculate($participants, $results);',
            <<<'PHP'
                $twice = $calculator->calculate([$alice, $bob], [new Result(new Event([$alice, $bob]), $alice), new Result(new Event([$alice, $bob]), $alice)]);
                assert($twice->getEntries()[0]->getPlayed() === 2);
                try {
                    $calculator->calculate([$alice, $bob], [new Result($eventOne, $alice), new Result($eventOne, $bob)]);
                    assert(false);
                } catch (\MissionGaming\Tactician\Exceptions\InvalidInputException) {
                }
                PHP,
        ],
        // The placement reading of the eight-entrant bracket, and its level entries
        'the table of a single-elimination bracket of eight' => [
            'docs/USAGE.md',
            '$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];',
            <<<'PHP'
                $records = array_map(
                    static fn ($entry): string => "{$entry->getWins()}-{$entry->getLosses()}",
                    $outcome->getStandings()->getEntries()
                );
                assert($records === ['3-0', '2-1', '1-1', '1-1', '0-1', '0-1', '0-1', '0-1']);
                assert($outcome->getStandings()->getEntries()[0]->getParticipant() === $titleHolder);
                assert(array_map('count', $outcome->getStandings()->getTiedSets()) === [2, 4]);
                PHP,
        ],
        // The label and seed assertions record a known defect (issue #55: Swiss pairing
        // order follows the seed attribute and the label, not list position).
        // "otherwise in the order of the table, the same on every call ... by seed,
        // then label, then ID, and not by position in the list", and the bound on rounds
        'a Swiss schedule without a randomizer, and its most rounds' => [
            'docs/USAGE.md',
            '$schedule = (new SwissScheduler(null, new Randomizer()))',
            <<<'PHP'
                $plain = new SwissScheduler();
                assert($plain->schedule($participants, new SwissOptions(rounds: 7))->toJson() === $plain->schedule($participants, new SwissOptions(rounds: 7))->toJson());

                $firstRound = static fn (array $field): array => array_map(
                    static fn ($event): array => array_map(static fn ($p): string => $p->getId(), $event->getParticipants()),
                    (new SwissScheduler())->schedule($field, new SwissOptions(rounds: 1))->getEvents()
                );
                // The same four ids in the same list order: labels and seeds decide who is paired
                $byLabel = [new Participant('p1', 'D'), new Participant('p2', 'C'), new Participant('p3', 'B'), new Participant('p4', 'A')];
                $bySeed = [new Participant('p1', 'A', 4), new Participant('p2', 'B', 3), new Participant('p3', 'C', 2), new Participant('p4', 'D', 1)];
                $inOrder = [new Participant('p1', 'A'), new Participant('p2', 'B'), new Participant('p3', 'C'), new Participant('p4', 'D')];
                assert($firstRound($byLabel) === $firstRound($bySeed));
                assert($firstRound($byLabel) !== $firstRound($inOrder));
                try {
                    $plain->schedule($participants, new SwissOptions(rounds: 8));
                    assert(false);
                } catch (\MissionGaming\Tactician\Exceptions\InvalidConfigurationException $e) {
                    assert($e->getReason() === \MissionGaming\Tactician\Exceptions\InvalidConfigurationReason::InvalidRoundCount);
                }
                PHP,
        ],
        // What a JSON round trip keeps of a schedule. That the entrant order is lost is a
        // known defect (issue #53)
        'what a serialized schedule keeps' => [
            'docs/USAGE.md',
            '$restored = Schedule::fromJson($json);',
            <<<'PHP'
                $entrants = [];
                for ($i = 1; $i <= 5; ++$i) {
                    $entrants[] = new Participant("p{$i}", "P{$i}", null, ['rating' => 1.0]);
                }
                $back = Schedule::fromJson((new RoundRobinScheduler())->schedule($entrants)->toJson());
                $ids = array_column($back->toArray()['participants'], 'id');
                assert($ids !== ['p1', 'p2', 'p3', 'p4', 'p5']);
                $firstAppearance = [];
                foreach ($back as $event) {
                    foreach ($event->getParticipants() as $participant) {
                        $firstAppearance[$participant->getId()] = true;
                    }
                }
                assert($ids === array_keys($firstAppearance));
                assert($back->getEvents()[0]->getParticipants()[0]->getMetadataValue('rating') === 1);
                PHP,
        ],
        // Records a known defect (issue #54: Schedule becomes an IteratorAggregate); the
        // first count is 120 * 120 when it is fixed.
        // "the outer loop stops after its first event"
        'a schedule has one cursor' => [
            'docs/USAGE.md',
            '$eventsByRound = $largeSchedule->getEventsByRound();',
            <<<'PHP'
                $visited = 0;
                foreach ($largeSchedule as $outer) {
                    foreach ($largeSchedule as $inner) {
                        ++$visited;
                    }
                }
                assert($visited === 120);
                $visited = 0;
                foreach ($largeSchedule as $outer) {
                    foreach ($largeSchedule->getEvents() as $inner) {
                        ++$visited;
                    }
                }
                assert($visited === 120 * 120);
                PHP,
        ],
        // Records a known defect (issue #55: constraint sites compare participants by
        // object identity); the two assertions on the rebuilt participant flip when it is fixed.
        // "they do not recognise it and let through an event they would reject"
        'two constraints look for the participant object' => [
            'docs/USAGE.md',
            'count($entrySchedule)',
            <<<'PHP'
                $first = new Participant('a', 'A', 1);
                $second = new Participant('b', 'B', 2);
                $third = new Participant('c', 'C', 3);
                $plan = new \MissionGaming\Tactician\Stage\RoundRobinPlan([$first, $second, $third], 2);
                $context = new \MissionGaming\Tactician\Scheduling\SchedulingContext(
                    [$first, $second, $third],
                    $plan,
                    [new \MissionGaming\Tactician\DTO\Event([$first, $second], new \MissionGaming\Tactician\DTO\Round(1))]
                );
                $rebuilt = new Participant('a', 'A', 1);

                $streak = \MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint::homeAway(1);
                assert(!$streak->isSatisfied(new \MissionGaming\Tactician\DTO\Event([$first, $third], new \MissionGaming\Tactician\DTO\Round(2)), $context));
                assert($streak->isSatisfied(new \MissionGaming\Tactician\DTO\Event([$rebuilt, $third], new \MissionGaming\Tactician\DTO\Round(2)), $context));

                $protection = new \MissionGaming\Tactician\Constraints\SeedProtectionConstraint(2, 1.0);
                assert(!$protection->isSatisfied(new \MissionGaming\Tactician\DTO\Event([$first, $second], new \MissionGaming\Tactician\DTO\Round(1)), $context));
                assert($protection->isSatisfied(new \MissionGaming\Tactician\DTO\Event([$rebuilt, $second], new \MissionGaming\Tactician\DTO\Round(1)), $context));
                PHP,
        ],
        // "two IDs that are equal as numbers ... keep the order they were given in".
        // Records a known defect (issue #55: a standalone table gives the same order
        // for every permutation of its participants)
        'the standings fallback does not order two ids that are equal as numbers' => [
            'docs/USAGE.md',
            'count($entrySchedule)',
            <<<'PHP'
                $order = static fn (array $field): array => array_map(
                    static fn ($entry): string => $entry->getParticipant()->getId(),
                    (new \MissionGaming\Tactician\Standings\StandingsCalculator())->calculate($field, [])->getEntries()
                );
                $leadingZero = new Participant('01', 'Entry');
                $plain = new Participant('1', 'Entry');
                assert($order([$leadingZero, $plain]) === ['01', '1']);
                assert($order([$plain, $leadingZero]) === ['1', '01']);
                assert($order([new Participant('10', 'Entry'), new Participant('9', 'Entry')]) === ['9', '10']);
                PHP,
        ],
        // "in a field of five, the entrants in positions 1 and 2 meet in the semifinal".
        // Records a known defect (issue #55: re-seeding ranks bye recipients last)
        'a re-seeded bracket ranks an entrant who had a bye below the winners of the round' => [
            'docs/USAGE.md',
            '$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];',
            <<<'PHP'
                $reseeded = new SingleEliminationEngine(new \MissionGaming\Tactician\Scheduling\EliminationOptions(reseedEachRound: true));
                $five = array_slice($participants, 0, 5);
                foreach ([0, 1] as $winnerIndex) {
                    $fiveState = StageState::start($five);
                    $opening = $reseeded->pairNextRound($fiveState);
                    assert($opening->getByes() === [$five[0], $five[1], $five[2]]);
                    $fiveState = $fiveState->withRoundPlayed($opening, array_map(
                        static fn ($event) => new Result($event, $event->getParticipants()[$winnerIndex]),
                        $opening->getEvents()
                    ));
                    $meetings = array_map(
                        static function ($event): array {
                            $ids = array_map(static fn ($p): string => $p->getId(), $event->getParticipants());
                            sort($ids);

                            return $ids;
                        },
                        $reseeded->pairNextRound($fiveState)->getEvents()
                    );
                    assert(in_array(['alice', 'bob'], $meetings, true));
                }
                PHP,
        ],
        // "a kickoff that falls in the hour the clocks skip ... every later round keeps
        // the moved time". Records a known defect (issue #56: slot times are computed by
        // adding the interval repeatedly)
        'a kickoff in the hour the clocks skip moves every later round' => [
            'docs/USAGE.md',
            "foreach (['P7D', 'PT168H'] as \$roundInterval)",
            <<<'PHP'
                $skipped = (new TimelineAssigner())->assign($schedule, new TimelineDefinition(
                    start: new DateTimeImmutable('2026-03-22 01:30', $londonTime),
                    roundInterval: new DateInterval('P7D'),
                    resources: ['Pitch 1', 'Pitch 2'],
                ));
                $times = [];
                foreach ($skipped->getEventsByRound() as $roundEvents) {
                    $times[] = $roundEvents[0]->getKickoff()->setTimezone($londonTime)->format('j M H:i');
                }
                assert($times === ['22 Mar 01:30', '29 Mar 02:30', '5 Apr 02:30']);
                PHP,
        ],
        // "without a rule it accepts a schedule that has one participant in two events at
        // the same time ... add a MinimumRestRule". Issue #56 covers what the assigner
        // does not check
        'the assigner checks who plays only when it has a rest rule' => [
            'docs/USAGE.md',
            "foreach (['P7D', 'PT168H'] as \$roundInterval)",
            <<<'PHP'
                [$one, $two, $three] = $participants;
                $clash = new \MissionGaming\Tactician\DTO\Schedule([
                    new \MissionGaming\Tactician\DTO\Event([$one, $two], new \MissionGaming\Tactician\DTO\Round(1)),
                    new \MissionGaming\Tactician\DTO\Event([$one, $three], new \MissionGaming\Tactician\DTO\Round(1)),
                ]);
                $evening = new TimelineDefinition(
                    start: new DateTimeImmutable('2026-10-17 19:00', $londonTime),
                    roundInterval: new DateInterval('P7D'),
                    resources: ['Pitch 1', 'Pitch 2'],
                );
                [$firstEvent, $secondEvent] = (new TimelineAssigner())->assign($clash, $evening)->getScheduledEvents();
                assert($firstEvent->getKickoff() == $secondEvent->getKickoff());
                try {
                    (new TimelineAssigner([new \MissionGaming\Tactician\Timeline\MinimumRestRule(new DateInterval('PT1S'))]))->assign($clash, $evening);
                    assert(false);
                } catch (\MissionGaming\Tactician\Exceptions\InvalidConfigurationException $e) {
                    assert($e->getReason() === \MissionGaming\Tactician\Exceptions\InvalidConfigurationReason::TimeRuleViolation);
                }
                PHP,
        ],
        // "the participants who have had a bye are ordered ahead of the others, with or
        // without a Randomizer, and are the first to be paired": the one participant
        // with a bye after round 1 is in the first event of round 2
        'a results-free Swiss schedule of an odd field pairs the bye recipient first' => [
            'docs/USAGE.md',
            '$schedule = (new SwissScheduler(null, new Randomizer()))',
            <<<'PHP'
                $odd = array_slice($participants, 0, 5);
                $schedulers = [new SwissScheduler()];
                for ($seed = 1; $seed <= 25; ++$seed) {
                    $schedulers[] = new SwissScheduler(null, new Randomizer(new \Random\Engine\Mt19937($seed)));
                }
                foreach ($schedulers as $swiss) {
                    $rounds = $swiss->schedule($odd, new SwissOptions(rounds: 2))->getEventsByRound();
                    $playing = [];
                    foreach ($rounds[1] as $event) {
                        foreach ($event->getParticipants() as $participant) {
                            $playing[] = $participant->getId();
                        }
                    }
                    $satOut = array_values(array_diff(array_map(static fn ($p): string => $p->getId(), $odd), $playing));
                    assert(count($satOut) === 1);
                    $firstPaired = array_map(static fn ($p): string => $p->getId(), $rounds[2][0]->getParticipants());
                    assert(in_array($satOut[0], $firstPaired, true));
                }
                // "a field of odd size could play one round more and is refused it"
                assert(count($schedulers[0]->schedule($odd, new SwissOptions(rounds: 4))) === 8);
                try {
                    $schedulers[0]->schedule($odd, new SwissOptions(rounds: 5));
                    assert(false);
                } catch (\MissionGaming\Tactician\Exceptions\InvalidConfigurationException $e) {
                    assert($e->getReason() === \MissionGaming\Tactician\Exceptions\InvalidConfigurationReason::InvalidRoundCount);
                }
                PHP,
        ],
        // "It is not what the selector returns from the outcome of a whole bracket"
        'the composition validator counts a match-outcome selector as one knockout round' => [
            'docs/USAGE.md',
            "new StageTransition('final', 2, MatchOutcomeSelector::winners())",
            <<<'PHP'
                assert($violations === []);
                $validator = new CompositionValidator();
                assert($validator->validateChain(8, [new StageTransition('next', 4, MatchOutcomeSelector::winners())]) === []);
                assert($validator->validateChain(5, [new StageTransition('next', 3, MatchOutcomeSelector::winners())]) === []);
                assert($validator->validateChain(5, [new StageTransition('repechage', 2, MatchOutcomeSelector::losers())]) === []);
                // The four qualifiers above, played as one bracket: the validator counts two
                // winners, and the selector returns the one winner of the final
                assert($validator->validateChain(4, [new StageTransition('next', 2, MatchOutcomeSelector::winners())]) === []);
                while (!$knockout->isComplete($knockoutState)) {
                    $knockoutRound = $knockout->pairNextRound($knockoutState);
                    $knockoutState = $knockoutState->withRoundPlayed($knockoutRound, array_map(
                        static fn ($event) => new \MissionGaming\Tactician\DTO\Result($event, $event->getParticipants()[0]),
                        $knockoutRound->getEvents()
                    ));
                }
                assert(count(MatchOutcomeSelector::winners()->select($knockout->getOutcome($knockoutState))) === 1);
                PHP,
        ],
        'skill tiers never skip a tier' => [
            'docs/USAGE.md',
            'echo count($tournament) . " matches\n";',
            <<<'PHP'
                $tierSkips = static fn ($schedule): int => count(array_filter(
                    $schedule->getEvents(),
                    static fn ($event): bool => abs(
                        $event->getParticipants()[0]->getMetadataValue('tier') - $event->getParticipants()[1]->getMetadataValue('tier')
                    ) > 1
                ));
                assert($tierSkips($tournament) === 0);
                // "left to itself, Swiss pairing would put a professional against an
                // amateur four times": the rule changes the pairings rather than
                // describing what the entrant order would have produced anyway
                assert($tierSkips((new SwissScheduler())->schedule($players, new SwissOptions(rounds: 3))) === 4);
                PHP,
        ],
        'team building is cross-building' => [
            'docs/USAGE.md',
            '$teamBuilding = ',
            <<<'PHP'
                foreach ($teamBuilding as $event) {
                    [$first, $second] = $event->getParticipants();
                    assert($first->getMetadataValue('location') !== $second->getMetadataValue('location'));
                }
                PHP,
        ],
        'counting a large schedule' => ['docs/USAGE.md', '$totalEvents = count($largeSchedule);', 'assert($totalEvents === 120 && count($events) === 120 && count($eventsByRound) === 15);'],
    ]);

    // Proves the two checks above can fail: without this a lookup that
    // matched nothing, or assertions that never ran, would pass every row
    it('fails when a documented value is wrong', function () use ($extracted): void {
        $failure = documentedValuesFailure($extracted, 'docs/USAGE.md', '$totalEvents = count($largeSchedule);', 'assert($totalEvents === 121);');

        expect($failure)->toContain('AssertionError: assert($totalEvents === 121)');
    });
});

/*
 * The harness itself, against Markdown written here. These prove the
 * documentation test can fail: without them a harness that ran nothing, or
 * swallowed what PHP reported, would pass every block above.
 */
describe('Documentation snippet harness', function (): void {
    it('fails a block that does not parse, naming the document, the fence line and the PHP error', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            # Sample

            ## Constraints

            ```php
            $a = 1;
            $b = fn () => { return $a; };
            ```
            MARKDOWN);

        expect($failure)->toContain('sample.md:5, under "Constraints": the php block failed')
            ->and($failure)->toContain('Parse error: syntax error')
            ->and($failure)->toContain('in sample.md:7');
    });

    it('fails a block that ends in an uncaught exception', function (): void {
        $failure = snippetSampleFailure("```php\nthrow new RuntimeException('boom');\n```\n");

        expect($failure)->toContain('sample.md:1')
            ->and($failure)->toContain('Uncaught RuntimeException: boom in sample.md:2');
    });

    it('fails a block for a diagnostic below an error', function (string $code, string $reported): void {
        expect(snippetSampleFailure("```php\n{$code}\n```\n"))->toContain($reported);
    })->with([
        'warning' => ['echo $undefined;', 'Warning: Undefined variable $undefined in sample.md:2'],
        'notice' => ["trigger_error('careful', E_USER_NOTICE);", 'Notice: careful'],
        'deprecation' => ["trigger_error('old', E_USER_DEPRECATED);", 'Deprecated: old'],
        'failed assertion' => ['assert(1 + 1 === 3);', 'AssertionError'],
        'missing import' => ["new Participant('a', 'A');", 'Class "Participant" not found'],
        'strict types' => ['strlen(5);', 'must be of type string, int given'],
    ]);

    it('passes a block that runs cleanly, with or without an opening tag', function (string $opening): void {
        expect(snippetSampleFailure("```php\n{$opening}use MissionGaming\\Tactician\\DTO\\Participant;\n\necho (new Participant('a', 'A'))->getLabel();\n```\n"))
            ->toBeNull();
    })->with(['bare' => [''], 'opening tag' => ["<?php\n\n"], 'strict types' => ["<?php\n\ndeclare(strict_types=1);\n\n"]]);

    it('runs a block after the earlier blocks of its section, across deeper headings', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;

            $alice = new Participant('alice', 'Alice');
            echo "printed by an earlier block\n";
            ```

            ### Deeper

            Repeating an import keeps a block readable by itself:

            ```php
            use MissionGaming\Tactician\DTO\Participant;

            $bob = new Participant('bob', 'Bob');
            assert($alice->getLabel() . $bob->getLabel() === 'AliceBob');
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('carries nothing from one section to the next', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## First

            ```php
            $alice = 'Alice';
            ```

            ## Second

            ```php
            echo $alice;
            ```
            MARKDOWN);

        expect($failure)->toContain('sample.md:9, under "Second": the php block failed')
            ->and($failure)->toContain('Undefined variable $alice in sample.md:10');
    });

    it('says how many earlier blocks a failing block ran after', function (): void {
        $failure = snippetSampleFailure("## Section\n\n```php\n\$a = 1;\n```\n\n```php\n\$b = 2;\n```\n\n```php\necho \$c;\n```\n");

        expect($failure)->toContain('sample.md:11, under "Section", run after the 2 earlier block(s) of that section');
    });

    it('finds a php block indented inside a list and ignores other languages', function (): void {
        $extracted = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            1. A step:

               ```php
               $inList = true;
               ```

            ```bash
            composer install
            ```

            ```text
            <!-- snippet: skip -->
            ```

            ~~~PHP
            $tilde = true;
            ~~~
            MARKDOWN);

        expect($extracted['problems'])->toBe([])
            ->and(array_map(static fn(DocumentationSnippet $snippet): array => $snippet->code, $extracted['snippets']))
            ->toBe([['$inList = true;'], ['$tilde = true;']])
            ->and(array_map(static fn(DocumentationSnippet $snippet): int => $snippet->line, $extracted['snippets']))
            ->toBe([3, 15]);
    });

    it('does not execute a skipped block and does not build on it', function (): void {
        $markdown = <<<'MARKDOWN'
            ## Section

            <!-- snippet: skip reason="A sketch that omits its methods." -->

            ```php
            class Sketch implements Countable
            {
                // ...
            }
            $fromSketch = true;
            ```

            ```php
            assert(!isset($fromSketch) && !class_exists('Sketch', false));
            ```
            MARKDOWN;

        $skipped = DocumentationSnippets::extract('sample.md', $markdown)['snippets'][0];

        expect($skipped->mode)->toBe(DocumentationSnippet::SKIP)
            ->and($skipped->reason)->toBe('A sketch that omits its methods.')
            ->and($skipped->isExecuted())->toBeFalse()
            ->and(snippetSampleFailure($markdown))->toBeNull();
    });

    it('reports a marker it cannot honour', function (string $markdown, string $problem): void {
        $problems = snippetSampleProblems($markdown);

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toContain($problem);
    })->with([
        'skip without a reason' => ["<!-- snippet: skip -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: the skip marker gives no reason'],
        'skip with an empty reason' => ["<!-- snippet: skip reason=\" \" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: the skip marker gives no reason'],
        'unknown marker' => ["<!-- snippet: ignore -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker `ignore`'],
        'marker split over lines' => ["<!-- snippet: skip\nreason=\"x\" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: malformed snippet marker'],
        'marker followed by prose' => ["<!-- snippet: skip reason=\"x\" -->\nProse.\n\n```php\n\$a = 1;\n```\n", 'sample.md:1: the snippet marker is not followed by a php block'],
        'marker before another language' => ["<!-- snippet: skip reason=\"x\" -->\n```bash\nls\n```\n", 'sample.md:1: the snippet marker is not followed by a php block'],
        'marker at the end of the document' => ["Prose.\n\n<!-- snippet: throws=\"RuntimeException\" -->\n", 'sample.md:3: the snippet marker is not followed by a php block'],
        'setup that imports a class' => ["<!-- snippet: setup\nuse RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup with an indented import' => ["<!-- snippet: setup\n    use RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup with an import after another statement' => ["<!-- snippet: setup\n\$a = 1; use RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup with a grouped import' => ["<!-- snippet: setup\nuse Random\\{Randomizer, Engine\\Mt19937};\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup that imports a function' => ["<!-- snippet: setup\nuse function strlen;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup that aliases a class' => ["<!-- snippet: setup\nclass_alias(RuntimeException::class, 'Boom');\n-->\n", 'sample.md:1: hidden setup must not import a class or alias one'],
        'setup that aliases a class by a qualified call' => ["<!-- snippet: setup\n\\Class_Alias(RuntimeException::class, 'Boom');\n-->\n", 'sample.md:1: hidden setup must not import a class or alias one'],
        'setup marker closed on its own line' => ["<!-- snippet: setup -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker `setup`'],
        'two markers for one block' => [
            "<!-- snippet: skip reason=\"x\" -->\n<!-- snippet: throws=\"RuntimeException\" -->\n```php\n\$a = 1;\n```\n",
            'sample.md:1: the snippet marker is not followed by a php block',
        ],
        'throws without a class' => ["<!-- snippet: throws=\"\" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker'],
        'marker sharing its line with prose' => ["See <!-- snippet: skip reason=\"x\" --> below.\n\n```php\n\$a = 1;\n```\n", 'sample.md:1: malformed snippet marker'],
        'setup that is never closed' => ["<!-- snippet: setup\n\$a = 1;\n", 'sample.md:1: the setup marker is never closed'],
        'fence that is never closed' => ["```php\n\$a = 1;\n", 'sample.md:1: the code fence is never closed'],
    ]);

    it('passes a block marked throws when it ends in that exception or a subclass', function (string $expected): void {
        $failure = snippetSampleFailure(
            "<!-- snippet: throws=\"{$expected}\" -->\n```php\nthrow new InvalidArgumentException('expected');\n```\n"
        );

        expect($failure)->toBeNull();
    })->with(['the class' => ['InvalidArgumentException'], 'a parent class' => ['LogicException'], 'an interface' => ['Throwable']]);

    it('fails a block marked throws that does not end in that exception', function (string $code, string $reported): void {
        $failure = snippetSampleFailure("<!-- snippet: throws=\"InvalidArgumentException\" -->\n```php\n{$code}\n```\n");

        expect($failure)->toContain('sample.md:2')
            ->and($failure)->toContain($reported);
    })->with([
        'nothing thrown' => ['$a = 1;', 'is marked throws="InvalidArgumentException" but ended without throwing.'],
        'another exception' => ["throw new RuntimeException('other');", 'is marked throws="InvalidArgumentException" but threw RuntimeException.'],
        'a parse error' => ['$a = ;', "but failed differently:\nParse error: syntax error"],
        'a warning before the exception' => [
            "echo \$undefined;\nthrow new InvalidArgumentException('expected');",
            "threw InvalidArgumentException as marked, but PHP also reported:\nWarning: Undefined variable \$undefined in sample.md:3",
        ],
    ]);

    it('does not build later blocks on a block that throws', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            <!-- snippet: throws="RuntimeException" -->
            ```php
            $beforeThrow = true;
            throw new RuntimeException('expected');
            ```

            ```php
            assert(!isset($beforeThrow));
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    // Exit code 0 and a silent stderr are what success looks like, and also
    // what a script that stopped early looks like: the block after the stop
    // would pass without one line of it having run.
    it('fails a block the script never reaches the end of', function (string $markdown, string $where): void {
        $failure = snippetSampleFailure($markdown);

        expect($failure)->toContain($where)
            ->and($failure)->toContain('the php block did not run to its end');
    })->with([
        'exit' => ["```php\nexit;\nundefinedFunction();\n```\n", 'sample.md:1'],
        'die with a message, exit code 0' => ["```php\ndie('Error: could not schedule');\n```\n", 'sample.md:1'],
        'a top-level return' => ["```php\nreturn;\nundefinedFunction();\n```\n", 'sample.md:1'],
        'exit in an earlier block' => ["## Section\n\n```php\nexit;\n```\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7, under "Section", run after the 1 earlier block(s)'],
        'a return in an earlier block' => ["## Section\n\n```php\nreturn;\n```\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7'],
        'an exception swallowed by an earlier handler' => [
            "## Section\n\n```php\nset_exception_handler(static function (Throwable \$e): void {});\n```\n\n```php\nthrow new RuntimeException('lost');\n```\n",
            'sample.md:7',
        ],
        'exit inside hidden setup' => ["## Section\n\n<!-- snippet: setup\nexit;\n-->\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7'],
    ]);

    // Without a limit a block that loops for ever hangs the whole suite, and
    // the run is killed by the CI job's own timeout with nothing to say which
    // block it was
    it('stops a block that never returns and fails it by name', function (string $markdown, string $where): void {
        $started = microtime(true);
        $failure = snippetSampleFailure($markdown, 0.5);
        $elapsed = microtime(true) - $started;

        expect($failure)->toContain($where)
            ->and($failure)->toContain('the php block did not finish within 0.5 second(s) and was stopped')
            ->and($failure)->toContain('never returns')
            ->and($elapsed)->toBeLessThan(10.0);
    })->with([
        'an endless loop' => ["```php\nwhile (true) {\n}\n```\n", 'sample.md:1'],
        'a sleep longer than the limit' => ["```php\nsleep(60);\n```\n", 'sample.md:1'],
        'a loop that prints as it goes' => ["```php\nwhile (true) {\n    echo 'still here';\n}\n```\n", 'sample.md:1'],
        'an endless loop in an earlier block' => [
            "## Section\n\n```php\nwhile (true) {\n}\n```\n\n```php\necho 'never reached';\n```\n",
            'sample.md:8, under "Section", run after the 1 earlier block(s)',
        ],
        'a block marked throws that loops instead' => [
            "<!-- snippet: throws=\"RuntimeException\" -->\n```php\nwhile (true) {\n}\n```\n",
            'sample.md:2',
        ],
    ]);

    // Stopping the wait is not enough: a process left running would burn a
    // core for the rest of the suite, and on a developer's machine after it
    it('leaves no process behind when it stops a block', function (): void {
        if (!function_exists('posix_kill')) {
            // The harness limitation: without the posix extension (Windows) there is no way to ask
            // whether a process id is still alive
            Assert::markTestSkipped('Needs the posix extension to ask whether the stopped process is gone.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'snippet-pid');
        Assert::assertIsString($pidFile);

        try {
            $failure = snippetSampleFailure(
                "```php\nfile_put_contents(" . var_export($pidFile, true) . ", (string) getmypid());\nwhile (true) {\n}\n```\n",
                // Long enough for PHP to start and write its id on a slow machine
                3.0
            );
            $pid = (int) file_get_contents($pidFile);
        } finally {
            unlink($pidFile);
        }

        expect($failure)->toContain('was stopped')
            ->and($pid)->toBeGreaterThan(0)
            // Signal 0 sends nothing; it reports whether the process exists
            ->and(posix_kill($pid, 0))->toBeFalse();
    });

    // What a block wrote before the limit must not be read as its result:
    // a THROWS block that reported its exception and then hung proved nothing
    it('fails a stopped block whatever it printed or threw first', function (string $markdown): void {
        expect(snippetSampleFailure($markdown, 0.5))->toContain('did not finish within 0.5 second(s) and was stopped');
    })->with([
        'output, then a loop' => ["```php\necho \"all done\\n\";\nwhile (true) {\n}\n```\n"],
        'a loop inside a shutdown function' => ["```php\nregister_shutdown_function(static function (): void {\n    while (true) {\n    }\n});\n```\n"],
        'a loop inside a finally' => [
            "<!-- snippet: throws=\"RuntimeException\" -->\n```php\ntry {\n    throw new RuntimeException('x');\n} finally {\n    while (true) {\n    }\n}\n```\n",
        ],
    ]);

    it('does not stop a block that finishes inside the limit', function (): void {
        expect(snippetSampleFailure("```php\nusleep(200_000);\necho 'done';\n```\n", 5.0))->toBeNull();
    });

    it('gives every block of the documents a limit far above what the slowest one needs', function (): void {
        expect(DocumentationSnippets::TIME_LIMIT)->toBeGreaterThanOrEqual(10.0)
            ->toBeLessThanOrEqual(120.0);
    });

    it('fails a block that turns PHP\'s error reporting down', function (string $markdown): void {
        $failure = snippetSampleFailure($markdown);

        expect($failure)->toContain('the php block failed (exit code 1)')
            ->and($failure)->toContain('The block changed error_reporting or display_errors');
    })->with([
        'error_reporting(0)' => ["```php\nerror_reporting(0);\necho \$undefined;\n```\n"],
        'deprecations masked' => ["```php\nerror_reporting(E_ALL & ~E_DEPRECATED);\n```\n"],
        'errors sent to standard output' => ["```php\nini_set('display_errors', '1');\necho \$undefined;\n```\n"],
        'errors hidden' => ["```php\nini_set('display_errors', '0');\necho \$undefined;\n```\n"],
        'in an earlier block' => ["## Section\n\n```php\nerror_reporting(0);\n```\n\n```php\necho \$undefined;\n```\n"],
    ]);

    it('passes a block whose shape could hide the end-of-block check', function (string $code): void {
        expect(snippetSampleFailure("```php\n{$code}\n```\n"))->toBeNull();
    })->with([
        'ends in a line comment' => ['$a = 1; // the last line'],
        'ends in a function declared after its use' => ["echo late();\nfunction late(): string\n{\n    return 'late';\n}"],
        'leaves an output buffer open' => ["ob_start();\necho 'buffered';"],
        'prints the word the harness looks for' => ['echo "finished\\nsnippet-finished\\n";'],
        'silences one call with @' => ['$missing = @file_get_contents(__DIR__ . \'/no-such-file\');'],
        'empty' => [''],
    ]);

    it('names the earlier block when that is the one that fails, and fails every block built on it', function (): void {
        $markdown = "## Section\n\n```php\n\$a = 1;\necho \$undefined;\n```\n\n```php\n\$b = \$a + 1;\n```\n\n```php\n\$c = \$b + 1;\n```\n";
        $extracted = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        $failures = array_map(
            static fn(DocumentationSnippet $snippet): ?string => DocumentationSnippets::run($snippet, $extracted, $autoload),
            $extracted
        );

        // No later block reports success while the block it builds on is broken
        expect($failures[0])->toContain('sample.md:3, under "Section": the php block failed')
            ->and($failures[1])->toContain('sample.md:8, under "Section", run after the 1 earlier block(s)')
            ->and($failures[2])->toContain('sample.md:12, under "Section", run after the 2 earlier block(s)');
        foreach ($failures as $failure) {
            expect($failure)->toContain('Undefined variable $undefined in sample.md:5');
        }
    });

    it('does not take an exception thrown by an earlier block for the one a block is marked to throw', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            throw new RuntimeException('thrown too early');
            ```

            <!-- snippet: throws="RuntimeException" -->
            ```php
            throw new RuntimeException('expected');
            ```
            MARKDOWN);

        expect($failure)->toContain('is marked throws="RuntimeException" but failed differently:')
            ->and($failure)->toContain('Uncaught RuntimeException: thrown too early in sample.md:4');
    });

    it('fails a block marked throws whose class does not exist, or that exits instead of throwing', function (string $marker, string $code, string $reported): void {
        expect(snippetSampleFailure("<!-- snippet: throws=\"{$marker}\" -->\n```php\n{$code}\n```\n"))->toContain($reported);
    })->with([
        'misspelt class' => ['RuntimeExeption', "throw new RuntimeException('x');", 'is marked throws="RuntimeExeption" but threw RuntimeException.'],
        'exit instead of throwing' => ['RuntimeException', 'exit;', 'but ended without throwing.'],
        'exception caught by the block' => ['RuntimeException', "try { throw new RuntimeException('x'); } catch (RuntimeException) { echo 'handled'; }", 'but ended without throwing.'],
        'printing the class name is not throwing it' => ['RuntimeException', 'echo "RuntimeException\\n";', 'but ended without throwing.'],
    ]);

    it('keeps two sections apart when their headings share a text', function (): void {
        $markdown = <<<'MARKDOWN'
            ## Example

            ```php
            $fromTheFirst = 1;
            ```

            ## Another

            ## Example

            ```php
            echo $fromTheFirst;
            ```
            MARKDOWN;

        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect($snippets[0]->section)->toBe($snippets[1]->section)
            ->and($snippets[0]->inSameSectionAs($snippets[1]))->toBeFalse()
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $fromTheFirst in sample.md:12');
    });

    it('starts a section at an underlined heading as well', function (): void {
        $markdown = <<<'MARKDOWN'
            First
            =====

            ```php
            $fromTheFirst = 1;
            ```

            | Not | A heading |
            |-----|-----------|

            - a list item
            ---

            ```php
            assert($fromTheFirst === 1);
            ```

            Second
            ------

            ```php
            echo $fromTheFirst;
            ```
            MARKDOWN;

        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect(array_map(static fn(DocumentationSnippet $snippet): string => $snippet->section, $snippets))
            ->toBe(['First', 'First', 'Second'])
            ->and(DocumentationSnippets::run($snippets[1], $snippets, dirname(__DIR__, 2) . '/vendor/autoload.php'))->toBeNull()
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $fromTheFirst in sample.md:22');
    });

    it('lets a deeper heading with the text of the section heading stay inside the section', function (): void {
        $failure = snippetSampleFailure("## Usage\n\n```php\n\$a = 1;\n```\n\n### Usage\n\n```php\nassert(\$a === 1);\n```\n");

        expect($failure)->toBeNull();
    });

    it('reads a document with Windows line endings like any other', function (): void {
        $markdown = "## Section\r\n\r\n<!-- snippet: throws=\"RuntimeException\" -->\r\n```php\r\nthrow new RuntimeException('x');\r\n```\r\n\r\n```php\r\necho \$undefined;\r\n```\r\n";
        $extracted = DocumentationSnippets::extract('sample.md', $markdown);

        expect($extracted['problems'])->toBe([])
            ->and($extracted['snippets'])->toHaveCount(2)
            ->and($extracted['snippets'][0]->mode)->toBe(DocumentationSnippet::THROWS)
            ->and(DocumentationSnippets::countPhpFences($markdown))->toBe(2)
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $undefined in sample.md:9');
    });

    // The feature test compares the extractor's count with this one, so the
    // two must disagree exactly when the extractor walks past a php fence
    it('counts php fences the way the extractor finds them, and differently when a block is passed over', function (string $markdown, int $extracted, int $counted): void {
        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect(count($snippets))->toBe($extracted)
            ->and(DocumentationSnippets::countPhpFences($markdown))->toBe($counted);
    })->with([
        'no block at all' => ["# Title\n\nProse only.\n", 0, 0],
        'only other languages' => ["```bash\nls\n```\n\n```phpunit\nx\n```\n", 0, 0],
        'an info string after the language' => ["```php title=\"a.php\"\n\$a = 1;\n```\n", 1, 1],
        'a space before the language' => ["``` php\n\$a = 1;\n```\n", 1, 1],
        'upper case and tildes' => ["~~~PHP\n\$a = 1;\n~~~\n", 1, 1],
        'a longer closing fence' => ["```php\n\$a = 1;\n`````\n\n```php\n\$b = 2;\n```\n", 2, 2],
        'a php fence shown inside a longer fence' => ["````markdown\n```php\n\$a = 1;\n```\n````\n", 0, 1],
        'a block swallowed by an unclosed fence' => ["```text\nnever closed\n\n```php\n\$a = 1;\n", 0, 1],
        'a language the extractor does not run' => ["```php-template\n<?= \$a ?>\n```\n", 0, 1],
    ]);

    it('does not close a fence with a shorter one or with the other fence character', function (): void {
        $extracted = DocumentationSnippets::extract('sample.md', "````php\n\$a = '```';\n```\n~~~~\n\$b = 2;\n````\n");

        expect($extracted['problems'])->toBe([])
            ->and($extracted['snippets'])->toHaveCount(1)
            ->and($extracted['snippets'][0]->code)->toBe(["\$a = '```';", '```', '~~~~', '$b = 2;']);
    });

    it('drops a repeated import only, never a different one or a closure use', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            use MissionGaming\Tactician\DTO\Participant as Entrant;

            $prefix = 'p';
            ```

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            use MissionGaming\Tactician\DTO\Participant as Entrant;
            use MissionGaming\Tactician\DTO\Round;

            $make = function (string $id) use ($prefix): Participant {
                return new Entrant($prefix . $id, $id);
            };
            assert($make('1')->getId() === 'p1' && (new Round(2))->getNumber() === 2);
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('fails a block that imports one name for two classes, as PHP would for the reader', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            ```

            ```php
            use MissionGaming\Tactician\DTO\Round as Participant;
            ```
            MARKDOWN);

        expect($failure)->toContain('Cannot use MissionGaming\\Tactician\\DTO\\Round as Participant')
            ->and($failure)->toContain('in sample.md:8');
    });

    it('allows a closure use and a trait use in hidden setup', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            <!-- snippet: setup
            trait Greets
            {
                public function greet(): string
                {
                    return 'hello';
                }
            }

            $greeter = new class () {
                use Greets;
            };
            $suffix = '!';
            $shout = static function (string $text) use ($suffix): string {
                return "{$text}{$suffix}";
            };
            -->

            ```php
            assert($shout($greeter->greet()) === 'hello!');
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('returns what the block printed, without the earlier blocks or its own bookkeeping', function (): void {
        $extracted = DocumentationSnippets::extract(
            'sample.md',
            "## Section\n\n```php\necho \"earlier\\n\";\n```\n\n```php\necho \"line one\\nline two\\n\";\n```\n\n```php\necho \$undefined;\n```\n"
        )['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        expect(DocumentationSnippets::output($extracted[1], $extracted, $autoload))->toBe("line one\nline two\n")
            ->and(static fn(): string => DocumentationSnippets::output($extracted[2], $extracted, $autoload))
            ->toThrow(RuntimeException::class, 'Undefined variable $undefined in sample.md:12');
    });

    it('checks the syntax of a block by itself and reports the document line', function (): void {
        $snippets = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            <!-- snippet: skip reason="A sketch." -->
            ```php
            <?php

            use App\Missing\Contract;

            class Sketch implements Contract
            {
                // ...
            }
            ```

            <!-- snippet: skip reason="Hides a typo." -->
            ```php
            class Broken
            {
                public function run(): void {
                    return $this->
                }
            }
            ```
            MARKDOWN)['snippets'];

        expect(DocumentationSnippets::syntaxError($snippets[0]))->toBeNull()
            ->and(DocumentationSnippets::syntaxError($snippets[1]))->toContain('sample.md:14: the php block is not valid PHP.')
            ->and(DocumentationSnippets::syntaxError($snippets[1]))->toContain('in sample.md:19');
    });

    it('tells a skip marker that is needed from one that is stale', function (): void {
        $snippets = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            ## Section

            ```php
            $a = 1;
            ```

            <!-- snippet: skip reason="Needs an interface that does not exist." -->
            ```php
            class Sketch implements App\Missing\Contract
            {
            }
            ```

            <!-- snippet: skip reason="Left behind after the block was fixed." -->
            ```php
            assert($a === 1);
            ```
            MARKDOWN)['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        expect(DocumentationSnippets::runSkipped($snippets[1], $snippets, $autoload))
            ->toContain('sample.md:8, under "Section", run after the 1 earlier block(s)')
            ->and(DocumentationSnippets::runSkipped($snippets[2], $snippets, $autoload))->toBeNull()
            // Asking the question changes nothing: the block is still skipped
            ->and($snippets[2]->mode)->toBe(DocumentationSnippet::SKIP);
    });

    it('runs hidden setup ahead of the later blocks of its section only', function (): void {
        $setup = <<<'MARKDOWN'
            ## Section

            <!-- snippet: setup
            function playRound(): string
            {
                return 'played';
            }
            -->

            ```php
            assert(playRound() === 'played');
            ```
            MARKDOWN;

        expect(snippetSampleFailure($setup))->toBeNull()
            ->and(snippetSampleFailure($setup . "\n## Next\n\n```php\nplayRound();\n```\n"))
            ->toContain('Call to undefined function playRound() in sample.md:16');
    });
});
