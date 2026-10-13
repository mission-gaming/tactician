<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

// StageState::fromArray() used to check the shape of stored data and trust
// its history: rounds in any order, a result of a round that is not
// recorded, an event filed under another round's pairing. An engine then
// replayed that history as if a sequence of verbs had recorded it. The rule
// now: a stored state is recorded again through withRoundPlayed(), so what a
// verb refuses of a live state is refused of a stored one, with the same
// reason.

/**
 * A stored state of Alice, Bob, Carol and Dan after two rounds, each round
 * with two events and one result: sound, as toArray() writes it.
 *
 * @return array<string, mixed>
 */
function storedHistory(): array
{
    $event = static fn(string $first, string $second, ?int $round): array => [
        'participants' => [$first, $second],
        'round' => $round === null ? null : ['number' => $round, 'metadata' => []],
        'metadata' => [],
    ];
    $result = static fn(array $event, string $winner): array => [
        'event' => $event,
        'winner' => $winner,
        'scores' => [],
        'metadata' => [],
    ];

    return [
        'format_version' => 1,
        'participants' => [
            ['id' => 'a', 'label' => 'Alice', 'seed' => 1, 'metadata' => []],
            ['id' => 'b', 'label' => 'Bob', 'seed' => 2, 'metadata' => []],
            ['id' => 'c', 'label' => 'Carol', 'seed' => 3, 'metadata' => []],
            ['id' => 'd', 'label' => 'Dan', 'seed' => 4, 'metadata' => []],
        ],
        'active' => ['a', 'b', 'c', 'd'],
        'rounds' => [
            ['round' => 1, 'label' => null, 'events' => [$event('a', 'b', 1), $event('c', 'd', 1)], 'byes' => []],
            ['round' => 2, 'label' => null, 'events' => [$event('a', 'c', 2), $event('b', 'd', 2)], 'byes' => []],
        ],
        'results' => [
            $result($event('a', 'b', 1), 'a'),
            $result($event('a', 'c', 2), 'c'),
        ],
    ];
}

/**
 * The configuration error reading a stored state ends in.
 *
 * @param array<string, mixed> $stored
 *
 * @throws LogicException When the state is read
 */
