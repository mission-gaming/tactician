<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Random\Engine\Mt19937;
use Random\Randomizer;

// Wherever an entrant list exists and results do not decide an order, the
// position in that list decides it. A label or a seed attribute never does.
// The suite gives the same list, by id and in the same order, two sets of
// labels and seed attributes, and holds every engine and selector to the
// same output for both.

/**
 * A field in list order p1, p2, ... whose labels and seed attributes are
 * drawn from the given seed: labels in no order, some seeds missing, some
 * shared. Two fields of one size differ in nothing but those.
 *
 * @return list<Participant>
 */
function positionField(int $count, int $dressing): array
{
    $randomizer = new Randomizer(new Mt19937($dressing));
    $names = ['Zed', 'amy', 'Amy', 'Bob', 'Cat', 'Player 10', 'Player 2', 'Yan', 'Dee', 'Eve'];

    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant(
            "p{$i}",
            $names[$randomizer->getInt(0, count($names) - 1)] . ' ' . $randomizer->getInt(1, 99),
            $randomizer->getInt(0, 2) === 0 ? null : $randomizer->getInt(1, $count)
        );
    }

    return $participants;
}

/**
 * @param array<Participant> $participants
 * @return list<string>
 */
function positionIds(array $participants): array
{
    return array_values(array_map(fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * Drive a stage to its end with results drawn by seat, and write down
 * everything it gave: each round's events and byes, the outcome's table and
 * what the selectors take from it, all by id.
 *
 * @param list<Participant> $field
 * @param bool $draws Whether an event may finish level (a Swiss stage)
 * @return list<string>
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function positionTranscript(StageEngineInterface $engine, array $field, int $seed, bool $draws, int $legsPerTie = 1): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $state = StageState::start($field);
    $transcript = [];
    $rounds = 0;

    while (!$engine->isComplete($state) && ++$rounds <= 4 * count($field) + 4) {
        try {
            $pairing = $engine->pairNextRound($state);
        } catch (NoValidPairingException) {
            // A late Swiss round with no pairing left: the same for both fields
            $transcript[] = 'no pairing';
            break;
        }

        $results = [];
        $line = "round {$pairing->getRoundNumber()}:";
        foreach ($pairing->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            $line .= " {$first->getId()}-{$second->getId()}";

            $draw = $randomizer->getInt(0, $draws ? 2 : 1);
            // Scores on some events only, so that some entries stay level
            $scores = $randomizer->getInt(0, 3) === 0
                ? [$first->getId() => $draw === 0 ? 2 : 1, $second->getId() => $draw === 1 ? 2 : 1]
                : [];
            $winner = match (true) {
                $legsPerTie === 2 && $event->getMetadataValue('tie_leg') === 2 => null,
                default => [$first, $second, null][$draw],
            };
            $results[] = new Result($event, $winner, $winner === null ? [] : $scores);
        }
        $line .= ' byes ' . implode(',', positionIds($pairing->getByes()));
        $transcript[] = $line;

        $state = $state->withRoundPlayed($pairing, $results);
    }

    $outcome = $engine->getOutcome($state);
    if ($outcome !== null) {
        $transcript[] = 'table: ' . implode(' ', positionIds(array_map(
            fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $outcome->getStandings()->getEntries()
        )));
        $transcript[] = 'entrants: ' . implode(' ', positionIds($outcome->getEntrants()));
        $transcript[] = 'top half: ' . implode(' ', positionIds(
            RankRangeSelector::overall(1, max(1, intdiv(count($field), 2)))->withTableOrderAccepted()->select($outcome)
        ));
        // An event of a Swiss round may be level, which a selector of
        // who advanced refuses: it is for the rounds of a bracket
        $transcript[] = 'last round won by: ' . ($draws
            ? 'not asked'
            : implode(' ', positionIds(MatchOutcomeSelector::winners()->select($outcome))));
    }

    return $transcript;
}

describe('position decides where results do not', function (): void {
    it('gives the same pairings, tables and selections whatever the labels and seed attributes are', function (
        string $name,
        Closure $engine,
        bool $draws,
        int $legsPerTie
    ): void {
        $stages = 0;
        $levelTables = 0;

        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 12, 13, 16] as $size) {
            foreach ([1, 2, 3] as $seed) {
                $reference = positionTranscript($engine(), positionField($size, 1), $seed * 31 + $size, $draws, $legsPerTie);

                foreach ([2, 3] as $dressing) {
                    expect(positionTranscript($engine(), positionField($size, $dressing), $seed * 31 + $size, $draws, $legsPerTie))
                        ->toBe($reference, "{$name}, n={$size}, seed {$seed}, labels and seeds {$dressing}");
                }
                ++$stages;
                $levelTables += str_starts_with($reference[count($reference) - 1], 'last round won by') ? 1 : 0;
            }
        }

        expect($stages)->toBe(33)->and($levelTables)->toBeGreaterThan(20);
    })->with([
        ['Swiss', fn() => new SwissPairingEngine(plannedRounds: 4), true, 1],
        [
            'Swiss with a tiebreaker on the 1/0.5/0 scale',
            fn() => new SwissPairingEngine(
                standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker()]),
                plannedRounds: 4
            ),
            true,
            1,
        ],
        ['single elimination', fn() => new SingleEliminationEngine(), false, 1],
        ['re-seeded single elimination', fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), false, 1],
        [
            'two-legged, re-seeded single elimination',
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, reseedEachRound: true)),
            false,
            2,
        ],
        ['double elimination', fn() => new DoubleEliminationEngine(), false, 1],
    ]);

    it('pairs a pre-drawn Swiss schedule the same whatever the labels and seed attributes are', function (int $size, ?int $seed): void {
        $schedule = fn(int $dressing): array => array_map(
            fn(Event $event): string => $event->getRound()?->getNumber() . ':' . implode('-', positionIds($event->getParticipants())),
            (new SwissScheduler())->schedule(positionField($size, $dressing), new SwissOptions(min(4, $size - 1), $seed))->getEvents()
        );

        expect($schedule(2))->toBe($schedule(1))
            ->and($schedule(3))->toBe($schedule(1));
    })->with([[4], [7], [8], [11], [16]])->with([[null], [2026]]);

    it('selects the same entrants from pools whatever the labels and seed attributes are', function (int $size, int $pools): void {
        $select = function (int $dressing) use ($size, $pools): array {
            $field = positionField($size, $dressing);
            $calculator = new StandingsCalculator();
            $outcomes = [];
            foreach (PoolDistributor::serpentine($field, $pools) as $label => $pool) {
                // Every event of the pool is drawn: nothing in it is decided
                $results = array_map(
                    fn(Event $event): Result => new Result($event),
                    (new RoundRobinScheduler())->schedule($pool)->getEvents()
                );
                $outcomes[$label] = new StageOutcome($calculator->calculate($pool, $results), $results, [], null, [], $pool);
            }
            $combined = StageOutcome::combining($outcomes, $calculator, $field);

            return [
                positionIds(RankRangeSelector::topPerGroup(2)->withTableOrderAccepted()->select($combined)),
                positionIds(RankRangeSelector::overall(1, $pools)->withTableOrderAccepted()->select($combined)),
            ];
        };

        expect($select(2))->toBe($select(1))
            ->and($select(3))->toBe($select(1));

        // And what is selected is the top of each list: with nothing
        // decided, the first two of every pool by position
        $expected = [];
        foreach ([0, 1] as $rank) {
            foreach (PoolDistributor::serpentine(positionField($size, 1), $pools) as $pool) {
                $expected[] = $pool[$rank]->getId();
            }
        }
        $selected = $select(1)[0];
        sort($selected, SORT_STRING);
        sort($expected, SORT_STRING);
        expect($selected)->toBe($expected);

        // Across pools of one size every entrant is level as well, and the
        // combined table is the entrant list of the whole stage. (In pools
        // of unequal size the entrants of the larger pool have a draw more.)
        if ($size % $pools === 0) {
            expect($select(1)[1])->toBe(array_map(fn(int $position): string => "p{$position}", range(1, $pools)));
        }
    })->with([[8, 2], [12, 3], [16, 4], [13, 3]]);
});

