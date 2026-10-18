<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\RankingValueTiebreaker;
use MissionGaming\Tactician\Standings\ScoreDifferenceTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The table of a bracket is ordered by its tiers (the round an entrant went
// out in) and, inside a tier, by records in which every bye of the bracket
// is credited and a tiebreaker between the tied participants is computed
// over the tied participants of the tier only
// (docs/adr/0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md).

/**
 * @return list<Participant>
 */
function bracketTableField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "P{$i}");
    }

    return $field;
}

/**
 * Play a bracket to its end. Each tie is decided in its first leg, by a
 * draw of the generator, with a score; a second leg is drawn level.
 *
 * @param list<Participant> $field
 *
 * @throws InvalidConfigurationException When the engine refuses the bracket (not expected here)
 * @throws NoValidPairingException When the engine has no round to pair (not expected here)
 */
function bracketTablePlayed(StageEngineInterface $engine, array $field, int $seed): StageState
{
    $rng = new Randomizer(new Mt19937($seed));
    $state = StageState::start($field);
    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        $results = [];
        foreach ($pairing->getEvents() as $event) {
            [$first, $second] = array_values($event->getParticipants());
            if ($event->getMetadataValue('tie_leg') === 2) {
                $level = $rng->getInt(0, 2);
                $results[] = new Result($event, null, [$first->getId() => $level, $second->getId() => $level]);

                continue;
            }
            [$winner, $loser] = $rng->getInt(0, 1) === 0 ? [$first, $second] : [$second, $first];
            $for = $rng->getInt(1, 4);
            $results[] = new Result($event, $winner, [$winner->getId() => $for, $loser->getId() => $rng->getInt(0, $for - 1)]);
        }
        $state = $state->withRoundPlayed($pairing, $results);
    }

    return $state;
}

/**
 * The IDs of a table in order, each with its tier.
 *
 * @return list<string>
 */
function bracketTableOrder(Standings $table): array
{
    $tiers = $table->getTiers();

    return array_map(
        static fn(int $position, StandingEntry $entry): string => $entry->getParticipant()->getId() . ' ' . $tiers[$position],
        array_keys(array_values($table->getEntries())),
        array_values($table->getEntries())
    );
}

