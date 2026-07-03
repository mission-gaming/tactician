<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Standings\StandingsCalculator;

/**
 * An outcome where the given participants finished in the given order
 * (each beat everyone below them).
 *
 * @param array<Participant> $ordered Best first
 */
function outcomeWithOrder(array $ordered): StageOutcome
{
    $results = [];
    $counted = count($ordered);
    for ($i = 0; $i < $counted - 1; ++$i) {
        for ($j = $i + 1; $j < $counted; ++$j) {
            $results[] = new Result(new Event([$ordered[$i], $ordered[$j]], new Round(1)), $ordered[$i]);
        }
    }

    $standings = (new StandingsCalculator())->calculate($ordered, $results);

    return new StageOutcome($standings, $results);
}

describe('RankRangeSelector', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
    });

    it('selects an overall rank slice in standings order', function (): void {
        $outcome = outcomeWithOrder([$this->alice, $this->bob, $this->carol, $this->dave]);

        $selector = RankRangeSelector::overall(1, 2);
        expect($selector->getSelectionSize())->toBe(2);
        expect(array_map(fn (Participant $p) => $p->getId(), $selector->select($outcome)))
            ->toBe(['p1', 'p2']);

        expect(array_map(fn (Participant $p) => $p->getId(), RankRangeSelector::overall(3, 4)->select($outcome)))
            ->toBe(['p3', 'p4']);
    });

    it('rejects an overall range past the table', function (): void {
        $outcome = outcomeWithOrder([$this->alice, $this->bob]);

        expect(fn () => RankRangeSelector::overall(1, 3)->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'past');
    });

    it('selects per-group blocks pool by pool, winners first', function (): void {
        $poolA = outcomeWithOrder([$this->alice, $this->bob]);
        $poolB = outcomeWithOrder([$this->carol, $this->dave]);
        $combined = StageOutcome::combining(['A' => $poolA, 'B' => $poolB]);

        $qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);

        // Pool winners head the list in pool order, then runners-up: the
        // ordering fold seeding wants for cross-pool knockout pairings
        expect(array_map(fn (Participant $p) => $p->getId(), $qualifiers))
            ->toBe(['p1', 'p3', 'p2', 'p4']);

        $runnersUp = RankRangeSelector::perGroup(2, 2)->select($combined);
        expect(array_map(fn (Participant $p) => $p->getId(), $runnersUp))->toBe(['p2', 'p4']);
    });

    it('reports no fixed size for per-group selections', function (): void {
        expect(RankRangeSelector::topPerGroup(2)->getSelectionSize())->toBeNull();
    });

    it('rejects per-group selection over an unpooled outcome', function (): void {
        $outcome = outcomeWithOrder([$this->alice, $this->bob]);

        expect(fn () => RankRangeSelector::topPerGroup(1)->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'pooled');
    });

    it('rejects a rank a pool cannot supply', function (): void {
        $combined = StageOutcome::combining([
            'A' => outcomeWithOrder([$this->alice, $this->bob]),
        ]);

        expect(fn () => RankRangeSelector::perGroup(3, 3)->select($combined))
            ->toThrow(InvalidConfigurationException::class, 'no rank 3');
    });

    it('round-trips through plain configuration data', function (): void {
        $selector = RankRangeSelector::fromArray(['mode' => 'per-group', 'from' => 3, 'to' => 4]);
        expect($selector->toArray())->toBe(['mode' => 'per-group', 'from' => 3, 'to' => 4]);

        expect(RankRangeSelector::overall(1, 8)->toArray())
            ->toBe(['mode' => 'overall', 'from' => 1, 'to' => 8]);
    });

    it('rejects invalid configuration', function (): void {
        expect(fn () => RankRangeSelector::fromArray(['mode' => 'sideways', 'from' => 1, 'to' => 2]))
            ->toThrow(InvalidConfigurationException::class, 'mode');
        expect(fn () => RankRangeSelector::fromArray(['mode' => 'overall', 'from' => 'one', 'to' => 2]))
            ->toThrow(InvalidConfigurationException::class, 'integers');
        expect(fn () => RankRangeSelector::overall(3, 2))
            ->toThrow(InvalidConfigurationException::class, 'from <= to');
    });
});

describe('MatchOutcomeSelector', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->eve = new Participant('p5', 'Eve');
    });

    it('selects winners in bracket order with byes appended', function (): void {
        $event1 = new Event([$this->alice, $this->bob], new Round(1));
        $event2 = new Event([$this->carol, $this->dave], new Round(1));
        $results = [new Result($event1, $this->alice), new Result($event2, $this->dave)];
        $finalRound = new RoundPairing(1, 'quarterfinal', [$event1, $event2], [$this->eve]);

        $standings = (new StandingsCalculator())->calculate(
            [$this->alice, $this->bob, $this->carol, $this->dave, $this->eve],
            $results
        );
        $outcome = new StageOutcome($standings, $results, ['p5' => 1], $finalRound);

        $winners = MatchOutcomeSelector::winners()->select($outcome);
        expect(array_map(fn (Participant $p) => $p->getId(), $winners))->toBe(['p1', 'p4', 'p5']);

        $losers = MatchOutcomeSelector::losers()->select($outcome);
        expect(array_map(fn (Participant $p) => $p->getId(), $losers))->toBe(['p2', 'p3']);
    });

    it('resolves two-legged final rounds through the tie decision', function (): void {
        $leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
        $results = [
            new Result($leg1, $this->alice),
            new Result($leg2, $this->bob, [], [TieDecision::TIE_WINNER_KEY => 'p2']),
        ];
        $finalRound = new RoundPairing(1, 'final', [$leg1, $leg2]);

        $standings = (new StandingsCalculator())->calculate([$this->alice, $this->bob], $results);
        $outcome = new StageOutcome($standings, $results, [], $finalRound);

        $winners = MatchOutcomeSelector::winners()->select($outcome);
        expect(array_map(fn (Participant $p) => $p->getId(), $winners))->toBe(['p2']);

        $losers = MatchOutcomeSelector::losers()->select($outcome);
        expect(array_map(fn (Participant $p) => $p->getId(), $losers))->toBe(['p1']);
    });

    it('rejects outcomes without a final round', function (): void {
        $outcome = outcomeWithOrder([$this->alice, $this->bob]);

        expect(fn () => MatchOutcomeSelector::winners()->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'final round');
    });

    it('rejects final rounds with missing or drawn results', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $standings = (new StandingsCalculator())->calculate([$this->alice, $this->bob], []);

        $missing = new StageOutcome($standings, [], [], new RoundPairing(1, 'final', [$event]));
        expect(fn () => MatchOutcomeSelector::winners()->select($missing))
            ->toThrow(InvalidConfigurationException::class, 'no complete recorded result');

        $drawn = new StageOutcome(
            $standings,
            [new Result($event)],
            [],
            new RoundPairing(1, 'final', [$event])
        );
        expect(fn () => MatchOutcomeSelector::winners()->select($drawn))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('reports no fixed size and round-trips through configuration', function (): void {
        expect(MatchOutcomeSelector::winners()->getSelectionSize())->toBeNull();
        expect(MatchOutcomeSelector::winners()->toArray())->toBe(['mode' => 'winners']);
        expect(MatchOutcomeSelector::fromArray(['mode' => 'losers'])->toArray())->toBe(['mode' => 'losers']);
        expect(fn () => MatchOutcomeSelector::fromArray(['mode' => 'survivors']))
            ->toThrow(InvalidConfigurationException::class, 'mode');
    });
});