describe('Swiss pairing order', function (): void {
    it('pairs an unseeded list in list order, whatever the participants are called', function (): void {
        $pairings = function (array $labels): array {
            $field = [];
            foreach ($labels as $index => $label) {
                $field[] = new Participant('id' . ($index + 1), $label);
            }

            $engine = new SwissPairingEngine(plannedRounds: 3);
            $state = StageState::start($field);
            $rounds = [];
            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                $rounds[] = array_map(fn(Event $event): array => positionIds($event->getParticipants()), $pairing->getEvents());
                // The first-named participant wins
                $state = $state->withRoundPlayed($pairing, array_map(
                    fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                    $pairing->getEvents()
                ));
            }

            return $rounds;
        };

        $listed = $pairings(['Zed', 'Amy', 'Bob', 'Cat']);

        // Round 1 pairs position 1 with 2 and 3 with 4; with no event
        // played the lower-placed participant of a pair is named first
        expect($listed[0])->toBe([['id2', 'id1'], ['id4', 'id3']])
            ->and($pairings(['Amy', 'Bob', 'Cat', 'Zed']))->toBe($listed)
            ->and($pairings(['Cat', 'Zed', 'Amy', 'Bob']))->toBe($listed)
            ->and($pairings(['d', 'c', 'b', 'a']))->toBe($listed);
    });

    it('gives the bye of the first round to the last position, not to the last label or seed', function (): void {
        $field = [
            new Participant('a', 'Zed', 5),
            new Participant('b', 'Yan', 4),
            new Participant('c', 'Bob', 3),
            new Participant('d', 'Amy', 2),
            new Participant('e', 'Abe', 1),
        ];

        $pairing = (new SwissPairingEngine())->pairNextRound(StageState::start($field));

        expect(positionIds($pairing->getByes()))->toBe(['e'])
            ->and(array_map(fn(Event $event): array => positionIds($event->getParticipants()), $pairing->getEvents()))
            ->toBe([['b', 'a'], ['d', 'c']]);
    });

    it('keeps the order of the list among withdrawn and active participants alike', function (): void {
        // Six participants, nothing played: the table of a stage in which
        // p2 has withdrawn lists the five active ones in list order and p2
        // after them, where no result places it
        $field = positionField(6, 2);
        $engine = new SwissPairingEngine(plannedRounds: 1);
        $state = StageState::start($field);
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, [])->withoutParticipant($field[1]);

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        expect(positionIds(array_map(
            fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $outcome->getStandings()->getEntries()
        )))->toBe(['p1', 'p3', 'p4', 'p5', 'p6', 'p2']);
    });

    // A withdrawn participant has no position any more. Two of them that no
    // result separates are in the order of their IDs, and not in the order
    // the recorded events happen to name them in: an application that
    // rebuilds a state from its own rows records the same round with its
    // events in whatever order the rows came back.
    it('orders level withdrawn participants the same whatever order the recorded events are in', function (int $dressing): void {
        $field = positionField(6, $dressing);
        $engine = new SwissPairingEngine(plannedRounds: 1);
        $pairing = $engine->pairNextRound(StageState::start($field));

        $tables = [];
        foreach ([$pairing->getEvents(), array_reverse($pairing->getEvents())] as $events) {
            $state = StageState::start($field)
                ->withRoundPlayed(new RoundPairing(1, null, $events), [])
                ->withoutParticipant($field[3])
                ->withoutParticipant($field[0]);

            $outcome = $engine->getOutcome($state);
            assert($outcome !== null);

            expect(positionIds($outcome->getEntrants()))->toBe(['p2', 'p3', 'p5', 'p6', 'p1', 'p4']);
            $tables[] = positionIds(array_map(
                fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                $outcome->getStandings()->getEntries()
            ));
        }

        expect($tables[0])->toBe(['p2', 'p3', 'p5', 'p6', 'p1', 'p4'])
            ->and($tables[1])->toBe($tables[0]);
    })->with([[1], [2], [3]]);
});