describe('a tiebreaker between the tied participants in the table of a bracket', function (): void {
    // Eight entrants, two legs a tie, with the ranking value between the
    // tied participants as the one tiebreaker. p1 and p4 each win a leg of
    // their quarter-final and go out on a decider: both are 1-1, on 3
    // points, in the tier of the quarter-finals, and they never met. p5,
    // who put p4 out, loses its semifinal in two legs and goes out on 3
    // points too, in the tier above. The tied set of p4 is p1 and p4: p4's
    // leg won from p5, an entrant of another tier, counts for nothing, and
    // nothing separates the two, so the entrant list puts p1 first.
    it('forms the tied sets inside a tier, so an event against another tier counts for nothing', function (): void {
        $engine = new SingleEliminationEngine(
            new EliminationOptions(legsPerTie: 2),
            new StandingsCalculator(new WinDrawLossRanking(), [new BetweenTiedParticipants(new RankingValueTiebreaker())])
        );
        $field = bracketTableField(8);
        $byId = [];
        foreach ($field as $participant) {
            $byId[$participant->getId()] = $participant;
        }

        // Who wins each leg, by tie; a decider where the legs are level
        $legWinners = [
            'p1 p8' => ['p1', 'p8', 'p8'],
            'p4 p5' => ['p4', 'p5', 'p5'],
            'p2 p7' => ['p2', 'p2', null],
            'p3 p6' => ['p3', 'p3', null],
            'p5 p8' => ['p8', 'p8', null],
            'p2 p3' => ['p2', 'p2', null],
            'p2 p8' => ['p8', 'p8', null],
        ];

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
                sort($ids);
                [$firstLeg, $secondLeg, $decided] = $legWinners[implode(' ', $ids)];
                $leg = $event->getMetadataValue('tie_leg') === 2 ? $secondLeg : $firstLeg;
                $results[] = new Result(
                    $event,
                    $byId[$leg],
                    [],
                    decider: $event->getMetadataValue('tie_leg') === 2 && $decided !== null ? new Decider([$decided], 'away goals') : null
                );
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }

        $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');
        $p1 = $table->getEntryFor($byId['p1']);
        $p4 = $table->getEntryFor($byId['p4']);
        $p5 = $table->getEntryFor($byId['p5']);

        expect(bracketTableOrder($table))->toBe(['p8 0', 'p2 1', 'p3 2', 'p5 2', 'p1 3', 'p4 3', 'p6 3', 'p7 3'])
            ->and([$p1?->getRankingValue(), $p4?->getRankingValue(), $p5?->getRankingValue()])->toBe([3.0, 3.0, 3.0])
            ->and($p1?->getTiebreakerValue('between-tied:ranking-value'))->toBe(0.0)
            ->and($p4?->getTiebreakerValue('between-tied:ranking-value'))->toBe(0.0)
            // p1 and p4 are reported as level: the entrant list ordered them
            ->and(array_map(
                static fn($set): array => array_map(static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(), $set->getEntries()),
                $table->getTiedSets()
            ))->toContain(['p1', 'p4']);
    });

    // A subclass of the calculator forms its tied sets over the whole table
    // in its own calculate(), so it cannot form them inside a tier, and the
    // table of every bracket has tiers. That is known from the engine
    // alone, so the bracket is refused before its first round is paired,
    // and not once it has been played to the end and its table is asked for.
    it('refuses a subclass of the calculator with such a tiebreaker before the first round', function (StageEngineInterface $engine): void {
        $state = StageState::start(bracketTableField(4));

        try {
            $engine->pairNextRound($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($exception->getMessage())->toContain('subclass');

            return;
        }

        throw new LogicException('A bracket whose table the calculator cannot form was paired.');
    })->with(function (): array {
        $calculator = new readonly class (new WinDrawLossRanking(), [new BetweenTiedParticipants(new RankingValueTiebreaker())]) extends StandingsCalculator {};

        return [
            'single elimination' => [new SingleEliminationEngine(new EliminationOptions(), $calculator)],
            'double elimination' => [new DoubleEliminationEngine(new EliminationOptions(), $calculator)],
        ];
    });

    it('plays a bracket with a subclass of the calculator without such a tiebreaker', function (StageEngineInterface $engine): void {
        $state = bracketTablePlayed($engine, bracketTableField(6), 7);

        expect($engine->getOutcome($state)?->getStandings()->getTiers())->toHaveCount(6);
    })->with(function (): array {
        $calculator = new readonly class (new WinDrawLossRanking(), [new ScoreDifferenceTiebreaker()]) extends StandingsCalculator {};

        return [
            'single elimination' => [new SingleEliminationEngine(new EliminationOptions(), $calculator)],
            'double elimination' => [new DoubleEliminationEngine(new EliminationOptions(), $calculator)],
        ];
    });
});

describe('a bye in the table of a bracket', function (): void {
    // Six entrants: p1 and p2 have the byes of the first round, the
    // first-named entrant wins every tie except that p1 loses its
    // semifinal. p1 (0-1 and a bye) and p3 (1-1: a quarter-final won) share
    // the tier of the semifinals. The bye is worth a win, so the two are
    // level on 3 points and the entrant list puts p1 first.
    it('is credited as the ranking strategy values it, so it counts as the tie it stands for', function (): void {
        $engine = new SingleEliminationEngine();
        $field = bracketTableField(6);

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                static function (Event $event): Result {
                    [$first, $second] = array_values($event->getParticipants());

                    return new Result($event, $first->getId() === 'p1' ? $second : $first);
                },
                $pairing->getEvents()
            ));
        }

        $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');
        $lines = array_map(
            static fn(StandingEntry $entry): string => sprintf(
                '%s %d-%d %d bye %s',
                $entry->getParticipant()->getId(),
                $entry->getWins(),
                $entry->getLosses(),
                $entry->getByes(),
                $entry->getRankingValue()
            ),
            $table->getEntries()
        );

        expect($lines)->toBe([
            'p4 3-0 0 bye 9',
            'p2 1-1 1 bye 6',
            'p1 0-1 1 bye 3',
            'p3 1-1 0 bye 3',
            'p5 0-1 0 bye 0',
            'p6 0-1 0 bye 0',
        ])
            ->and($table->getTiers())->toBe([0, 1, 2, 2, 3, 3]);
    });

    it('is credited once for each leg of a tie', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = bracketTablePlayed($engine, bracketTableField(5), 3);
        $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');

        $credited = 0;
        foreach ($table->getEntries() as $entry) {
            expect($entry->getEarnedRankingValue())->toBe($entry->getRankingValueOfResults() + $entry->getByes() * 2 * 3.0);
            $credited += $entry->getByes();
        }
        expect($credited)->toBe(3);
    });
});

