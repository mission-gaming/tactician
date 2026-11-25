<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SlotPlaceholder;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;

// Only a winners tie its results decided drops its loser (issue #182,
// docs/adr/0026-only-a-winners-tie-its-results-decided-drops-its-loser.md).

/**
 * Every event of a round with a result for those with no withdrawn
 * entrant, the first-named winning.
 *
 * @param array<Event> $events
 * @param list<string> $withdrawn
 *
 * @return list<Result>
 */
function dropperResults(array $events, array $withdrawn): array
{
    $results = [];
    foreach ($events as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
        if (array_intersect($ids, $withdrawn) === []) {
            $results[] = new Result($event, array_values($event->getParticipants())[0]);
        }
    }

    return $results;
}

/**
 * A round as text: its events, then its byes.
 */
function dropperShape(RoundPairing $round): string
{
    $events = array_map(
        static fn(Event $event): string => implode(' v ', array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()))),
        array_values($round->getEvents())
    );

    return implode(', ', $events) . '; byes ' . implode(',', array_map(static fn(Participant $participant): string => $participant->getId(), array_values($round->getByes())));
}

/**
 * The nodes of losers round 1 of the view as text.
 *
 * @return list<string>
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function dropperLosersRoundOne(DoubleEliminationEngine $engine, StageState $state): array
{
    foreach ($engine->getBracket($state)->getRounds() as $round) {
        if ($round->getSide() === BracketSide::Losers && $round->getRound() === 1) {
            return array_map(static fn(BracketNode $node): string => $node->getStatus()->value . ' (' . implode(' v ', array_map(
                static fn($slot): string => $slot->getParticipant()?->getId() ?? '[' . $slot->getPlaceholder()?->value . ']',
                $node->getSlots()
            )) . ')', $round->getNodes());
        }
    }

    return [];
}

describe('the loser of a winners tie given away by its withdrawal', function (): void {
    /**
     * Eight entrants: p8 withdraws during winners round 1, which gives
     * p1 v p8 away; the other ties are played, the first-named winning, and
     * so is losers round 1.
     *
     * @return array{DoubleEliminationEngine, StageState, array<string, Participant>, RoundPairing, RoundPairing}
     */
    $afterLosersRoundOne = static function (): array {
        $field = [];
        foreach (range(1, 8) as $i) {
            $field["p{$i}"] = new Participant("p{$i}", "P{$i}");
        }
        $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));
        $state = StageState::start(array_values($field));
        $winnersRoundOne = $engine->pairNextRound($state);
        expect(dropperShape($winnersRoundOne))->toBe('p1 v p8, p4 v p5, p2 v p7, p3 v p6; byes ');
        $state = $state->withRoundPaired($winnersRoundOne)
            ->withoutParticipant($field['p8'])
            ->withAdditionalResults(dropperResults($winnersRoundOne->getEvents(), ['p8']));
        $losersRoundOne = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($losersRoundOne, dropperResults($losersRoundOne->getEvents(), ['p8']));

        return [$engine, $state, $field, $winnersRoundOne, $losersRoundOne];
    };

    it('drops nobody: the entrant who would have met it in the losers bracket has a bye', function () use ($afterLosersRoundOne): void {
        [$engine, $state, , , $losersRoundOne] = $afterLosersRoundOne();

        // Then: p5, who lost to p4, stands alone in losers round 1
        expect(dropperShape($losersRoundOne))->toBe('p7 v p6; byes p5')
            ->and(dropperLosersRoundOne($engine, $state))->toBe([
                'bye ([' . SlotPlaceholder::Void->value . '] v p5)',
                'decided (p7 v p6)',
            ]);
    });

    it('is shown the same whatever the other entrant of its tie does later', function (bool $fromHistory) use ($afterLosersRoundOne): void {
        [$engine, $state, $field, $winnersRoundOne, $losersRoundOne] = $afterLosersRoundOne();
        $before = dropperLosersRoundOne($engine, $state);

        // Given: p1, who went through the tie p8 gave away, withdraws before
        // winners round 2 is paired, so the tie turns void (ADR 0011)
        $state = $state->withoutParticipant($field['p1']);
        if ($fromHistory) {
            $state = StageState::fromHistory(
                array_values($field),
                [$winnersRoundOne, $losersRoundOne],
                [...dropperResults($winnersRoundOne->getEvents(), ['p8']), ...dropperResults($losersRoundOne->getEvents(), ['p8'])],
                [new Withdrawal($field['p8'], 0), new Withdrawal($field['p1'], 2)]
            );
        }

        // Then: Losers round 1 is shown as it was, and winners round 2 goes on without p1
        expect(dropperLosersRoundOne($engine, $state))->toBe($before)
            ->and(dropperShape($engine->pairNextRound($state)))->toBe('p2 v p3; byes p4');
    })->with(['through the verbs' => [false], 'from the rows of an application' => [true]]);

    it('counts the bye in the table, and places the withdrawn entrant with the first losers out', function () use ($afterLosersRoundOne): void {
        [$engine, $state, $field] = $afterLosersRoundOne();
        while (!$engine->isComplete($state)) {
            $round = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($round, dropperResults($round->getEvents(), ['p8']));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $table = $outcome->getStandings();
        $tierOf = static function (string $id) use ($table): int {
            foreach (array_values($table->getEntries()) as $index => $entry) {
                if ($entry->getParticipant()->getId() === $id) {
                    return $table->getTiers()[$index] ?? -1;
                }
            }

            return -1;
        };

        expect($table->getEntryFor($field['p5'])?->getByes())->toBe(1)
            ->and($tierOf('p8'))->toBe($tierOf('p6'))
            ->and($tierOf('p8'))->toBe(max([0, ...array_values($table->getTiers())]));
    });
});

it('still drops the loser of a winners tie its results decided, who gives its losers tie away when it withdraws', function (): void {
    // Given: Eight entrants; winners round 1 is played, the first-named
    // winning, and p5, who lost to p4, withdraws before losers round 1
    $field = [];
    foreach (range(1, 8) as $i) {
        $field["p{$i}"] = new Participant("p{$i}", "P{$i}");
    }
    $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));
    $state = StageState::start(array_values($field));
    $winnersRoundOne = $engine->pairNextRound($state);
    $state = $state->withRoundPlayed($winnersRoundOne, dropperResults($winnersRoundOne->getEvents(), []))
        ->withoutParticipant($field['p5']);

    // Then: p5 dropped, and its losers tie with p8 is given away (the round
    // lists p8 among its byes, a walkover)
    $losersRoundOne = $engine->pairNextRound($state);
    $state = $state->withRoundPlayed($losersRoundOne, dropperResults($losersRoundOne->getEvents(), ['p5']));
    expect(dropperShape($losersRoundOne))->toBe('p7 v p6; byes p8')
        ->and(dropperLosersRoundOne($engine, $state)[0])->toBe(BracketNodeStatus::Uncontested->value . ' (p8 v p5)');
});