describe('a bye in a Swiss stage', function (): void {
    /**
     * Five participants, three rounds, the first-named participant of every
     * event wins.
     *
     * @return StageOutcome
     */
    $play = function (): StageOutcome {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start(positionField(5, 1));
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        return $outcome;
    };

    it('counts in the final table as it counts for pairing: as a win', function () use ($play): void {
        $outcome = $play();
        $byes = $outcome->getByes();

        expect(array_sum($byes))->toBe(3);

        $previous = null;
        foreach ($outcome->getStandings()->getEntries() as $entry) {
            $id = $entry->getParticipant()->getId();

            // Three points for a win and three for a bye; the record
            // counts results only
            expect($entry->getByes())->toBe($byes[$id] ?? 0)
                ->and($entry->getRankingValue())->toBe(3.0 * ($entry->getWins() + $entry->getByes()))
                ->and($entry->getPlayed())->toBe(3 - $entry->getByes())
                ->and($entry->getWins() + $entry->getLosses())->toBe($entry->getPlayed());

            // The table is in the order of the value that includes the bye
            if ($previous !== null) {
                expect($previous->getRankingValue() >= $entry->getRankingValue())->toBeTrue();
            }
            $previous = $entry;
        }
    });

    it('orders the table the next round would be paired from', function (int $size, int $seed): void {
        // The outcome's table after r rounds is the pairing order of round
        // r + 1: paired without a randomizer, the first event of that round
        // holds the two participants at the top of the table (unless they
        // have met, which the table cannot say), and the bye goes to the
        // lowest-placed participant with the fewest byes
        $randomizer = new Randomizer(new Mt19937($seed));
        $field = positionField($size, 3);
        $state = StageState::start($field);

        for ($round = 1; $round <= 3; ++$round) {
            $pairing = (new SwissPairingEngine())->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, [...$event->getParticipants(), null][$randomizer->getInt(0, 2)]),
                $pairing->getEvents()
            ));

            $outcome = (new SwissPairingEngine(plannedRounds: $round))->getOutcome($state);
            assert($outcome !== null);
            $table = positionIds(array_map(
                fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                $outcome->getStandings()->getEntries()
            ));

            try {
                $next = (new SwissPairingEngine())->pairNextRound($state);
            } catch (NoValidPairingException) {
                continue;
            }

            // The participant at the top of the table is in the first event
            expect(positionIds($next->getEvents()[0]->getParticipants()))->toContain($table[0]);

            // The bye: nobody below the recipient in the table has as few byes
            $byeCounts = $state->getByeCounts();
            foreach ($next->getByes() as $bye) {
                $below = array_slice($table, (int) array_search($bye->getId(), $table, true) + 1);
                foreach ($below as $id) {
                    expect($byeCounts[$id] ?? 0)->toBeGreaterThan($byeCounts[$bye->getId()] ?? 0, "n={$size}, round {$round}: {$id} is below {$bye->getId()}");
                }
            }
        }
    })->with([[5], [7], [9], [11]])->with([[1], [2], [3], [4], [5]]);

    it('is refused in the table, as it is in pairing, on a scale that has no value for a win', function (): void {
        $engine = new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator(new class implements MissionGaming\Tactician\Standings\RankingStrategy {
                #[Override]
                public function rank(Participant $participant, array $results): float
                {
                    return (float) count($results);
                }
            }),
            plannedRounds: 1
        );
        $state = StageState::start(positionField(3, 1));
        $state = $state->withRoundPlayed($engine->pairNextRound($state), []);

        try {
            $engine->getOutcome($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);

            return;
        }

        throw new LogicException('A bye was credited on a scale with no value for a win.');
    });
});

