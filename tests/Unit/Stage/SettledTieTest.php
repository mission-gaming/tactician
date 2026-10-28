<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SettledTie;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A tie as an engine settled it, and the selector that reads it from an
// outcome in the place of the results.

describe('SettledTie', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->event = new Event([$this->alice, $this->bob], new Round(1));
        $this->standings = new StandingsCalculator()->calculate([$this->alice, $this->bob], []);
    });

    it('holds who went on and who did not, as a list', function (): void {
        $tie = new SettledTie($this->alice, ['loser' => $this->bob]);

        expect($tie->getAdvancer())->toBe($this->alice)
            ->and($tie->getEliminated())->toBe([$this->bob]);
    });

    it('can say that nobody went on, and that an entrant stood alone', function (): void {
        $nobody = new SettledTie(null, [$this->alice, $this->bob]);
        $alone = new SettledTie($this->alice);

        expect($nobody->getAdvancer())->toBeNull()
            ->and($nobody->getEliminated())->toBe([$this->alice, $this->bob])
            ->and($alone->getEliminated())->toBe([]);
    });

    it('is carried by an outcome only when it is given: an outcome built without is read from its results', function (): void {
        $plain = new StageOutcome($this->standings, []);
        $settled = new StageOutcome($this->standings, [], settledTies: ['final' => new SettledTie($this->alice, [$this->bob])]);

        expect($plain->getSettledTies())->toBeNull()
            ->and($settled->getSettledTies())->toHaveCount(1)
            ->and(array_keys($settled->getSettledTies() ?? []))->toBe([0])
            ->and(StageOutcome::combining(['A' => $plain])->getSettledTies())->toBeNull();
    });

    // The settled ties are the whole answer where an outcome has them: no
    // final round and no result is needed, and none is read.
    it('is what the selector reads: the entrants who went on, and those who did not, in the order of the ties', function (): void {
        $carol = new Participant('p3', 'Carol');
        $dave = new Participant('p4', 'Dave');
        $outcome = new StageOutcome($this->standings, [], settledTies: [
            new SettledTie($this->bob, [$this->alice]),
            new SettledTie(null, [$carol, $dave]),
            new SettledTie($carol),
        ]);

        expect(MatchOutcomeSelector::winners()->select($outcome))->toBe([$this->bob, $carol])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$this->alice, $carol, $dave]);
    });

    it('is read in the place of results that say something else', function (): void {
        // The results say Alice; the engine that settled the tie said Bob
        $results = [new Result($this->event, $this->alice)];
        $outcome = new StageOutcome(
            $this->standings,
            $results,
            [],
            new RoundPairing(1, 'final', [$this->event]),
            settledTies: [new SettledTie($this->bob, [$this->alice])]
        );

        expect(MatchOutcomeSelector::winners()->select($outcome))->toBe([$this->bob]);
    });

    it('gives an empty selection for an outcome whose engine settled no tie, where one without them is refused', function (): void {
        expect(MatchOutcomeSelector::winners()->select(new StageOutcome($this->standings, [], settledTies: [])))->toBe([]);

        try {
            MatchOutcomeSelector::winners()->select(new StageOutcome($this->standings, []));
            $this->fail('An outcome with no final round and no settled ties was read.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOutcome);
        }
    });
});
