<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Standings\StandingEntry;

// The third-place tie of a single elimination bracket, case by case. The
// sweeps over field sizes, legs and re-seeding are in
// EliminationInvariantsTest and EliminationPlacementTest; the cases here are
// the ones the option was specified by, with every participant named.

/**
 * Entrants p1, p2, ... in list order.
 *
 * @return list<Participant>
 */
function thirdPlaceField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "Entrant {$i}");
    }

    return $field;
}

/**
 * The events of a pairing as `first v second`, in the pairing's order.
 *
 * @return list<string>
 */
function thirdPlaceEvents(RoundPairing $pairing): array
{
    return array_values(array_map(
        fn(Event $event): string => implode(' v ', array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants())),
        $pairing->getEvents()
    ));
}

/**
 * Record a round in which the named entrants win every event they are in.
 * An event that holds none of them, or two, is a mistake of the test.
 *
 * @param array<string> $winnerIds
 * @throws InvalidConfigurationException When the state refuses the round
 */
function thirdPlaceRound(StageState $state, RoundPairing $pairing, array $winnerIds): StageState
{
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $winners = array_values(array_filter(
            $event->getParticipants(),
            fn(Participant $participant): bool => in_array($participant->getId(), $winnerIds, true)
        ));
        if (count($winners) !== 1) {
            throw new LogicException('Name exactly one entrant of every event as its winner.');
        }
        $results[] = new Result($event, $winners[0]);
    }

    return $state->withRoundPlayed($pairing, $results);
}

/**
 * Play rounds, each won by the entrants named for it, and return the state
 * after the last of them.
 *
 * @param list<Participant> $field
 * @param array<array<string>> $winnersByRound The ids of the entrants who win, round by round
 * @throws InvalidConfigurationException When the engine or the state refuses a round
 * @throws NoValidPairingException Never: a bracket engine does not throw it
 */
function thirdPlaceState(StageEngineInterface $engine, array $field, array $winnersByRound): StageState
{
    $state = StageState::start($field);
    foreach ($winnersByRound as $winnerIds) {
        $state = thirdPlaceRound($state, $engine->pairNextRound($state), $winnerIds);
    }

    return $state;
}

/**
 * The table as participant ids, rank 1 first.
 *
 * @return list<string>
 */
function thirdPlaceTable(StageOutcome $outcome): array
{
    return array_values(array_map(
        fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
        $outcome->getStandings()->getEntries()
    ));
}

/**
 * @param array<Participant> $participants
 * @return list<string>
 */
