<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Scheduling\BacktrackingRoundRobinGenerator;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;

// Every map of pairings in the library is keyed by the two participant ids.
// These tests name one defect each, at the component it was observed in: a
// pair of ids that PHP compares as equal numbers ('01' and '1'), or an id
// that contains the key's separator. The sweeps over whole tournaments are in
// ScheduleCompletenessTest and EliminationInvariantsTest.

/**
 * @param array<string> $ids
 * @return array<Participant>
 */
function keyedParticipants(array $ids): array
{
    $participants = [];
    foreach (array_values($ids) as $index => $id) {
        $participants[] = new Participant($id, 'L' . $id, $index + 1);
    }

    return $participants;
}

describe('numerically equal participant ids', function (): void {
    it('generates the round robin of the issue: ids 01, 1, 2 and 3', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(keyedParticipants(['01', '1', '2', '3']));

        expect($schedule)->toHaveCount(6);

        $counts = AwkwardIds::pairingCounts($schedule);
        ksort($counts, SORT_STRING);
        expect($counts)->toBe([
            '["01","1"]' => 1,
            '["01","2"]' => 1,
            '["01","3"]' => 1,
            '["1","2"]' => 1,
            '["1","3"]' => 1,
            '["2","3"]' => 1,
        ]);

        // An id is a name, not an input to the algorithm: the schedule is
        // the one four participants with any other ids get, seat for seat.
        $seats = fn(Schedule $generated, array $ids): array => array_map(
            fn(Event $event): array => [
                $event->getRound()?->getNumber(),
                ...array_map(
                    fn(Participant $participant) => array_search($participant->getId(), $ids, true),
                    $event->getParticipants()
                ),
            ],
            $generated->getEvents()
        );
        $plain = (new RoundRobinScheduler())->schedule(keyedParticipants(['a', 'b', 'c', 'd']));

        expect($seats($schedule, ['01', '1', '2', '3']))->toBe($seats($plain, ['a', 'b', 'c', 'd']));
    });

    it('generates a round robin for an id written as an exponent', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(keyedParticipants(['1e3', '1000', 'a', 'b']));

        expect($schedule)->toHaveCount(6);
        expect(AwkwardIds::pairingCounts($schedule)['["1000","1e3"]'] ?? 0)->toBe(1);
    });

    it('validates a complete round robin whichever of the two is named first', function (): void {
        [$zeroOne, $one, $two] = $participants = keyedParticipants(['01', '1', '2']);
        $plan = new RoundRobinPlan($participants, 1);

        // The plan builds its expectation from the list order (01, 1); the
        // event names the pair the other way round.
        $schedule = new Schedule([
            new Event([$one, $zeroOne], new Round(1)),
            new Event([$two, $zeroOne], new Round(2)),
            new Event([$two, $one], new Round(3)),
        ]);

        expect($plan->validateIntegrity($schedule))->toBe([]);
        expect($plan->findUnplayedPairings([new Result($schedule->getEvents()[0], $one)]))
            ->toBe(['L01 vs L2', 'L1 vs L2']);
    });

    it('counts a repeat in a Swiss schedule whichever of the two is named first', function (): void {
        [$zeroOne, $one] = $participants = keyedParticipants(['01', '1']);

        $violations = (new SwissPlan($participants, 2))->validateIntegrity(new Schedule([
            new Event([$zeroOne, $one], new Round(1)),
            new Event([$one, $zeroOne], new Round(2)),
        ]));

        expect($violations)->toBe(['Pairing 01 vs 1 appears 2 time(s); Swiss pairings may not repeat.']);
    });

    it('never pairs two Swiss participants twice', function (): void {
        $participants = keyedParticipants(['0e1', '0e2', '01', '1']);
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start($participants);

        $events = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $events[] = $event;
                // Every event is drawn, so the table keeps its order and
                // each round offers the pairings of the round before first:
                // an event names the lower-placed participant first, the
                // pairing search the higher-placed one.
                $results[] = new Result($event);
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }

        expect(AwkwardIds::pairingCounts($events))->toHaveCount(6);
        expect(array_unique(array_values(AwkwardIds::pairingCounts($events))))->toBe([1]);
    });

    it('accepts the result of a pairing recorded with the participants the other way round', function (): void {
        [$zeroOne, $one] = $participants = keyedParticipants(['01', '1']);
        $pairing = new RoundPairing(1, null, [new Event([$zeroOne, $one], new Round(1))]);
        $result = new Result(new Event([$one, $zeroOne], new Round(1)), $one);

        $state = StageState::start($participants)->withRoundPlayed($pairing, [$result]);

        expect($state->getResults())->toBe([$result]);
    });

    it('resolves a two-legged tie, whose second leg reverses the roles', function (): void {
        $participants = keyedParticipants(['01', '1']);
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start($participants);

        $pairing = $engine->pairNextRound($state);
        [$firstLeg, $secondLeg] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [
            new Result($firstLeg, $participants[1]),
            new Result($secondLeg),
        ]);

        expect($engine->isComplete($state))->toBeTrue();

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(MatchOutcomeSelector::winners()->select($outcome))->toBe([$participants[1]])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$participants[0]]);
    });

    it('measures the spacing of a pairing that meets twice in opposite roles', function (): void {
        [$zeroOne, $one, $two, $three] = keyedParticipants(['01', '1', '2', '3']);

        // 01 and 1 meet in rounds 1 and 2 of six: as close as two meetings
        // can be, where the ideal gap is three rounds.
        $schedule = new Schedule([
            new Event([$zeroOne, $one], new Round(1)),
            new Event([$one, $zeroOne], new Round(2)),
            new Event([$two, $three], new Round(1)),
            new Event([$two, $three], new Round(4)),
            new Event([$zeroOne, $two], new Round(6)),
        ]);

        $sameScheduleWithPlainIds = function () {
            [$a, $b, $c, $d] = keyedParticipants(['a', 'b', 'c', 'd']);

            return new Schedule([
                new Event([$a, $b], new Round(1)),
                new Event([$b, $a], new Round(2)),
                new Event([$c, $d], new Round(1)),
                new Event([$c, $d], new Round(4)),
                new Event([$a, $c], new Round(6)),
            ]);
        };

        $metric = new PairingSpacingMetric();

        expect($metric->measure($schedule))->toBeGreaterThan(0.0)
            ->and($metric->measure($schedule))->toBe($metric->measure($sameScheduleWithPlainIds()));
    });

    it('reports no missing pairing for a complete schedule with reversed roles', function (): void {
        [$zeroOne, $one, $two] = $participants = keyedParticipants(['01', '1', '2']);

        $report = (new SchedulingDiagnostics())->analyzeSchedulingFailure(
            $participants,
            ConstraintSet::create()->build(),
            [
                new Event([$one, $zeroOne], new Round(1)),
                new Event([$two, $zeroOne], new Round(2)),
                new Event([$two, $one], new Round(3)),
            ],
            new RoundRobinPlan($participants, 1)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('attributes only the pairing that is missing to the constraint that rejects it', function (): void {
        [$zeroOne, $one, $two] = $participants = keyedParticipants(['01', '1', '2']);
        $constraints = ConstraintSet::create()->custom(
            static fn(Event $event): bool => $event->getRound()?->getNumber() !== 3,
            'Nothing in round 3'
        )->build();

        // 01 has met 1 and 2; only "1 v 2" is missing.
        $report = (new SchedulingDiagnostics())->analyzeSchedulingFailure(
            $participants,
            $constraints,
            [
                new Event([$one, $zeroOne], new Round(1)),
                new Event([$two, $zeroOne], new Round(2)),
            ],
            new RoundRobinPlan($participants, 1)
        );

        expect($report->getConstraintViolations())
            ->toBe(['Nothing in round 3 rejects L1 vs L2 in 1 of 3 rounds']);
    });
});

describe('participant ids that contain the key separator', function (): void {
    it('generates a round robin', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(keyedParticipants(['a', 'b|c', 'a|b', 'c']));

        expect($schedule)->toHaveCount(6);
        expect(AwkwardIds::pairingCounts($schedule))->toHaveCount(6);
    });

    it('names both participants of a pairing a round robin is missing', function (): void {
        [$a, $bc, $ab, $c] = $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c']);

        $violations = (new RoundRobinPlan($participants, 1))->validateIntegrity(new Schedule([
            new Event([$a, $bc], new Round(1)),
            new Event([$a, $ab], new Round(2)),
            new Event([$a, $c], new Round(3)),
            new Event([$bc, $ab], new Round(3)),
            new Event([$bc, $c], new Round(2)),
        ]));

        expect($violations)->toBe(['Pairing La|b vs Lc appears 0 time(s), expected 1.']);
    });

    it('names both participants of a repeated Swiss pairing', function (): void {
        [$a, $bc] = $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c']);

        $violations = (new SwissPlan($participants, 2))->validateIntegrity(new Schedule([
            new Event([$a, $bc], new Round(1)),
            new Event([$participants[2], $participants[3]], new Round(1)),
            new Event([$bc, $a], new Round(2)),
            new Event([$participants[3], $participants[2]], new Round(2)),
        ]));

        expect($violations)->toBe([
            'Pairing a vs b|c appears 2 time(s); Swiss pairings may not repeat.',
            'Pairing a|b vs c appears 2 time(s); Swiss pairings may not repeat.',
        ]);
    });

    it('searches a backtracked leg in which two pairings join to the same text', function (): void {
        $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c']);

        $events = (new BacktrackingRoundRobinGenerator())
            ->generateFirstLeg($participants, new RoundRobinPlan($participants, 1));

        expect($events)->not->toBeNull();
        assert($events !== null);
        expect(AwkwardIds::pairingCounts($events))->toHaveCount(6);
    });

    it('searches a backtracked leg for a field in which one id is the old bye marker', function (): void {
        // The search used to mark the bye seat with the id "\0bye", so the
        // bye of a participant and their meeting with a participant of
        // that id were one pairing.
        $participants = keyedParticipants(["\0bye", 'a', 'b']);
        $generator = new BacktrackingRoundRobinGenerator();

        $events = $generator->generateFirstLeg($participants, new RoundRobinPlan($participants, 1));

        expect($events)->not->toBeNull();
        assert($events !== null);
        expect(AwkwardIds::pairingCounts($events))->toHaveCount(3);

        $byes = $generator->getRoundByes();
        sort($byes, SORT_STRING);
        expect($byes)->toBe(["\0bye", 'a', 'b']);
    });

    it('pairs the Swiss leaders who have not met', function (): void {
        // Round 1 was "a|b v c", "a v d" and "b|c v e". Its winners a and
        // b|c lead the table and have not met; the ids of "a|b v c" join to
        // the same text as theirs.
        [$a, $bc, $ab, $c, $d, $e] = $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c', 'd', 'e']);
        $firstRound = new RoundPairing(1, null, [
            $first = new Event([$ab, $c], new Round(1)),
            $second = new Event([$a, $d], new Round(1)),
            $third = new Event([$bc, $e], new Round(1)),
        ]);
        $state = StageState::start($participants)->withRoundPlayed($firstRound, [
            new Result($first, $c),
            new Result($second, $a),
            new Result($third, $bc),
        ]);

        $pairing = (new SwissPairingEngine())->pairNextRound($state);

        // Table order: a, b|c, c, then the losers a|b, d, e. The leaders
        // pair; c has met a|b, so c takes d and a|b takes e.
        expect(AwkwardIds::pairingCounts($pairing->getEvents()))->toBe([
            '["a","b|c"]' => 1,
            '["c","d"]' => 1,
            '["a|b","e"]' => 1,
        ]);
    });

    it('rejects a result for an event that is not in the pairing', function (): void {
        [$a, $bc, $ab, $c] = $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c']);
        $pairing = new RoundPairing(1, null, [new Event([$a, $bc], new Round(1))]);
        $foreign = new Result(new Event([$ab, $c], new Round(1)), $c);

        StageState::start($participants)->withRoundPlayed($pairing, [$foreign]);
    })->throws(
        InvalidConfigurationException::class,
        'Result references an event that is not part of the pairing being recorded'
    );

    it('keeps the results of two different ties of an elimination round apart', function (): void {
        // Four entrants fold to "a v c" and "b|c v a|b"; reorder them so the
        // round holds the two ties whose ids join to the same text.
        [$a, $bc, $ab, $c] = keyedParticipants(['a', 'b|c', 'a|b', 'c']);
        $engine = new SingleEliminationEngine();
        $state = StageState::start([$a, $ab, $c, $bc]);

        $pairing = $engine->pairNextRound($state);
        expect(AwkwardIds::pairingCounts($pairing->getEvents()))->toBe([
            '["a","b|c"]' => 1,
            '["a|b","c"]' => 1,
        ]);

        [$first, $second] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [new Result($first, $a), new Result($second, $c)]);

        $final = $engine->pairNextRound($state);
        expect(AwkwardIds::pairingCounts($final->getEvents()))->toBe(['["a","c"]' => 1]);
    });

    it('selects the winner of each of two ties whose ids join to the same text', function (): void {
        [$a, $bc, $ab, $c] = $participants = keyedParticipants(['a', 'b|c', 'a|b', 'c']);
        $first = new Event([$a, $bc], new Round(1));
        $second = new Event([$ab, $c], new Round(1));
        $results = [new Result($first, $bc), new Result($second, $ab)];

        $outcome = new StageOutcome(
            (new StandingsCalculator())->calculate($participants, $results),
            $results,
            [],
            new RoundPairing(1, null, [$first, $second])
        );

        expect(MatchOutcomeSelector::winners()->select($outcome))->toBe([$bc, $ab])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$a, $c]);
    });
});

