<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A score group is the participants who are level. A ranking value is a
// float sum, and a sum of values that binary floating point cannot hold
// exactly depends on the order of its terms: with 1 for a win and 0.1 for a
// draw, win-draw-draw is 1.2000000000000002 and draw-draw-win is 1.2. The
// two participants are level, and the engine must treat them as level.

/**
 * Record a round from its results, each given as [first, second, winner]
 * with a null winner for a draw.
 *
 * @param array<array{Participant, Participant, Participant|null}> $games
 * @param array<Participant> $byes
 * @throws InvalidConfigurationException When the round does not follow the recorded ones
 */
function withScoredRound(StageState $state, int $round, array $games, array $byes = []): StageState
{
    $results = array_map(
        fn(array $game): Result => new Result(new Event([$game[0], $game[1]], new Round($round)), $game[2]),
        $games
    );

    return $state->withRoundPlayed(
        new RoundPairing($round, null, array_map(fn(Result $result) => $result->getEvent(), $results), $byes),
        $results
    );
}

/**
 * The opponent of a participant in a pairing.
 */
function opponentIn(RoundPairing $pairing, Participant $participant): ?Participant
{
    foreach ($pairing->getEvents() as $event) {
        if ($event->hasParticipant($participant)) {
            [$first, $second] = $event->getParticipants();

            return $first->getId() === $participant->getId() ? $second : $first;
        }
    }

    return null;
}

