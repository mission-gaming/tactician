<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Record a played round onto a state from its results (and any byes),
 * building the pairing the engine would have produced.
 *
 * @param array<Result> $results
 * @param array<Participant> $byes
 * @throws InvalidConfigurationException When a result belongs to a different round
 */
function withSwissRound(StageState $state, int $round, array $results, array $byes = []): StageState
{
    $events = array_map(fn(Result $result) => $result->getEvent(), $results);

    return $state->withRoundPlayed(new RoundPairing($round, null, $events, $byes), $results);
}

/**
 * @param array<Event> $events
 * @return array<string> Sorted 'id-id' keys
 */
function swissPairKeys(array $events): array
{
    $pairKeys = array_map(function (Event $event) {
        $ids = array_map(fn(Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);

        return implode('-', $ids);
    }, $events);
    sort($pairKeys);

    return $pairKeys;
}

describe('SwissPairingEngine', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->eve = new Participant('p5', 'Eve');
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('is a stage engine', function (): void {
        expect(new SwissPairingEngine())->toBeInstanceOf(StageEngineInterface::class);
    });

    it('pairs round 1 adjacently in standings order', function (): void {
        $pairing = new SwissPairingEngine()->pairNextRound(StageState::start($this->participants));

        expect($pairing->getRoundNumber())->toBe(1);
        expect($pairing->getLabel())->toBeNull();
        expect($pairing->hasByes())->toBeFalse();

        $pairs = array_map(
            fn(Event $event) => array_map(fn(Participant $p) => $p->getId(), $event->getParticipants()),
            $pairing->getEvents()
        );

        // All on zero points, ordered by label: Alice-Bob, Carol-Dave.
        // Home counts are tied at zero, so the lower-placed participant is home.
        expect($pairs)->toBe([['p2', 'p1'], ['p4', 'p3']]);
    });

    it('pairs winners against winners in round 2', function (): void {
        $state = withSwissRound(StageState::start($this->participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ]);

        $pairing = new SwissPairingEngine()->pairNextRound($state);

        expect($pairing->getRoundNumber())->toBe(2);

        // Winners (Alice, Carol) meet; losers (Bob, Dave) meet
        expect(swissPairKeys($pairing->getEvents()))->toBe(['p1-p3', 'p2-p4']);
    });

    it('avoids repeat pairings by backtracking to lower-placed opponents', function (): void {
        $state = StageState::start($this->participants);
        $state = withSwissRound($state, 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ]);
        $state = withSwissRound($state, 2, [
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
            new Result(new Event([$this->bob, $this->dave], new Round(2)), $this->bob),
        ]);

        $pairing = new SwissPairingEngine()->pairNextRound($state);

        expect($pairing->getRoundNumber())->toBe(3);

        // Alice has played Bob and Carol; only Dave remains for her
        expect(swissPairKeys($pairing->getEvents()))->toBe(['p1-p4', 'p2-p3']);
    });

    // Repeat avoidance reads the recorded pairings, not the results, so
    // rounds recorded without results still exclude their pairings. Pairing
    // on from such rounds is results-free mode.
    it('avoids repeats from rounds recorded without results', function (): void {
        $roundOneEvents = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->carol, $this->dave], new Round(1)),
        ];
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, $roundOneEvents), []);

        $pairing = new SwissPairingEngine(resultsFree: true)->pairNextRound($state);

        expect($pairing->getRoundNumber())->toBe(2);
        expect(swissPairKeys($pairing->getEvents()))->not->toContain('p1-p2');
        expect(swissPairKeys($pairing->getEvents()))->not->toContain('p3-p4');
    });

    it('balances home assignments and gives ties to the lower-placed participant', function (): void {
        $state = withSwissRound(StageState::start($this->participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ]);

        $pairing = new SwissPairingEngine()->pairNextRound($state);

        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(fn(Participant $p) => $p->getId(), $event->getParticipants());
            if (in_array('p1', $ids, true)) {
                // Alice and Carol both had one home game: the lower-placed
                // participant (Carol) gets home
                expect($ids)->toBe(['p3', 'p1']);
            } else {
                // Bob and Dave both had none: lower-placed Dave gets home
                expect($ids)->toBe(['p4', 'p2']);
            }
        }
    });

    it('gives the bye to the lowest-placed participant without one', function (): void {
        $participants = [...$this->participants, $this->eve];

        $round1 = new SwissPairingEngine()->pairNextRound(StageState::start($participants));
        expect($round1->hasByes())->toBeTrue();
        // All tied on zero points, label order: Eve is lowest placed
        expect($round1->getByes()[0]->getId())->toBe('p5');

        // With Eve's bye recorded, the next-lowest without a bye sits out
        $state = withSwissRound(StageState::start($participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ], [$this->eve]);
        $round2 = new SwissPairingEngine()->pairNextRound($state);

        expect($round2->hasByes())->toBeTrue();
        expect($round2->getByes()[0]->getId())->not->toBe('p5');
        expect($round2->getEvents())->toHaveCount(2);
    });

    // A ranking strategy that does not say what a bye is worth (it is no
    // RankingStrategyWithByeValue) has the bye counted and credited with
    // nothing, as a bracket does under it (ADR 0019): the stage is paired on.
    describe('under a ranking strategy that does not say what a bye is worth', function (): void {
        beforeEach(function (): void {
            // Three for a win, one for a draw, and nothing said of a bye
            $this->threePerWin = new readonly class implements RankingStrategy {
                /**
                 * @param array<Result> $results
                 */
                #[Override]
                public function rank(Participant $participant, array $results): float
                {
                    $value = 0.0;
                    foreach ($results as $result) {
                        $value += $result->isWinFor($participant) ? 3.0 : ($result->isDraw() ? 1.0 : 0.0);
                    }

                    return $value;
                }
            };
            $this->frank = new Participant('p6', 'Frank');
            $this->grace = new Participant('p7', 'Grace');
            $this->seven = [...$this->participants, $this->eve, $this->frank, $this->grace];

            // Round 1: Grace has the bye; Alice, Carol and Eve win
            $this->afterRoundOne = withSwissRound(StageState::start($this->seven), 1, [
                new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
                new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
                new Result(new Event([$this->eve, $this->frank], new Round(1)), $this->eve),
            ], [$this->grace]);
        });

        it('counts the bye and credits it with nothing in the table', function (): void {
            $engine = new SwissPairingEngine(standingsCalculator: new StandingsCalculator($this->threePerWin));
            $table = $engine->getStandings($this->afterRoundOne);

            $grace = $table->getEntryFor($this->grace);
            expect($grace?->getByes())->toBe(1)
                ->and($grace?->getPlayed())->toBe(0)
                ->and($grace?->getRankingValue())->toBe(0.0)
                ->and($grace?->getEarnedRankingValue())->toBe(0.0)
                // With the losers, after them in list order
                ->and(array_map(static fn($entry): string => $entry->getParticipant()->getId(), $table->getEntries()))
                ->toBe(['p1', 'p3', 'p5', 'p2', 'p4', 'p6', 'p7']);
        });

        it('pairs the participant who sat out among those it is level with, and gives the next bye to one who has had none', function (): void {
            $engine = new SwissPairingEngine(standingsCalculator: new StandingsCalculator($this->threePerWin));

            $round2 = $engine->pairNextRound($this->afterRoundOne);

            // Grace is last on 0 and has had a bye, so Frank, the lowest
            // placed without one, sits out; Grace meets Dave among the losers.
            // A bye worth a win pairs her with Eve instead (the test below).
            expect($round2->getByes()[0]->getId())->toBe('p6')
                ->and(swissPairKeys($round2->getEvents()))->toBe(['p1-p3', 'p2-p5', 'p4-p7']);
        });

        it('pairs a field a withdrawal makes odd, and ends with a table that credits the bye with nothing', function (): void {
            $engine = new SwissPairingEngine(standingsCalculator: new StandingsCalculator($this->threePerWin), plannedRounds: 3);

            // Four play round 1; Dave then withdraws, which leaves three
            $state = withSwissRound(StageState::start($this->participants), 1, [
                new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
                new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
            ])->withoutParticipant($this->dave);

            // Bob, the lowest placed of the three, sits round 2 out
            $round2 = $engine->pairNextRound($state);
            expect($round2->getByes()[0]->getId())->toBe('p2')
                ->and(swissPairKeys($round2->getEvents()))->toBe(['p1-p3']);
            $state = $state->withRoundPlayed($round2, [new Result($round2->getEvents()[0], $this->carol)]);

            // Bob has had his bye: Alice, the lowest placed of the two who
            // have not, sits round 3 out, and Bob meets Carol
            $round3 = $engine->pairNextRound($state);
            expect($round3->getByes()[0]->getId())->toBe('p1')
                ->and(swissPairKeys($round3->getEvents()))->toBe(['p2-p3']);
            $state = $state->withRoundPlayed($round3, [new Result($round3->getEvents()[0], $this->bob)]);

            $outcome = $engine->getOutcome($state);
            expect($outcome)->not->toBeNull();
            $values = [];
            foreach ($outcome?->getStandings() ?? [] as $entry) {
                $values[$entry->getParticipant()->getId()] = [$entry->getRankingValue(), $entry->getByes()];
            }

            expect($values)->toBe([
                'p3' => [6.0, 0],
                'p1' => [3.0, 1],
                'p2' => [3.0, 1],
                'p4' => [0.0, 0],
            ])->and($outcome?->getByes())->toBe(['p2' => 1, 'p1' => 1]);
        });

        // A round after a bye has a derivation now, where the engine refused
        // to pair one before: the rounds it paired are not reported as manual
        it('derives every recorded round after a bye, and reports none it paired as manual', function (): void {
            $engine = new SwissPairingEngine(standingsCalculator: new StandingsCalculator($this->threePerWin), plannedRounds: 4);

            $state = StageState::start($this->seven);
            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                $state = $state->withRoundPlayed($pairing, array_map(
                    static fn(Event $event): Result => new Result($event, $event->getParticipants()[1]),
                    $pairing->getEvents()
                ));
            }

            $recorded = $engine->getRecordedRounds($state);
            expect($recorded)->toHaveCount(4);
            foreach ($recorded as $round) {
                expect($round->getDerivation())->not->toBeNull()
                    ->and($round->isManual())->toBeFalse();
            }
        });
    });

    it('credits a bye as a win when ordering the next round', function (): void {
        $frank = new Participant('p6', 'Frank');
        $grace = new Participant('p7', 'Grace');
        $participants = [...$this->participants, $this->eve, $frank, $grace];

        // Round 1: Grace has the bye; Alice, Carol, and Eve win.
        $state = withSwissRound(StageState::start($participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
            new Result(new Event([$this->eve, $frank], new Round(1)), $this->eve),
        ], [$grace]);

        $round2 = new SwissPairingEngine()->pairNextRound($state);

        // Grace's bye counts as a win, so she pairs among the winners
        // (against Eve, the third-placed winner), and the losers pair off.
        expect($round2->getByes()[0]->getId())->toBe('p6'); // lowest-placed without a bye
        expect(swissPairKeys($round2->getEvents()))->toBe(['p1-p3', 'p2-p4', 'p5-p7']);
    });

    it('continues pairing after a participant withdraws', function (): void {
        // Round 1 played by all four; Dave then withdraws
        $state = withSwissRound(StageState::start($this->participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->dave),
        ]);
        $state = $state->withoutParticipant($this->dave);

        $pairing = new SwissPairingEngine()->pairNextRound($state);

        expect($pairing->getRoundNumber())->toBe(2);
        expect($pairing->getEvents())->toHaveCount(1);
        expect($pairing->hasByes())->toBeTrue();

        // Dave is never paired despite appearing in the results
        $pairedIds = array_map(fn(Participant $p) => $p->getId(), $pairing->getEvents()[0]->getParticipants());
        expect($pairedIds)->not->toContain('p4');
        expect($pairing->getByes()[0]->getId())->not->toBe('p4');
    });

    it('exposes planned rounds to constraints via the context', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}", $i);
        }

        // Protect the top 2 seeds for 50% of a 7-round tournament (rounds 1-3)
        $constraints = ConstraintSet::create()
            ->add(new SeedProtectionConstraint(2, 0.5))
            ->build();
        $engine = new SwissPairingEngine($constraints, plannedRounds: 7);

        // Round 1: seeds 1 and 2 both win
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);
        $results = [];
        foreach ($round1->getEvents() as $event) {
            $ps = $event->getParticipants();
            $winner = ($ps[0]->getSeed() ?? PHP_INT_MAX) < ($ps[1]->getSeed() ?? PHP_INT_MAX) ? $ps[0] : $ps[1];
            $results[] = new Result($event, $winner);
        }
        $state = $state->withRoundPlayed($round1, $results);

        // Without plannedRounds the protection window would collapse to zero
        // and the two 1-0 top seeds would meet in round 2
        $round2 = $engine->pairNextRound($state);
        foreach ($round2->getEvents() as $event) {
            $seeds = array_map(fn(Participant $p) => $p->getSeed(), $event->getParticipants());
            sort($seeds);
            expect($seeds)->not->toBe([1, 2]);
        }
    });

    it('throws when every pairing has already been played', function (): void {
        $state = StageState::start($this->participants);
        $state = withSwissRound($state, 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ]);
        $state = withSwissRound($state, 2, [
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
            new Result(new Event([$this->bob, $this->dave], new Round(2)), $this->bob),
        ]);
        $state = withSwissRound($state, 3, [
            new Result(new Event([$this->dave, $this->alice], new Round(3)), $this->alice),
            new Result(new Event([$this->carol, $this->bob], new Round(3)), $this->bob),
        ]);

        expect(fn() => new SwissPairingEngine()->pairNextRound($state))
            ->toThrow(NoValidPairingException::class);
    });

    it('respects constraints when pairing', function (): void {
        // Forbid Alice vs Bob entirely
        $constraints = ConstraintSet::create()
            ->custom(
                function (Event $event, SchedulingContext $context): bool {
                    $ids = array_map(fn(Participant $p) => $p->getId(), $event->getParticipants());
                    sort($ids);

                    return $ids !== ['p1', 'p2'];
                },
                'No Alice vs Bob'
            )
            ->build();

        $pairing = new SwissPairingEngine($constraints)->pairNextRound(StageState::start($this->participants));

        expect(swissPairKeys($pairing->getEvents()))->not->toContain('p1-p2');
    });

    it('derives the next round number from the recorded rounds', function (): void {
        $state = StageState::start($this->participants);
        foreach ([1, 2] as $number) {
            $state = $state->withRoundPlayed(new RoundPairing($number, null, [
                new Event([$this->alice, $this->bob], new Round($number)),
                new Event([$this->carol, $this->dave], new Round($number)),
            ]), []);
        }

        $pairing = new SwissPairingEngine(resultsFree: true)->pairNextRound($state);

        expect($pairing->getRoundNumber())->toBe(3);
        foreach ($pairing->getEvents() as $event) {
            expect($event->getRound()?->getNumber())->toBe(3);
        }
    });

    it('rejects fewer than two active participants', function (): void {
        $engine = new SwissPairingEngine();

        expect(fn() => $engine->pairNextRound(StageState::start([$this->alice])))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('shuffles the whole field when no rounds are recorded and a randomizer is given', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $engine = new SwissPairingEngine(randomizer: new Randomizer(new Mt19937(4242)));
        $pairing = $engine->pairNextRound(StageState::start($participants));

        // Deterministic under the seed, and different from the unshuffled
        // adjacent pairing p1-p2, p3-p4, p5-p6, p7-p8
        $deterministicRepeat = new SwissPairingEngine(randomizer: new Randomizer(new Mt19937(4242)))
            ->pairNextRound(StageState::start($participants));

        expect(swissPairKeys($pairing->getEvents()))->toBe(swissPairKeys($deterministicRepeat->getEvents()));
        expect(swissPairKeys($pairing->getEvents()))->not->toBe(['p1-p2', 'p3-p4', 'p5-p6', 'p7-p8']);
    });

    it('shuffles within equal-ranking groups without mixing winners and losers', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        // Round 1: p1-p4 beat p5-p8 respectively
        $results = [];
        for ($i = 0; $i < 4; ++$i) {
            $results[] = new Result(
                new Event([$participants[$i], $participants[$i + 4]], new Round(1)),
                $participants[$i]
            );
        }
        $state = withSwissRound(StageState::start($participants), 1, $results);

        $engine = new SwissPairingEngine(randomizer: new Randomizer(new Mt19937(7)));
        $round2 = $engine->pairNextRound($state);

        // Winners (p1-p4) pair among themselves, losers (p5-p8) among
        // themselves - the shuffle never crosses the ranking boundary
        foreach ($round2->getEvents() as $event) {
            $indexes = array_map(
                fn(Participant $p) => (int) substr($p->getId(), 1),
                $event->getParticipants()
            );
            expect(($indexes[0] <= 4) === ($indexes[1] <= 4))->toBeTrue();
        }
    });

    it('reports completion and the outcome once planned rounds are played', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 2);
        $state = StageState::start($this->participants);

        expect($engine->isComplete($state))->toBeFalse();
        expect($engine->getOutcome($state))->toBeNull();

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = array_map(
                fn(Event $event) => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            );
            $state = $state->withRoundPlayed($pairing, $results);
        }

        $outcome = $engine->getOutcome($state);

        expect($outcome)->not->toBeNull();
        assert($outcome !== null);
        expect($outcome->getResults())->toHaveCount(4);
        expect($outcome->getByes())->toBe([]);
        expect($outcome->getFinalRound()?->getRoundNumber())->toBe(2);
        expect($outcome->getStandings()->getEntries())->toHaveCount(4);
    });

    it('reports completion when too few active participants remain', function (): void {
        $engine = new SwissPairingEngine();
        $state = StageState::start($this->participants)
            ->withoutParticipant($this->bob)
            ->withoutParticipant($this->carol)
            ->withoutParticipant($this->dave);

        expect($engine->isComplete($state))->toBeTrue();
        expect($engine->getOutcome($state))->not->toBeNull();
    });

    it('never reports an open-ended stage complete while it can be paired', function (): void {
        $engine = new SwissPairingEngine();

        expect($engine->isComplete(StageState::start($this->participants)))->toBeFalse();
        expect($engine->getOutcome(StageState::start($this->participants)))->toBeNull();
    });

    it('declares its plan from the state', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 5);

        $plan = $engine->getPlan(StageState::start($this->participants));

        expect($plan->getAlgorithm())->toBe('swiss');
        expect($plan->getTotalRounds())->toBe(5);
        expect($plan->getLegs())->toBeNull();
        expect($plan->getExpectedEventCount())->toBe(10);
    });

    // Regression: a participant referenced only by a bye (bye in one round,
    // withdrawn before ever playing) vanished from the plan and standings
    // even though the outcome's bye counts still listed them
    it('keeps bye-only withdrawn participants in the plan and outcome', function (): void {
        $participants = [...$this->participants, $this->eve];
        $engine = new SwissPairingEngine(plannedRounds: 1);

        // Round 1: Eve has the bye and never plays; she then withdraws
        $state = withSwissRound(StageState::start($participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ], [$this->eve]);
        $state = $state->withoutParticipant($this->eve);

        expect($engine->getPlan($state)->getParticipants())->toHaveCount(5);

        $outcome = $engine->getOutcome($state);
        expect($outcome)->not->toBeNull();
        assert($outcome !== null);
        expect($outcome->getByes())->toBe(['p5' => 1]);
        expect($outcome->getStandings()->getEntryFor($this->eve)?->getPlayed())->toBe(0);
    });

    it('keeps withdrawn participants in the outcome standings', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 1);
        $state = withSwissRound(StageState::start($this->participants), 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->dave),
        ]);
        $state = $state->withoutParticipant($this->dave);

        $outcome = $engine->getOutcome($state);

        expect($outcome)->not->toBeNull();
        assert($outcome !== null);
        // Dave withdrew but his played game stays in the record
        expect($outcome->getStandings()->getEntryFor($this->dave)?->getWins())->toBe(1);
    });

    it('runs a full five-round tournament for eight participants without repeats', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("t{$i}", "Team {$i}");
        }

        $engine = new SwissPairingEngine(plannedRounds: 5);
        $state = StageState::start($participants);
        $seenPairings = [];

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            expect($pairing->getEvents())->toHaveCount(4);

            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $ids = array_map(fn(Participant $p) => $p->getId(), $event->getParticipants());
                sort($ids);
                $key = implode('-', $ids);
                expect($seenPairings)->not->toContain($key);
                $seenPairings[] = $key;

                // Winner is always the lexicographically smaller ID for determinism
                $winner = $event->getParticipants()[0]->getId() < $event->getParticipants()[1]->getId()
                    ? $event->getParticipants()[0]
                    : $event->getParticipants()[1];
                $results[] = new Result($event, $winner);
            }

            $state = $state->withRoundPlayed($pairing, $results);
        }

        expect($seenPairings)->toHaveCount(20);
        expect($engine->getOutcome($state))->not->toBeNull();
    });

    it('rejects a non-positive planned round count', function (): void {
        new SwissPairingEngine(plannedRounds: 0);
    })->throws(InvalidArgumentException::class, 'at least 1');

    it('throws for odd fields whose every pairing has been played', function (): void {
        $trio = [$this->alice, $this->bob, $this->carol];
        $state = StageState::start($trio);
        $state = withSwissRound($state, 1, [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
        ], [$this->carol]);
        $state = withSwissRound($state, 2, [
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
        ], [$this->bob]);
        $state = withSwissRound($state, 3, [
            new Result(new Event([$this->bob, $this->carol], new Round(3)), $this->bob),
        ], [$this->alice]);

        expect(fn() => new SwissPairingEngine()->pairNextRound($state))
            ->toThrow(NoValidPairingException::class);
    });

    // The bracket engines refuse a round after the last one. A Swiss stage
    // with planned rounds ends too, and paired a round beyond them.
    it('refuses to pair a round beyond the planned rounds', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 1, resultsFree: true);
        $state = StageState::start($this->participants);
        $state = $state->withRoundPlayed($engine->pairNextRound($state), []);

        expect($engine->isComplete($state))->toBeTrue();

        try {
            $engine->pairNextRound($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::PlannedRoundsPlayed)
                ->and($exception->getContext())->toBe(['planned_rounds' => 1, 'next_round' => 2]);

            return;
        }

        $this->fail('A second round was paired for a stage of one planned round.');
    });

    it('pairs the last planned round, and any round of an open-ended stage', function (): void {
        $planned = new SwissPairingEngine(plannedRounds: 2, resultsFree: true);
        $state = StageState::start($this->participants);
        $state = $state->withRoundPlayed($planned->pairNextRound($state), []);

        expect($planned->pairNextRound($state)->getRoundNumber())->toBe(2)
            ->and(new SwissPairingEngine(resultsFree: true)->pairNextRound($state)->getRoundNumber())->toBe(2);
    });
});