function storedHistoryRefusal(array $stored): InvalidConfigurationException
{
    try {
        StageState::fromArray($stored);
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    throw new LogicException('The stored state was read.');
}

describe('a stored stage state', function (): void {
    it('is read when its history is one the verbs record', function (): void {
        $stored = storedHistory();

        $state = StageState::fromArray($stored);

        expect($state->toArray())->toBe($stored)
            ->and($state->getNextRoundNumber())->toBe(3)
            ->and($state->getResults())->toHaveCount(2);
    });

    it('is refused when its history is one a verb refuses, with the reason the verb gives', function (
        Closure $corrupt,
        InvalidConfigurationReason $reason,
        string $issue,
        array $context
    ): void {
        $refusal = storedHistoryRefusal($corrupt(storedHistory()));

        expect($refusal->getReason())->toBe($reason)
            ->and($refusal->getConfigurationIssue())->toBe($issue)
            ->and($refusal->getContext())->toBe($context);
    })->with([
        'the rounds in reverse order' => [
            function (array $stored): array {
                $stored['rounds'] = array_reverse($stored['rounds']);
                $stored['results'] = array_reverse($stored['results']);

                return $stored;
            },
            InvalidConfigurationReason::RoundOutOfSequence,
            'Rounds must be recorded in play order with increasing round numbers',
            ['last_round' => 2, 'pairing_round' => 1],
        ],
        'one round recorded twice' => [
            function (array $stored): array {
                $stored['rounds'][] = $stored['rounds'][1];

                return $stored;
            },
            InvalidConfigurationReason::RoundOutOfSequence,
            'Rounds must be recorded in play order with increasing round numbers',
            ['last_round' => 2, 'pairing_round' => 2],
        ],
        'a pairing renumbered under its events' => [
            function (array $stored): array {
                $stored['rounds'][1]['round'] = 7;

                return $stored;
            },
            InvalidConfigurationReason::EventNotInRound,
            'Pairing contains an event from a different round',
            ['pairing_round' => 7, 'event_round' => 2],
        ],
        'a pairing that holds an event with no round' => [
            function (array $stored): array {
                $stored['rounds'][0]['events'][1]['round'] = null;

                return $stored;
            },
            InvalidConfigurationReason::EventWithoutRoundNumber,
            'Pairing contains an event from a different round',
            ['pairing_round' => 1, 'event_round' => null],
        ],
        'a result for an event its round does not hold' => [
            function (array $stored): array {
                $stored['results'][0]['event']['participants'] = ['a', 'd'];

                return $stored;
            },
            InvalidConfigurationReason::EventNotInRound,
            'Result references an event that is not part of the pairing being recorded',
            ['pairing_round' => 1, 'event' => '1:a|d:1'],
        ],
        'a result of a round that is not recorded' => [
            function (array $stored): array {
                $stored['results'][1]['event']['round']['number'] = 3;

                return $stored;
            },
            InvalidConfigurationReason::EventNotInRound,
            'Stored result belongs to a round that is not recorded, or does not stand with the results of its round',
            ['result' => 1, 'result_round' => 3, 'recorded_rounds' => [1, 2]],
        ],
        'a result with no round' => [
            function (array $stored): array {
                $stored['results'][0]['event']['round'] = null;

                return $stored;
            },
            InvalidConfigurationReason::EventWithoutRoundNumber,
            'Stored result belongs to a round that is not recorded, or does not stand with the results of its round',
            ['result' => 0, 'result_round' => null, 'recorded_rounds' => [1, 2]],
        ],
        'a result of round 1 after the results of round 2' => [
            function (array $stored): array {
                $stored['results'] = array_reverse($stored['results']);

                return $stored;
            },
            InvalidConfigurationReason::EventNotInRound,
            'Stored result belongs to a round that is not recorded, or does not stand with the results of its round',
            // The result of round 2 stands first and is recorded with its
            // round; the one that cannot be placed is the result of round 1
            // that follows it
            ['result' => 1, 'result_round' => 1, 'recorded_rounds' => [1, 2]],
        ],
        'results and no rounds' => [
            function (array $stored): array {
                $stored['rounds'] = [];

                return $stored;
            },
            InvalidConfigurationReason::EventNotInRound,
            'Stored result belongs to a round that is not recorded, or does not stand with the results of its round',
            ['result' => 0, 'result_round' => 1, 'recorded_rounds' => []],
        ],
    ]);

    // The same history, live: the verb refuses it for the same reason
    it('is refused for the reason a live state is', function (): void {
        $alice = new Participant('a', 'Alice');
        $bob = new Participant('b', 'Bob');
        $first = new RoundPairing(1, null, [new Event([$alice, $bob], new Round(1))]);
        $second = new RoundPairing(2, null, [new Event([$bob, $alice], new Round(2))]);

        $live = null;
        try {
            StageState::start([$alice, $bob])->withRoundPlayed($second, [])->withRoundPlayed($first, []);
        } catch (InvalidConfigurationException $exception) {
            $live = $exception;
        }

        $stored = StageState::start([$alice, $bob])->withRoundPlayed($first, [])->withRoundPlayed($second, [])->toArray();
        $stored['rounds'] = array_reverse($stored['rounds']);
        $refusal = storedHistoryRefusal($stored);

        expect($live?->getReason())->toBe(InvalidConfigurationReason::RoundOutOfSequence)
            ->and($refusal->getReason())->toBe($live?->getReason())
            ->and($refusal->getMessage())->toBe($live?->getMessage())
            ->and($refusal->getContext())->toBe($live?->getContext());
    });

    it('is refused as JSON too', function (): void {
        $stored = storedHistory();
        $stored['rounds'] = array_reverse($stored['rounds']);

        expect(fn(): StageState => StageState::fromJson(json_encode($stored, JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidConfigurationException::class, 'Rounds must be recorded in play order');
    });

    // What the verbs accept is accepted from storage: a state that holds it
    // must load, so that it can be read and repaired.
    it('is read with a gap in its round numbers', function (): void {
        $stored = storedHistory();
        $stored['rounds'][1]['round'] = 5;
        foreach ([0, 1] as $index) {
            $stored['rounds'][1]['events'][$index]['round']['number'] = 5;
        }
        $stored['results'][1]['event']['round']['number'] = 5;

        expect(StageState::fromArray($stored)->getNextRoundNumber())->toBe(6);
    });

    it('is read with two results of one event, which withResultReplaced() repairs', function (): void {
        $stored = storedHistory();
        $stored['results'][] = $stored['results'][1];

        $state = StageState::fromArray($stored);
        expect($state->getResults())->toHaveCount(3)
            ->and($state->toArray())->toBe($stored);

        $carol = $state->getParticipants()[2];
        $repaired = $state->withResultReplaced(new Result($state->getResults()[1]->getEvent(), $carol));
        expect($repaired->getResults())->toHaveCount(2);
    });

    it('keeps a result added to a round after it was recorded where it was added', function (): void {
        $alice = new Participant('a', 'Alice');
        $bob = new Participant('b', 'Bob');
        $carol = new Participant('c', 'Carol');
        $dan = new Participant('d', 'Dan');
        $one = new Event([$alice, $bob], new Round(1));
        $two = new Event([$carol, $dan], new Round(1));
        $three = new Event([$alice, $carol], new Round(2));

        $state = StageState::start([$alice, $bob, $carol, $dan])
            ->withRoundPlayed(new RoundPairing(1, null, [$one, $two]), [new Result($two, $dan)])
            ->withAdditionalResults([new Result($one, $alice)])
            ->withRoundPlayed(new RoundPairing(2, null, [$three]), [new Result($three, $carol)]);

        $restored = StageState::fromArray($state->toArray());

        expect($restored)->toEqual($state)
            ->and(array_map(fn(Result $result): ?string => $result->getWinner()?->getId(), $restored->getResults()))
            ->toBe(['d', 'a', 'c']);
    });
});