describe('re-seeding an elimination bracket', function (): void {
    /**
     * Play a bracket in which the participant earlier in the list wins
     * every tie, and return each round's ties by list position.
     *
     * @return list<list<array{int, int}>>
     */
    $favouritesWin = function (SingleEliminationEngine $engine, int $size): array {
        $field = positionField($size, 2);
        $position = array_flip(positionIds($field));
        $state = StageState::start($field);
        $rounds = [];

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $ties = [];
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                [$first, $second] = $event->getParticipants();
                $ties[] = [$position[$first->getId()] + 1, $position[$second->getId()] + 1];
                $results[] = new Result($event, $position[$first->getId()] < $position[$second->getId()] ? $first : $second);
            }
            $rounds[] = $ties;
            $state = $state->withRoundPlayed($pairing, $results);
        }

        return $rounds;
    };

    it('keeps the top two positions apart until the final in a field of five', function () use ($favouritesWin): void {
        $rounds = $favouritesWin(new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), 5);

        expect($rounds)->toBe([
            [[4, 5]],
            [[1, 4], [2, 3]],
            [[1, 2]],
        ]);
    });

    it('gives the entrants who had a bye the top places of the next round in a field of twelve', function () use ($favouritesWin): void {
        $rounds = $favouritesWin(new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), 12);

        expect($rounds[1])->toBe([[1, 8], [4, 5], [2, 7], [3, 6]]);
    });

    // When the better-placed entrant wins every tie and no score is
    // recorded, the survivors of a round are level and re-seeding has
    // nothing to change: it is the bracket of the fixed path.
    it('pairs every round as the fixed path does when the better-placed entrant always wins', function () use ($favouritesWin): void {
        for ($size = 2; $size <= 33; ++$size) {
            foreach ([1, 2] as $legs) {
                expect($favouritesWin(new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legs, reseedEachRound: true)), $size))
                    ->toBe($favouritesWin(new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legs)), $size), "n={$size}, {$legs} leg(s)");
            }
        }
    });

    it('refuses a bye it cannot credit on a scale that has no value for a win', function (): void {
        $engine = new SingleEliminationEngine(
            new EliminationOptions(reseedEachRound: true),
            new StandingsCalculator(new class implements MissionGaming\Tactician\Standings\RankingStrategy {
                #[Override]
                public function rank(Participant $participant, array $results): float
                {
                    return (float) count($results);
                }
            })
        );
        $state = StageState::start(positionField(3, 1));
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [new Result($first->getEvents()[0], $first->getEvents()[0]->getParticipants()[0])]);

        try {
            $engine->pairNextRound($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);

            return;
        }

        throw new LogicException('A bye was credited on a scale with no value for a win.');
    });

    // A state stored by 0.2.x. Five entrants, re-seeded: 0.2.x ranked the
    // three who had a bye below the winner of round 1 and paired the second
    // round p4 v p3 and p1 v p2. This engine pairs it p1 v p4 and p2 v p3,
    // so the recorded round is not a round of its bracket, and reading its
    // results against other ties would be wrong.
    it('refuses a stored state whose recorded round was paired in the order of an earlier release', function (): void {
        $field = positionField(5, 1);
        [$p1, $p2, $p3, $p4] = $field;
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));

        $state = StageState::start($field);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [new Result($first->getEvents()[0], $p4)]);

        $round = new Round(2, ['label' => 'semifinal']);
        $asStored = $state->withRoundPlayed(
            new RoundPairing(2, 'semifinal', [new Event([$p4, $p3], $round), new Event([$p1, $p2], $round)]),
            []
        );
        $restored = StageState::fromJson($asStored->toJson());

        foreach ([
            fn() => $engine->pairNextRound($restored),
            fn() => $engine->isComplete($restored),
            fn() => $engine->getOutcome($restored),
        ] as $call) {
            try {
                $call();
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                    ->and($exception->getMessage())->toContain('The recorded round 2 is not the round 2 this bracket has')
                    ->and($exception->getContext()['round'] ?? null)->toBe(2);

                continue;
            }

            throw new LogicException('A round paired under other rules was read as a round of this bracket.');
        }

        // The round as this engine pairs it is accepted
        $second = $engine->pairNextRound($state);
        expect($engine->isComplete($state->withRoundPlayed($second, [])))->toBeFalse();
    });

    it('still reads a stored state whose recorded rounds are the rounds it pairs', function (): void {
        // The same bracket on a fixed path: nothing about its pairing
        // changed, so a state stored by an earlier release resolves as before
        $field = positionField(5, 1);
        $engine = new SingleEliminationEngine();
        $state = StageState::start($field);
        $first = $engine->pairNextRound($state);
        $state = StageState::fromJson($state->withRoundPlayed($first, [new Result($first->getEvents()[0], $field[3])])->toJson());

        expect(array_map(fn(Event $event): array => positionIds($event->getParticipants()), $engine->pairNextRound($state)->getEvents()))
            ->toBe([['p1', 'p4'], ['p2', 'p3']]);
    });
});