describe('Swiss score groups', function (): void {
    beforeEach(function (): void {
        $this->field = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $index => $id) {
            $this->field[$id] = new Participant($id, strtoupper($id), $index + 1);
        }

        // Three rounds in which a, b, c and d each take a win and two draws
        // from e, f, g and h, and never meet each other. a and c win first;
        // b and d win last.
        $this->levelLeaders = function (): StageState {
            ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e, 'f' => $f, 'g' => $g, 'h' => $h] = $this->field;

            $state = StageState::start(array_values($this->field));
            $state = withScoredRound($state, 1, [[$a, $e, $a], [$b, $f, null], [$c, $g, $c], [$d, $h, null]]);
            $state = withScoredRound($state, 2, [[$a, $f, null], [$b, $g, null], [$c, $h, null], [$d, $e, null]]);

            return withScoredRound($state, 3, [[$a, $g, null], [$b, $h, $b], [$c, $e, null], [$d, $f, $d]]);
        };
    });

    it('starts from two sums of the same results that differ in the last bits', function (): void {
        $standings = (new StandingsCalculator(new WinDrawLossRanking(1.0, 0.1)))
            ->calculate(array_values($this->field), ($this->levelLeaders)()->getResults());

        $a = $standings->getEntryFor($this->field['a']);
        $b = $standings->getEntryFor($this->field['b']);

        expect([$a?->getWins(), $a?->getDraws()])->toBe([$b?->getWins(), $b?->getDraws()])
            ->and($a?->getRankingValue())->not->toBe($b?->getRankingValue());
    });

    it('shuffles level participants as one group when their sums differ in the last bits', function (): void {
        $state = ($this->levelLeaders)();

        // a, b, c and d are level and have not met, so a shuffled pairing
        // gives a each of the other three as an opponent sooner or later.
        $opponents = [];
        for ($seed = 1; $seed <= 40; ++$seed) {
            $engine = new SwissPairingEngine(
                standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(1.0, 0.1)),
                randomizer: new Randomizer(new Mt19937($seed))
            );
            $opponent = opponentIn($engine->pairNextRound($state), $this->field['a']);
            $opponents[$opponent?->getId()] = true;
        }

        ksort($opponents);
        expect(array_keys($opponents))->toBe(['b', 'c', 'd']);
    });

    it('groups ranking values that differ by less than the tolerance', function (): void {
        // No results at all: the ranking value comes straight from the
        // strategy, which gives a and c the sum 0.1 + 0.2 and b and d 0.3.
        $noisy = new readonly class implements RankingStrategy {
            /**
             * @param array<Result> $results
             */
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return in_array($participant->getId(), ['a', 'c'], true) ? 0.1 + 0.2 : 0.3;
            }
        };
        $field = [$this->field['a'], $this->field['b'], $this->field['c'], $this->field['d']];

        $opponents = [];
        for ($seed = 1; $seed <= 40; ++$seed) {
            $engine = new SwissPairingEngine(
                standingsCalculator: new StandingsCalculator($noisy),
                randomizer: new Randomizer(new Mt19937($seed))
            );
            $opponent = opponentIn($engine->pairNextRound(StageState::start($field)), $this->field['a']);
            $opponents[$opponent?->getId()] = true;
        }

        ksort($opponents);
        expect(array_keys($opponents))->toBe(['b', 'c', 'd']);
    });

    it('orders a credited bye level with the win it stands for', function (): void {
        // 0.6 for a win, 0.1 for a draw. y won and drew twice: 0.6 + 0.1 +
        // 0.1 is 0.7999999999999999. x drew twice and had a bye, credited
        // as a win: 0.2 + 0.6 is 0.8. They are level, so the table decides,
        // and the table has y first: a bye is not a result.
        $x = new Participant('x', 'X', 1);
        $y = new Participant('y', 'Y', 2);
        $q = new Participant('q', 'Q', 3);
        $r = new Participant('r', 'R', 4);
        $s = new Participant('s', 'S', 5);

        $state = StageState::start([$x, $y, $q, $r, $s]);
        $state = withScoredRound($state, 1, [[$y, $q, $y], [$s, $r, $s]], [$x]);
        $state = withScoredRound($state, 2, [[$x, $r, null], [$s, $y, null]], [$q]);
        $state = withScoredRound($state, 3, [[$q, $x, null], [$r, $y, null]], [$s]);

        // s withdraws, leaving y, x, q and r. x and y have each been named
        // first once, so the role goes by placing: the lower-placed
        // participant is named first.
        $pairing = (new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(0.6, 0.1))
        ))->pairNextRound($state->withoutParticipant($s));

        $ids = array_map(
            fn(Event $event): array => array_map(fn(Participant $p) => $p->getId(), $event->getParticipants()),
            $pairing->getEvents()
        );
        expect($ids)->toBe([['x', 'y'], ['r', 'q']]);
    });

    // The two guards below hold before and after the tolerance: it must not
    // change anything for scoring that floats hold exactly.
    it('keeps participants half a point apart in different groups', function (
        WinDrawLossRanking $ranking
    ): void {
        ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d] = $this->field;

        // a beat c and b drew with d: a leads alone, then b and d, then c.
        $state = withScoredRound(StageState::start([$a, $b, $c, $d]), 1, [[$a, $c, $a], [$b, $d, null]]);

        for ($seed = 1; $seed <= 40; ++$seed) {
            $engine = new SwissPairingEngine(
                standingsCalculator: new StandingsCalculator($ranking),
                randomizer: new Randomizer(new Mt19937($seed))
            );

            // a meets one of the two in the group below, never c.
            expect(opponentIn($engine->pairNextRound($state), $a)?->getId())->toBeIn(['b', 'd']);
        }
    })->with([
        '3/1/0' => [WinDrawLossRanking::threeOneZero()],
        '1/0.5/0' => [WinDrawLossRanking::oneHalfZero()],
    ]);

    it('keeps ranking values a millionth apart in different groups', function (): void {
        $fine = new readonly class implements RankingStrategy {
            /**
             * @param array<Result> $results
             */
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return ['a' => 4.0e-6, 'b' => 3.0e-6, 'c' => 2.0e-6, 'd' => 1.0e-6][$participant->getId()] ?? 0.0;
            }
        };
        $field = [$this->field['d'], $this->field['c'], $this->field['b'], $this->field['a']];

        for ($seed = 1; $seed <= 40; ++$seed) {
            $engine = new SwissPairingEngine(
                standingsCalculator: new StandingsCalculator($fine),
                randomizer: new Randomizer(new Mt19937($seed))
            );

            expect(opponentIn($engine->pairNextRound(StageState::start($field)), $this->field['a'])?->getId())
                ->toBe('b');
        }
    });
});