// What a caller can read of a key or of its order: the messages and the
// context of four failures. Written out for ids the library has always
// handled, so the shared helper cannot change them.
describe('pair keys a caller can read', function (): void {
    beforeEach(function (): void {
        $this->participants = keyedParticipants(['10', '9', 'p10', 'p9']);
        [$this->ten, $this->nine, $this->pTen, $this->pNine] = $this->participants;
    });

    it('names a foreign result by round, ids in key order, and leg', function (): void {
        $pairing = new RoundPairing(1, null, [new Event([$this->pTen, $this->pNine], new Round(1))]);
        $foreign = new Result(new Event([$this->ten, $this->nine], new Round(1)), $this->ten);

        try {
            StageState::start($this->participants)->withRoundPlayed($pairing, [$foreign]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext())->toBe(['pairing_round' => 1, 'event' => '1:9|10:1']);

            return;
        }

        throw new LogicException('The foreign result was accepted.');
    });

    it('names a twice-recorded elimination match with its ids in key order', function (): void {
        $event = new Event([$this->ten, $this->nine], new Round(1));
        $pairing = new RoundPairing(1, null, [$event]);
        $state = StageState::start([$this->ten, $this->nine])
            ->withRoundPlayed($pairing, [new Result($event, $this->ten)])
            ->withAdditionalResults([new Result(new Event([$this->ten, $this->nine], new Round(1)), $this->nine)]);

        try {
            (new SingleEliminationEngine())->isComplete($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain('Two results reference the same elimination match (9 vs 10, round 1)')
                ->and($exception->getContext())->toBe(['round' => 1, 'participants' => ['9', '10']]);

            return;
        }

        throw new LogicException('The duplicate result was accepted.');
    });

    it('names a missing round-robin pairing in list order', function (): void {
        $violations = (new RoundRobinPlan([$this->ten, $this->nine, $this->pTen], 1))->validateIntegrity(new Schedule([
            new Event([$this->ten, $this->pTen], new Round(1)),
        ]));

        expect($violations)->toBe([
            'Pairing L9 vs L10 appears 0 time(s), expected 1.',
            'Pairing L9 vs Lp10 appears 0 time(s), expected 1.',
        ]);
    });

    it('names a repeated Swiss pairing with its ids in key order', function (): void {
        $violations = (new SwissPlan($this->participants, 2))->validateIntegrity(new Schedule([
            new Event([$this->ten, $this->nine], new Round(1)),
            new Event([$this->pNine, $this->pTen], new Round(1)),
            new Event([$this->nine, $this->ten], new Round(2)),
            new Event([$this->pTen, $this->pNine], new Round(2)),
        ]));

        expect($violations)->toBe([
            'Pairing 9 vs 10 appears 2 time(s); Swiss pairings may not repeat.',
            'Pairing p10 vs p9 appears 2 time(s); Swiss pairings may not repeat.',
        ]);
    });
});
