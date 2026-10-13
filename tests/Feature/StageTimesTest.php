<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SettledTie;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\Standings;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A stage state says after which round each adjustment was made and each
// participant withdrew, and an engine reads a recorded round from what was
// known when it was paired (ADR 0001, ADR 0007). These are the cases: the
// re-seeded bracket that was read wrongly, what is read where the time is
// not stated, the tie both entrants left, and the selector over a bracket a
// finalist withdrew from. The sweeps are in EliminationInvariantsTest.

/**
 * @return list<Participant>
 */
function timesField(int $size): array
{
    return array_map(static fn(int $number): Participant => new Participant("p{$number}", "P{$number}"), range(1, $size));
}

/**
 * Pair the next round and record it with the entrant of the lower number
 * winning every event.
 *
 * @throws InvalidConfigurationException When the engine or the state refuses the round
 */
function timesPlayRound(SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): StageState
{
    $pairing = $engine->pairNextRound($state);

    return $state->withRoundPlayed($pairing, array_map(
        static function (Event $event): Result {
            $entrants = array_values($event->getParticipants());
            usort($entrants, static fn(Participant $one, Participant $other): int => (int) substr($one->getId(), 1) <=> (int) substr($other->getId(), 1));

            return new Result($event, $entrants[0]);
        },
        $pairing->getEvents()
    ));
}

/**
 * The pairs of a round as sorted IDs, in the order of its events.
 *
 * @return list<string>
 */
function timesPairs(RoundPairing $pairing): array
{
    $pairs = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
        sort($ids, SORT_NATURAL);
        $pairs[implode(' v ', $ids)] = true;
    }

    return array_keys($pairs);
}

/**
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function timesIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The tier of each participant of a table, by ID, in the order of the IDs.
 *
 * @return array<string, int>
 */
function timesTiers(Standings $table): array
{
    $tiers = [];
    foreach (array_values($table->getEntries()) as $index => $entry) {
        $tiers[$entry->getParticipant()->getId()] = $table->getTiers()[$index] ?? -1;
    }
    ksort($tiers, SORT_NATURAL);

    return $tiers;
}

describe('the match outcome selector over a bracket a finalist withdrew from', function (): void {
    /**
     * A bracket of four played up to its last tie, which is between the
     * two entrants returned.
     *
     * @return array{SingleEliminationEngine|DoubleEliminationEngine, StageState, Participant, Participant}
     */
    $lastTie = function (string $variant): array {
        $engine = $variant === 'single' ? new SingleEliminationEngine() : new DoubleEliminationEngine();
        $state = StageState::start(timesField(4));
        for ($rounds = $variant === 'single' ? 1 : 4; $rounds > 0; --$rounds) {
            $state = timesPlayRound($engine, $state);
        }
        [$first, $second] = array_values($engine->pairNextRound($state)->getEvents()[0]->getParticipants());

        return [$engine, $state, $first, $second];
    };

    it('gives the finalist who stayed as the winner and the one who left as the loser', function (string $variant, bool $finalRecorded) use ($lastTie): void {
        [$engine, $state, $stays, $leaves] = $lastTie($variant);

        if ($finalRecorded) {
            $state = $state->withRoundPlayed($engine->pairNextRound($state), []);
        }
        $state = $state->withoutParticipant($leaves);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($state))->toBeTrue()
            ->and($outcome->getStandings()->getEntries()[0]->getParticipant())->toBe($stays)
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$stays])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$leaves])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($stays, [$leaves])])
            // Where the final was never paired the last round on record is the round before it
            ->and($outcome->getFinalRound())->toBe($state->getLastRound());
    })->with([
        'single elimination, the final recorded without a result' => ['single', true],
        'single elimination, the final never paired' => ['single', false],
        'double elimination, the grand final recorded without a result' => ['double', true],
        'double elimination, the grand final never paired' => ['double', false],
    ]);

    it('gives the same selection as the results do for a final that was played', function (string $variant) use ($lastTie): void {
        [$engine, $state] = $lastTie($variant);
        $state = timesPlayRound($engine, $state);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $fromResults = new StageOutcome($outcome->getStandings(), $outcome->getResults(), $outcome->getByes(), $outcome->getFinalRound());

        expect($outcome->getSettledTies())->toHaveCount(1)
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe(MatchOutcomeSelector::winners()->select($fromResults))
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe(MatchOutcomeSelector::losers()->select($fromResults));
    })->with(['single', 'double']);

    it('carries the reset as the settled tie of a double elimination bracket whose grand final the losers champion won', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(timesField(4));
        for ($rounds = 4; $rounds > 0; --$rounds) {
            $state = timesPlayRound($engine, $state);
        }

        // The losers champion wins the grand final, and the reset
        foreach (['grand final', 'grand final reset'] as $label) {
            $pairing = $engine->pairNextRound($state);
            [$winnersChampion, $losersChampion] = array_values($pairing->getEvents()[0]->getParticipants());
            expect($pairing->getLabel())->toBe($label);
            $state = $state->withRoundPlayed($pairing, [new Result($pairing->getEvents()[0], $losersChampion)]);
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome && isset($winnersChampion, $losersChampion));

        expect($outcome->getSettledTies())->toEqual([new SettledTie($losersChampion, [$winnersChampion])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$losersChampion]);
    });

    it('carries no settled ties on the outcome of a Swiss stage, which the selector reads from its results', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 1);
        $state = StageState::start(timesField(4));
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[1]),
            $pairing->getEvents()
        ));

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($outcome->getSettledTies())->toBeNull()
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toHaveCount(2);
    });
});