function thirdPlaceIds(array $participants): array
{
    return array_values(array_map(fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * Every call an engine answers from a state, by name.
 *
 * @return array<string, Closure(StageState): mixed>
 */
function thirdPlaceCalls(SingleEliminationEngine $engine): array
{
    return [
        'isComplete' => $engine->isComplete(...),
        'pairNextRound' => $engine->pairNextRound(...),
        'getOutcome' => $engine->getOutcome(...),
        'getPlan' => $engine->getPlan(...),
    ];
}

describe('the third-place tie of a single elimination bracket', function (): void {
    beforeEach(function (): void {
        $this->engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
    });

    it('pairs the final and a tie between the two semifinal losers in the last round of four entrants', function (): void {
        $state = StageState::start(thirdPlaceField(4));

        $semifinals = $this->engine->pairNextRound($state);
        expect(thirdPlaceEvents($semifinals))->toBe(['p1 v p4', 'p2 v p3'])
            ->and($semifinals->getLabel())->toBe('semifinal')
            ->and(array_map(BracketTie::of(...), $semifinals->getEvents()))->toBe([null, null]);

        $state = thirdPlaceRound($state, $semifinals, ['p1', 'p2']);
        $last = $this->engine->pairNextRound($state);

        // One round, labelled as the last round of every bracket is; the
        // final first, and each event says which tie it is
        expect($last->getRoundNumber())->toBe(2)
            ->and($last->getLabel())->toBe('final')
            ->and($last->getByes())->toBe([])
            ->and(thirdPlaceEvents($last))->toBe(['p1 v p2', 'p4 v p3'])
            ->and(array_map(BracketTie::of(...), $last->getEvents()))->toBe([BracketTie::Final, BracketTie::ThirdPlace])
            ->and(array_map(fn(Event $event): array => $event->getMetadata(), $last->getEvents()))->toBe([
                ['bracket_tie' => 'final'],
                ['bracket_tie' => 'third-place'],
            ]);

        // Both events are in the round itself
        foreach ($last->getEvents() as $event) {
            expect($event->getRound()?->getNumber())->toBe(2)
                ->and($event->getRound()?->getMetadataValue('label'))->toBe('final');
        }
    });

    it('is complete only when both ties of the last round are decided', function (): void {
        $state = thirdPlaceState($this->engine, thirdPlaceField(4), [['p1', 'p2']]);
        $last = $this->engine->pairNextRound($state);
        [$final, $thirdPlace] = $last->getEvents();

        $waitingFor = fn(StageState $state): array => array_map(
            static fn(Event $event): string => $event->getParticipants()[0]->getId() . ' v ' . $event->getParticipants()[1]->getId(),
            $this->engine->getReadiness($state)->getUnresolvedEvents()
        );

        // Paired and not played: the stage is not complete, nothing is
        // thrown for it, and the engine waits for both ties
        $paired = $state->withRoundPlayed($last, []);
        expect($this->engine->isComplete($paired))->toBeFalse()
            ->and($this->engine->getOutcome($paired))->toBeNull()
            ->and($waitingFor($paired))->toBe(['p1 v p2', 'p4 v p3']);

        // One of the two decided, either one: the round is not finished, as
        // a round with one of its ties undecided never is. Reading it throws
        // nothing; asking for a round after it is refused with the event the
        // bracket waits for.
        foreach ([[$final, 'the final alone'], [$thirdPlace, 'the third-place tie alone']] as [$event, $case]) {
            $partial = $state->withRoundPlayed($last, [new Result($event, $event->getParticipants()[0])]);
            $other = $event === $final ? $thirdPlace : $final;

            expect($this->engine->isComplete($partial))->toBeFalse("isComplete() with {$case} decided")
                ->and($this->engine->getOutcome($partial))->toBeNull()
                ->and($this->engine->getReadiness($partial)->getUnresolvedEvents())->toBe([$other]);

            try {
                $this->engine->pairNextRound($partial);
                $this->fail("pairNextRound() accepted a last round with {$case} decided.");
            } catch (UnresolvedRoundException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::RoundPartiallyResolved)
                    ->and($exception->getRoundNumber())->toBe(2)
                    ->and($exception->getUnresolvedEvents())->toBe([$other]);
            }

            // The missing result completes it
            $completed = $partial->withAdditionalResults([new Result($other, $other->getParticipants()[0])]);
            expect($this->engine->isComplete($completed))->toBeTrue();
        }
    });

    it('gives places 1 to 4 to the winner and the loser of the final, then of the third-place tie', function (
        array $lastRoundWinners,
        array $table
    ): void {
        $state = thirdPlaceState($this->engine, thirdPlaceField(4), [['p1', 'p2'], $lastRoundWinners]);
        $outcome = $this->engine->getOutcome($state);
        assert($outcome !== null);

        expect(thirdPlaceTable($outcome))->toBe($table)
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 3])
            ->and($outcome->getStandings()->getTiedSets())->toBe([])
            // The match-outcome selector reads the final and not the
            // third-place tie, which sends nobody on: one participant,
            // as after every bracket
            ->and(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe([$table[0]])
            ->and(thirdPlaceIds(MatchOutcomeSelector::losers()->select($outcome)))->toBe([$table[1]])
            ->and(thirdPlaceIds(RankRangeSelector::overall(3, 3)->select($outcome)))->toBe([$table[2]])
            ->and(thirdPlaceIds(RankRangeSelector::overall(1, 4)->select($outcome)))->toBe($table);
    })->with([
        'the first-named win both' => [['p1', 'p4'], ['p1', 'p2', 'p4', 'p3']],
        'the second-named win both' => [['p2', 'p3'], ['p2', 'p1', 'p3', 'p4']],
        'one of each' => [['p1', 'p3'], ['p1', 'p2', 'p3', 'p4']],
        'the other of each' => [['p2', 'p4'], ['p2', 'p1', 'p4', 'p3']],
    ]);

    // A state built from an application's own storage lists the events of
    // a round in the order the storage gives them. The selection is the
    // final's, whichever tie the recorded round lists first.
    it('selects the winner and the loser of the final whatever order the last round was recorded in', function (
        int $legsPerTie,
        int $size
    ): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legsPerTie, thirdPlace: true));
        $state = StageState::start(thirdPlaceField($size));

        // The second-named entrant of every tie wins each leg
        $secondNamedWins = fn(RoundPairing $pairing): array => array_values(array_map(
            fn(Event $event): Result => new Result($event, $event->getParticipants()[$event->getMetadataValue('tie_leg') === 2 ? 0 : 1]),
            $pairing->getEvents()
        ));

        for ($round = 1; $round < (int) log($size, 2); ++$round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, $secondNamedWins($pairing));
        }
        $last = $engine->pairNextRound($state);
        [$first, $second] = array_values($last->getEvents()[0]->getParticipants());

        foreach ([$last->getEvents(), array_reverse($last->getEvents())] as $recordedOrder) {
            $recorded = new RoundPairing($last->getRoundNumber(), $last->getLabel(), $recordedOrder, $last->getByes());
            $outcome = $engine->getOutcome($state->withRoundPlayed($recorded, array_reverse($secondNamedWins($last))));
            assert($outcome !== null);

            expect(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe([$second->getId()])
                ->and(thirdPlaceIds(MatchOutcomeSelector::losers()->select($outcome)))->toBe([$first->getId()])
                ->and(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))
                ->toBe(thirdPlaceIds(RankRangeSelector::overall(1, 1)->select($outcome)));
        }
    })->with([[1], [2]])->with([[4], [8], [16]]);

    // The composition validator runs nothing and counts one participant
    // from a match-outcome selection after a whole bracket. That count is
    // right for a bracket with a third-place tie as for one without.
    it('yields from a whole bracket the one participant the composition validator counts', function (): void {
        $state = thirdPlaceState($this->engine, thirdPlaceField(8), [['p1', 'p2', 'p3', 'p4'], ['p1', 'p2'], ['p1', 'p3']]);
        $outcome = $this->engine->getOutcome($state);
        assert($outcome !== null);

        $violations = (new CompositionValidator())->validateChain(8, [
            new StageTransition('next', 2, MatchOutcomeSelector::winners(), afterWholeBracket: true),
        ]);

        expect($violations)->toBe(["Stage 'next' expects 2 entrants but its selection yields 1 from the previous stage's 8."])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toHaveCount(1)
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toHaveCount(1);
    });

    // The selector knows the third-place tie by its mark and by nothing
    // else. A round recorded with events that do not carry it is read as
    // any round is: one participant for each tie, in the recorded order.
    it('selects from both ties of a last round whose events were recorded without their marks', function (): void {
        $state = thirdPlaceState($this->engine, thirdPlaceField(4), [['p1', 'p2']]);
        $last = $this->engine->pairNextRound($state);

        $unmarked = array_values(array_map(
            fn(Event $event): Event => new Event($event->getParticipants(), $event->getRound()),
            $last->getEvents()
        ));
        $state = $state->withRoundPlayed(
            new RoundPairing($last->getRoundNumber(), $last->getLabel(), $unmarked, []),
            [new Result($unmarked[0], $unmarked[0]->getParticipants()[0]), new Result($unmarked[1], $unmarked[1]->getParticipants()[0])]
        );
        $outcome = $this->engine->getOutcome($state);
        assert($outcome !== null);

        // The engine knows the ties by their participants, so the table is the same
        expect(thirdPlaceTable($outcome))->toBe(['p1', 'p2', 'p4', 'p3'])
            ->and(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe(['p1', 'p4'])
            ->and(thirdPlaceIds(MatchOutcomeSelector::losers()->select($outcome)))->toBe(['p2', 'p3']);
    });

    it('counts the result of the third-place tie in the records of its two entrants', function (): void {
        $field = thirdPlaceField(4);
        [, , $p3, $p4] = $field;
        $state = thirdPlaceState($this->engine, $field, [['p1', 'p2']]);
        $last = $this->engine->pairNextRound($state);
        [$final, $thirdPlace] = $last->getEvents();

        $state = $state->withRoundPlayed($last, [
            new Result($final, $final->getParticipants()[0]),
            new Result($thirdPlace, $p4, ['p4' => 1, 'p3' => 0]),
        ]);
        $outcome = $this->engine->getOutcome($state);
        assert($outcome !== null);

        expect(thirdPlaceTable($outcome))->toBe(['p1', 'p2', 'p4', 'p3'])
            ->and($outcome->getStandings()->getEntryFor($p4)?->getLosses())->toBe(1)
            ->and($outcome->getStandings()->getEntryFor($p4)?->getWins())->toBe(1)
            ->and($outcome->getStandings()->getEntryFor($p3)?->getLosses())->toBe(2)
            ->and($outcome->getStandings()->getEntryFor($p3)?->getWins())->toBe(0);
    });

    it('plays the third-place tie between the two semifinal losers of a bracket with byes', function (
        int $size,
        array $winnersByRound,
        array $lastRound,
        array $table
    ): void {
        $state = thirdPlaceState($this->engine, thirdPlaceField($size), $winnersByRound);
        $last = $this->engine->pairNextRound($state);

        expect(thirdPlaceEvents($last))->toBe($lastRound)
            ->and($last->getLabel())->toBe('final')
            ->and($last->getRoundNumber())->toBe(3);

        $state = $state->withRoundPlayed($last, array_map(
            fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
            $last->getEvents()
        ));
        $outcome = $this->engine->getOutcome($state);
        assert($outcome !== null);

        expect(array_slice(thirdPlaceTable($outcome), 0, 4))->toBe($table)
            ->and(array_slice($outcome->getStandings()->getTiers(), 0, 5))->toBe([0, 1, 2, 3, 4])
            ->and($outcome->getResults())->toHaveCount($size)
            ->and($this->engine->getPlan($state)->getExpectedEventCount())->toBe($size);
    })->with([
        // Round 1 of five: p4 v p5, byes for p1, p2, p3. Semifinals p1 v p4, p2 v p3
        'five entrants' => [5, [['p4'], ['p1', 'p2']], ['p1 v p2', 'p4 v p3'], ['p1', 'p2', 'p4', 'p3']],
        'five entrants, the upsets' => [5, [['p5'], ['p5', 'p3']], ['p5 v p3', 'p1 v p2'], ['p5', 'p3', 'p1', 'p2']],
        // Round 1 of six: p4 v p5, p3 v p6, byes for p1, p2
        'six entrants' => [6, [['p4', 'p3'], ['p4', 'p2']], ['p4 v p2', 'p1 v p3'], ['p4', 'p2', 'p1', 'p3']],
        // Round 1 of seven: p4 v p5, p2 v p7, p3 v p6, a bye for p1
        'seven entrants' => [7, [['p5', 'p7', 'p6'], ['p1', 'p6']], ['p1 v p6', 'p5 v p7'], ['p1', 'p6', 'p5', 'p7']],
        'eight entrants' => [8, [['p1', 'p4', 'p2', 'p3'], ['p4', 'p3']], ['p4 v p3', 'p1 v p2'], ['p4', 'p3', 'p1', 'p2']],
    ]);

    it('plays no third-place tie in a field of three, and places the one semifinal loser third', function (): void {
        $field = thirdPlaceField(3);
        $without = new SingleEliminationEngine();

        $state = StageState::start($field);
        $stateWithout = StageState::start($field);

        // Round 1: p1 has the bye, p2 v p3
        $semifinal = $this->engine->pairNextRound($state);
        expect(thirdPlaceEvents($semifinal))->toBe(['p2 v p3'])
            ->and(thirdPlaceIds($semifinal->getByes()))->toBe(['p1'])
            ->and($semifinal->toArray())->toBe($without->pairNextRound($stateWithout)->toArray());

        $state = thirdPlaceRound($state, $semifinal, ['p3']);
        $stateWithout = thirdPlaceRound($stateWithout, $without->pairNextRound($stateWithout), ['p3']);

        // The last round holds the final alone, and it says so
        $final = $this->engine->pairNextRound($state);
        expect(thirdPlaceEvents($final))->toBe(['p1 v p3'])
            ->and(thirdPlaceEvents($final))->toBe(thirdPlaceEvents($without->pairNextRound($stateWithout)))
            ->and(array_map(BracketTie::of(...), $final->getEvents()))->toBe([BracketTie::Final]);

        $state = thirdPlaceRound($state, $final, ['p3']);
        $stateWithout = thirdPlaceRound($stateWithout, $without->pairNextRound($stateWithout), ['p3']);

        $outcome = $this->engine->getOutcome($state);
        $outcomeWithout = $without->getOutcome($stateWithout);
        assert($outcome !== null && $outcomeWithout !== null);

        // p2, who went out in the semifinal, is third: the table of the
        // bracket without the option
        expect(thirdPlaceTable($outcome))->toBe(['p3', 'p1', 'p2'])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2])
            ->and(thirdPlaceTable($outcome))->toBe(thirdPlaceTable($outcomeWithout))
            ->and($outcome->getStandings()->getTiers())->toBe($outcomeWithout->getStandings()->getTiers())
            ->and(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe(['p3'])
            ->and($this->engine->getPlan($state)->getExpectedEventCount())->toBe(2)
            ->and($this->engine->getPlan($state)->hasThirdPlaceTie())->toBeFalse();
    });

    it('refuses a field of two on every call, with a reason', function (string $call): void {
        $state = StageState::start(thirdPlaceField(2));

        try {
            thirdPlaceCalls($this->engine)[$call]($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::TooFewParticipants)
                ->and($exception->getContext())->toBe(['participant_count' => 2, 'minimum_required' => 3])
                ->and($exception->getMessage())->toContain('no third place to decide');

            return;
        }

        $this->fail("{$call}() accepted a bracket of two with the third-place option.");
    })->with([['isComplete'], ['pairNextRound'], ['getOutcome'], ['getPlan']]);

    // The same two entrants without the option are a bracket: a final
    it('still plays a bracket of two without the option', function (): void {
        $engine = new SingleEliminationEngine();
        $state = thirdPlaceState($engine, thirdPlaceField(2), [['p2']]);

        expect($engine->isComplete($state))->toBeTrue();
    });

    it('plays the third-place tie over two legs when ties have two, and decides it like any other tie', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true));
        $field = thirdPlaceField(4);
        [, , $p3, $p4] = $field;

        $state = StageState::start($field);
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, array_map(
            // p1 and p2 win both legs of their ties
            fn(Event $event): Result => new Result($event, $event->hasParticipant($field[0]) ? $field[0] : $field[1]),
            $semifinals->getEvents()
        ));

        $last = $engine->pairNextRound($state);
        expect(thirdPlaceEvents($last))->toBe(['p1 v p2', 'p2 v p1', 'p4 v p3', 'p3 v p4'])
            ->and(array_map(fn(Event $event): array => $event->getMetadata(), $last->getEvents()))->toBe([
                ['tie_leg' => 1, 'bracket_tie' => 'final'],
                ['tie_leg' => 2, 'bracket_tie' => 'final'],
                ['tie_leg' => 1, 'bracket_tie' => 'third-place'],
                ['tie_leg' => 2, 'bracket_tie' => 'third-place'],
            ])
            ->and($engine->getPlan($state)->getExpectedEventCount())->toBe(8);

        [$finalFirstLeg, $finalSecondLeg, $thirdFirstLeg, $thirdSecondLeg] = $last->getEvents();
        $finalResults = [new Result($finalFirstLeg, $field[0]), new Result($finalSecondLeg, $field[0])];

        // One leg of the third-place tie: the tie is not decided yet, and
        // the round waits for the other leg
        $oneLeg = $state->withRoundPlayed($last, [...$finalResults, new Result($thirdFirstLeg, $p4)]);
        expect($engine->isComplete($oneLeg))->toBeFalse()
            ->and($engine->getReadiness($oneLeg)->getUnresolvedEvents())->toBe([$thirdSecondLeg]);

        // A leg each, and no decision: refused, as a level tie is
        $level = $oneLeg->withAdditionalResults([new Result($thirdSecondLeg, $p3)]);
        try {
            $engine->isComplete($level);
            $this->fail('A level third-place tie without a decision was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        }

        // The decision sends p3 on: third, above p4, whose record is the same
        $decided = $oneLeg->withAdditionalResults([
            new Result($thirdSecondLeg, $p3, [], [TieDecision::TIE_WINNER_KEY => 'p3']),
        ]);
        $outcome = $engine->getOutcome($decided);
        assert($outcome !== null);

        expect(thirdPlaceTable($outcome))->toBe(['p1', 'p2', 'p3', 'p4'])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 3])
            ->and($outcome->getStandings()->getTiedSets())->toBe([])
            ->and($outcome->getResults())->toHaveCount(8)
            ->and(thirdPlaceIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe(['p1'])
            ->and(thirdPlaceIds(MatchOutcomeSelector::losers()->select($outcome)))->toBe(['p2']);

        // The leg wins decide where they can: p4 wins one leg and draws the other
        $won = $oneLeg->withAdditionalResults([new Result($thirdSecondLeg)]);
        $wonOutcome = $engine->getOutcome($won);
        assert($wonOutcome !== null);
        expect(array_slice(thirdPlaceTable($wonOutcome), 2))->toBe(['p4', 'p3']);
    });

    it('reads a level single-leg third-place tie through its recorded decision', function (): void {
        $field = thirdPlaceField(4);
        [, , $p3, $p4] = $field;
        $state = thirdPlaceState($this->engine, $field, [['p1', 'p2']]);
        $last = $this->engine->pairNextRound($state);
        [$final, $thirdPlace] = $last->getEvents();
        $finalResult = new Result($final, $final->getParticipants()[0]);

        $undecided = $state->withRoundPlayed($last, [$finalResult, new Result($thirdPlace, null, ['p4' => 1, 'p3' => 1])]);
        try {
            $this->engine->getOutcome($undecided);
            $this->fail('A drawn third-place tie without a decision was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        }

        $decided = $state->withRoundPlayed($last, [
            $finalResult,
            new Result($thirdPlace, null, ['p4' => 1, 'p3' => 1], [TieDecision::TIE_WINNER_KEY => 'p3']),
        ]);
        $outcome = $this->engine->getOutcome($decided);
        assert($outcome !== null);

        // The table counts the decided draw as p3's win; the result is
        // the draw it was
        expect(thirdPlaceTable($outcome))->toBe(['p1', 'p2', 'p3', 'p4'])
            ->and($outcome->getStandings()->getEntryFor($p3)?->getWins())->toBe(1)
            ->and($outcome->getStandings()->getEntryFor($p3)?->getDraws())->toBe(0)
            ->and($outcome->getStandings()->getEntryFor($p4)?->getLosses())->toBe(2)
            ->and($outcome->getResults()[3]->isDraw())->toBeTrue();
    });

    // A fixed path keeps the order of the semifinals; a re-seeded bracket
    // orders the two as it orders the finalists, by the table of the
    // earlier rounds and list position where that is level
    it('names the two entrants of the third-place tie in bracket order on a fixed path, and in table order when re-seeded', function (): void {
        // Eight entrants, the favourites win round 1. Semifinals p1 v p4
        // and p2 v p3; p1 and p3 win. p4 went out of the first semifinal
        // and p2 of the second; both have won one event and lost one
        $rounds = [['p1', 'p4', 'p2', 'p3'], ['p1', 'p3']];

        $fixed = $this->engine;
        $reseeded = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true, thirdPlace: true));

        expect(thirdPlaceEvents($fixed->pairNextRound(thirdPlaceState($fixed, thirdPlaceField(8), $rounds))))
            ->toBe(['p1 v p3', 'p4 v p2'])
            ->and(thirdPlaceEvents($reseeded->pairNextRound(thirdPlaceState($reseeded, thirdPlaceField(8), $rounds))))
            ->toBe(['p1 v p3', 'p2 v p4']);
    });

    it('orders the two entrants of a re-seeded third-place tie by their records before list position', function (): void {
        // As above, but p4 lost its semifinal 2-3 and p2 lost 0-5: p4 has
        // the better record before the last round, and is first-named
        $reseeded = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true, thirdPlace: true));
        $state = thirdPlaceState($reseeded, thirdPlaceField(8), [['p1', 'p4', 'p2', 'p3']]);
        $semifinals = $reseeded->pairNextRound($state);

        $results = [];
        foreach ($semifinals->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            $results[] = $second->getId() === 'p4'
                ? new Result($event, $first, [$first->getId() => 3, 'p4' => 2])
                : new Result($event, $second, [$second->getId() => 5, $first->getId() => 0]);
        }
        expect(thirdPlaceEvents($semifinals))->toBe(['p1 v p4', 'p2 v p3']);

        $last = $reseeded->pairNextRound($state->withRoundPlayed($semifinals, $results));
        expect(array_slice(thirdPlaceEvents($last), 1))->toBe(['p4 v p2']);
    });

    it('gives the same last round again when it is asked for twice, and reads it as its own once its pairing is recorded', function (bool $reseed): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: $reseed, thirdPlace: true));
        $state = thirdPlaceState($engine, thirdPlaceField(8), [['p8', 'p5', 'p7', 'p6'], ['p8', 'p6']]);

        $last = $engine->pairNextRound($state);
        $recorded = $engine->getRecordedRounds(StageState::fromJson($state->withRoundPlayed($last, [])->toJson()));

        // A recorded round is not paired again; what the engine would pair
        // in its place is its derivation, and it is the round it paired
        expect($engine->pairNextRound($state)->toArray())->toBe($last->toArray())
            ->and($recorded[2]->getDerivation()?->toArray())->toBe($last->toArray())
            ->and($recorded[2]->isManual())->toBeFalse()
            ->and($recorded[2]->getUnresolvedEvents())->toHaveCount(2);
    })->with([[false], [true]]);

    // A last round recorded without the tie is the round that was played:
    // the application says no third-place tie was. It is read as recorded,
    // it is reported as manual, and it decides places 1 and 2 only.
    it('reads a last round recorded without the third-place tie as paired by hand, with places 3 and 4 undecided', function (): void {
        // Paired by an engine without the option, and not stamped
        $without = new SingleEliminationEngine();
        $state = thirdPlaceState($without, thirdPlaceField(4), [['p1', 'p2']]);
        $final = $without->pairNextRound($state);
        $state = $state->withRoundPlayed($final, []);

        $rounds = $this->engine->getRecordedRounds($state);
        expect($rounds[1]->isManual())->toBeTrue()
            ->and(thirdPlaceEvents($rounds[1]->getDerivation() ?? $final))->toBe(['p1 v p2', 'p4 v p3'])
            ->and($this->engine->getReadiness($state)->getUnresolvedEvents())->toBe($final->getEvents());

        $played = $state->withAdditionalResults([new Result($final->getEvents()[0], $final->getEvents()[0]->getParticipants()[0])]);
        expect($this->engine->isComplete($played))->toBeTrue()
            ->and($this->engine->getOutcome($played)?->getStandings()->getTiers())->toBe([0, 1, 2, 2]);
    });

    // The other way round. An engine without the option has one tie in its
    // last round, and would read the third-place tie as an event of the
    // application's own: its results would count in the records and its
    // two entrants would share a tier. The mark says what the event is.
    it('refuses, without the option, a state whose last round was recorded with the third-place tie', function (
        string $call,
        int $legsPerTie,
        string $recorded
    ): void {
        $with = new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legsPerTie, thirdPlace: true));
        $without = new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legsPerTie));

        $state = StageState::start(thirdPlaceField(8));
        while (!$with->isComplete($state)) {
            $pairing = $with->pairNextRound($state);
            $results = array_values(array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[$event->getMetadataValue('tie_leg') === 2 ? 1 : 0]),
                $pairing->getEvents()
            ));
            if ($pairing->getLabel() === 'final' && $recorded !== 'every result') {
                // Paired and not played, or the final alone played: an engine
                // without the option would take the second for a finished bracket
                $results = $recorded === 'no result' ? [] : array_slice($results, 0, $legsPerTie);
                $state = $state->withRoundPlayed($pairing, $results);
                break;
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }
        $state = StageState::fromJson($state->toJson());

        try {
            thirdPlaceCalls($without)[$call]($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                ->and($exception->getMessage())->toContain('third-place tie')
                // The first-named win: p1 and p2 are in the final, and p4
                // and p3, who went out in the semifinals, in the tie
                ->and($exception->getContext())->toBe(['round' => 3, 'participants' => ['p4', 'p3']]);

            return;
        }

        $this->fail("{$call}() read a last round with a third-place tie as the last round of a bracket without one.");
    })->with([['isComplete'], ['pairNextRound'], ['getOutcome']])
        ->with([[1], [2]])
        ->with([['every result'], ['the final alone'], ['no result']]);

    // Until its last round is recorded, a state says nothing of the option
    // and either engine pairs that round; and a field of three has the same
    // bracket with the option and without it
    it('reads, without the option, a state that holds no third-place tie', function (): void {
        $without = new SingleEliminationEngine();

        $beforeLast = thirdPlaceState($this->engine, thirdPlaceField(8), [['p1', 'p2', 'p3', 'p4'], ['p1', 'p3']]);
        expect(thirdPlaceEvents($without->pairNextRound($beforeLast)))->toBe(['p1 v p3'])
            ->and(thirdPlaceEvents($this->engine->pairNextRound($beforeLast)))->toBe(['p1 v p3', 'p4 v p2']);

        $three = thirdPlaceState($this->engine, thirdPlaceField(3), [['p2'], ['p1']]);
        expect(array_map(BracketTie::of(...), $three->getLastRound()?->getEvents() ?? []))->toBe([BracketTie::Final])
            ->and($without->isComplete($three))->toBeTrue()
            ->and(thirdPlaceTable($without->getOutcome($three) ?? throw new LogicException('No outcome.')))->toBe(['p1', 'p2', 'p3']);
    });

    // What the main branch has always allowed: an event the application
    // adds to a recorded round, which carries no mark, is read by nothing
    it('still reads, without the option, a last round with an unmarked event of the application', function (): void {
        $without = new SingleEliminationEngine();
        $field = thirdPlaceField(4);
        $state = thirdPlaceState($without, $field, [['p1', 'p2']]);
        $final = $without->pairNextRound($state);
        $own = new Event([$field[3], $field[2]], $final->getEvents()[0]->getRound());

        $state = $state->withRoundPlayed(
            new RoundPairing($final->getRoundNumber(), $final->getLabel(), [...$final->getEvents(), $own], []),
            [new Result($final->getEvents()[0], $field[0])]
        );

        expect($without->isComplete($state))->toBeTrue()
            ->and($without->getOutcome($state)?->getStandings()->getTiers())->toBe([0, 1, 2, 2]);
    });

    it('is part of the engine fingerprint, so a stamped state is not read by an engine without it', function (): void {
        $without = new SingleEliminationEngine();
        $field = thirdPlaceField(4);

        expect($this->engine->getFingerprint())->not->toBe($without->getFingerprint())
            ->and((new SingleEliminationEngine(new EliminationOptions(thirdPlace: false)))->getFingerprint())
            ->toBe($without->getFingerprint());

        foreach ([[$this->engine, $without], [$without, $this->engine]] as [$stamping, $reading]) {
            $state = StageState::start($field)->withEngineFingerprint($stamping->getFingerprint());

            expect($stamping->isComplete($state))->toBeFalse();
            try {
                $reading->isComplete($state);
                $this->fail('A state stamped under the other third-place setting was accepted.');
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch)
                    ->and($exception->getMessage())->toContain('third-place');
            }
        }
    });

    it('is refused by the double elimination engine, which decides third place in its losers bracket', function (): void {
        try {
            new DoubleEliminationEngine(new EliminationOptions(thirdPlace: true));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($exception->getMessage())->toContain('losers bracket');

            return;
        }

        $this->fail('The double elimination engine accepted the third-place option.');
    });

    it('marks no event of a bracket without the option', function (int $size, int $legsPerTie): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legsPerTie));
        $state = StageState::start(thirdPlaceField($size));

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            foreach ($pairing->getEvents() as $event) {
                expect($event->hasMetadata(BracketTie::METADATA_KEY))->toBeFalse()
                    ->and(BracketTie::of($event))->toBeNull()
                    ->and(array_keys($event->getMetadata()))->toBe($legsPerTie === 2 ? ['tie_leg'] : []);
            }
            $state = $state->withRoundPlayed($pairing, array_map(
                // The first-named entrant of the tie wins every leg
                fn(Event $event): Result => new Result($event, $event->getParticipants()[$event->getMetadataValue('tie_leg') === 2 ? 1 : 0]),
                $pairing->getEvents()
            ));
        }

        expect($engine->getOutcome($state)?->getResults())->toHaveCount($legsPerTie * ($size - 1));
    })->with([[2], [3], [4], [8], [13]])->with([[1], [2]]);
});