describe('the table of a bracket, over generated brackets', function (): void {
    /**
     * The engines, by name, with the chain the table is ordered by.
     *
     * @var array<string, Closure(StandingsCalculator): StageEngineInterface>
     */
    $engines = [
        'single elimination' => static fn(StandingsCalculator $calculator): StageEngineInterface => new SingleEliminationEngine(new EliminationOptions(), $calculator),
        're-seeded' => static fn(StandingsCalculator $calculator): StageEngineInterface => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true), $calculator),
        'two legs' => static fn(StandingsCalculator $calculator): StageEngineInterface => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2), $calculator),
        'third-place tie' => static fn(StandingsCalculator $calculator): StageEngineInterface => new SingleEliminationEngine(new EliminationOptions(thirdPlace: true), $calculator),
        'double elimination' => static fn(StandingsCalculator $calculator): StageEngineInterface => new DoubleEliminationEngine(new EliminationOptions(), $calculator),
    ];

    // In single elimination an entrant goes on from every round it is in
    // except the one it goes out in, and every advance that is no tie won
    // is a bye: so its byes are the rounds it went on from less the ties it
    // won, worked out from the tiers and the results alone. That is also
    // the count the ranking of a re-seeded round credits.
    it('credits every advance without a tie, each at the value of a bye for each leg', function (string $name) use ($engines): void {
        $legs = $name === 'two legs' ? 2 : 1;
        $credited = 0;
        for ($size = 3; $size <= 16; ++$size) {
            $rounds = (int) ceil(log($size, 2));
            foreach ([1, 2, 3] as $seed) {
                $engine = $engines[$name](new StandingsCalculator());
                $state = bracketTablePlayed($engine, bracketTableField($size), $seed);
                $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');

                $tiesWon = [];
                foreach ($state->getResults() as $result) {
                    $winner = $result->getWinner();
                    if ($winner !== null) {
                        $tiesWon[$winner->getId()] = ($tiesWon[$winner->getId()] ?? 0) + 1;
                    }
                }

                foreach (array_values($table->getEntries()) as $position => $entry) {
                    $id = $entry->getParticipant()->getId();
                    $tier = $table->getTiers()[$position];
                    $advances = $tier === 0 ? $rounds : $rounds - $tier;

                    expect($entry->getByes())->toBe($advances - ($tiesWon[$id] ?? 0), "{$name}, {$size} entrants, seed {$seed}, {$id}")
                        ->and($entry->getEarnedRankingValue())->toBe($entry->getRankingValueOfResults() + $entry->getByes() * $legs * 3.0);
                    $credited += $entry->getByes();
                }
            }
        }

        expect($credited)->toBeGreaterThan(100);
        // A double elimination bracket has no round count to read the
        // advances from, and a third-place tie splits a tier: both are held
        // to the figures of the last test of this group instead
    })->with(['single elimination', 're-seeded', 'two legs']);

    // Inside a tier the entries are ordered by the ranking value, the
    // ranking value and the score difference of the events among the
    // entrants of the tier level on the ranking value, the score
    // difference and the scores-for, and then by the entrant list: worked
    // out here from the results, the byes and the tiers alone.
    it('orders a tier by figures of the tier alone, and by the entrant list where they are level', function (string $name) use ($engines): void {
        $calculator = new StandingsCalculator(new WinDrawLossRanking(), [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()),
        ]);
        $separated = 0;

        for ($size = 3; $size <= 16; ++$size) {
            foreach ([1, 2, 3, 4] as $seed) {
                $engine = $engines[$name]($calculator);
                $field = bracketTableField($size);
                $state = bracketTablePlayed($engine, $field, $seed);
                $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');

                $position = array_flip(array_map(static fn(Participant $participant): string => $participant->getId(), $field));
                $lines = [];
                foreach (array_values($table->getEntries()) as $index => $entry) {
                    $lines[$entry->getParticipant()->getId()] = [
                        'tier' => $table->getTiers()[$index],
                        'value' => $entry->getRankingValue(),
                        'difference' => $entry->getScoreDifference(),
                        'for' => $entry->getScoreFor(),
                    ];
                }

                // The figures between the tied: the events whose two
                // entrants share a tier and a ranking value. Those of the
                // events whose two entrants share a ranking value in two
                // tiers are counted apart, to show that the brackets have
                // them
                $between = $acrossTiers = array_fill_keys(array_keys($lines), ['value' => 0.0, 'difference' => 0.0]);
                foreach ($state->getResults() as $result) {
                    [$one, $other] = array_values($result->getEvent()->getParticipants());
                    if ($lines[$one->getId()]['value'] !== $lines[$other->getId()]['value']) {
                        continue;
                    }
                    $sameTier = $lines[$one->getId()]['tier'] === $lines[$other->getId()]['tier'];
                    foreach ([[$one, $other], [$other, $one]] as [$self, $opponent]) {
                        $winner = $result->getWinner()?->getId();
                        $value = $winner === $self->getId() ? 3.0 : ($winner === null ? 1.0 : 0.0);
                        $difference = (float) $result->getScoreFor($self) - (float) $result->getScoreFor($opponent);
                        $id = $self->getId();
                        if ($sameTier) {
                            $between[$id] = ['value' => $between[$id]['value'] + $value, 'difference' => $between[$id]['difference'] + $difference];
                        } else {
                            $acrossTiers[$id] = ['value' => $acrossTiers[$id]['value'] + $value, 'difference' => $acrossTiers[$id]['difference'] + $difference];
                        }
                    }
                }

                $expected = array_keys($lines);
                usort($expected, static fn(string $one, string $other): int => [
                    $lines[$one]['tier'],
                    -$lines[$one]['value'],
                    -$between[$one]['value'],
                    -$between[$one]['difference'],
                    -$lines[$one]['difference'],
                    -$lines[$one]['for'],
                    $position[$one],
                ] <=> [
                    $lines[$other]['tier'],
                    -$lines[$other]['value'],
                    -$between[$other]['value'],
                    -$between[$other]['difference'],
                    -$lines[$other]['difference'],
                    -$lines[$other]['for'],
                    $position[$other],
                ]);

                $label = "{$name}, {$size} entrants, seed {$seed}";
                expect(array_keys($lines))->toBe($expected, $label);
                foreach ($table->getEntries() as $entry) {
                    $id = $entry->getParticipant()->getId();
                    expect($entry->getTiebreakerValue('between-tied:ranking-value'))->toBe($between[$id]['value'], "{$label}, {$id}")
                        ->and($entry->getTiebreakerValue('between-tied:score-difference'))->toBe($between[$id]['difference'] + 0.0, "{$label}, {$id}");
                    $separated += $acrossTiers[$id] !== ['value' => 0.0, 'difference' => 0.0] ? 1 : 0;
                }
            }
        }

        // The brackets have entrants who met and went out in two tiers with
        // one ranking value: a calculation over the whole table would have
        // counted their events (the third-place tie, and the losers bracket,
        // have them). The other brackets have none: with every bye credited
        // and every tie decided in its first leg, an entrant's ranking value
        // is fixed by the round it went out in, so one value is one tier
        if (in_array($name, ['single elimination', 're-seeded', 'two legs'], true)) {
            expect($separated)->toBe(0);

            return;
        }
        expect($separated)->toBeGreaterThan(10);
    })->with(array_keys($engines));

    it('has figures that add up, with each bye credited', function (string $name) use ($engines): void {
        for ($size = 3; $size <= 16; ++$size) {
            $legs = $name === 'two legs' ? 2 : 1;
            $engine = $engines[$name](new StandingsCalculator());
            $state = bracketTablePlayed($engine, bracketTableField($size), $size);
            $table = $engine->getOutcome($state)?->getStandings() ?? throw new LogicException('The bracket is not over.');

            foreach ($table->getEntries() as $entry) {
                $id = $entry->getParticipant()->getId();

                // Every bye a round lists is credited; a bracket can credit
                // more (an advance through a stage that took no round number)
                expect($entry->getByes())->toBeGreaterThanOrEqual($state->getByeCounts()[$id] ?? 0)
                    ->and($entry->getRankingValueOfResults())->toBe(3.0 * $entry->getWins() + 1.0 * $entry->getDraws())
                    ->and($entry->getEarnedRankingValue())->toBe($entry->getRankingValueOfResults() + $entry->getByes() * $legs * 3.0);
            }
        }
    })->with(array_keys($engines));
});