describe('BracketTie', function (): void {
    it('reads the tie an event is marked with, from the metadata alone', function (): void {
        [$a, $b] = thirdPlaceField(2);
        $round = new Round(3, ['label' => 'final']);

        expect(BracketTie::of(new Event([$a, $b], $round, ['bracket_tie' => 'final'])))->toBe(BracketTie::Final)
            ->and(BracketTie::of(new Event([$a, $b], $round, ['bracket_tie' => 'third-place'])))->toBe(BracketTie::ThirdPlace)
            ->and(BracketTie::of(new Event([$a, $b], null, ['tie_leg' => 2, 'bracket_tie' => 'third-place'])))->toBe(BracketTie::ThirdPlace)
            // The round label is not a mark
            ->and(BracketTie::of(new Event([$a, $b], $round)))->toBeNull()
            ->and(BracketTie::of(new Event([$a, $b], $round, ['tie_leg' => 1])))->toBeNull();
    });

    it('reads a value that is not the backing string of a case as no mark', function (mixed $value): void {
        [$a, $b] = thirdPlaceField(2);

        expect(BracketTie::of(new Event([$a, $b], null, ['bracket_tie' => $value])))->toBeNull();
    })->with([['semifinal'], ['Final'], [''], [1], [true], [null], [['final']]]);

    it('has the metadata key and the backing strings stored events carry', function (): void {
        expect(BracketTie::METADATA_KEY)->toBe('bracket_tie')
            ->and(array_map(fn(BracketTie $tie): string => $tie->value, BracketTie::cases()))->toBe(['final', 'third-place']);
    });

    it('says which place the entrant who advances from the tie has', function (): void {
        expect(BracketTie::Final->winnerRank())->toBe(1)
            ->and(BracketTie::ThirdPlace->winnerRank())->toBe(3);
    });

    it('survives the plain-data form of an event and of a stage state', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
        $field = thirdPlaceField(4);
        $state = thirdPlaceState($engine, $field, [['p1', 'p2']]);
        $last = $engine->pairNextRound($state);

        $registry = [];
        foreach ($field as $participant) {
            $registry[$participant->getId()] = $participant;
        }

        $restoredEvents = array_map(
            fn(Event $event): Event => Event::fromArray($event->toArray(), $registry),
            $last->getEvents()
        );
        $restoredState = StageState::fromJson($state->withRoundPlayed($last, [])->toJson());

        expect(array_map(BracketTie::of(...), $restoredEvents))->toBe([BracketTie::Final, BracketTie::ThirdPlace])
            ->and(array_map(BracketTie::of(...), $restoredState->getLastRound()?->getEvents() ?? []))
            ->toBe([BracketTie::Final, BracketTie::ThirdPlace]);
    });
});
