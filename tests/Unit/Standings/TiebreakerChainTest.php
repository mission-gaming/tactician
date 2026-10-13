<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\RankingValueTiebreaker;
use MissionGaming\Tactician\Standings\ScoreDifferenceTiebreaker;
use MissionGaming\Tactician\Standings\ScoresForTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerChain;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

/**
 * The exception a call ends in.
 *
 * @param Closure(): mixed $call
 */
function chainRefusal(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    throw new LogicException('The call was not refused.');
}

describe('a tiebreaker chain built from plain data', function (): void {
    it('is built from an array of identifiers, in their order', function (): void {
        $chain = TiebreakerChain::fromIdentifiers(['wins', 'score-difference', 'scores-for', 'buchholz', 'sonneborn-berger', 'ranking-value']);

        expect(array_map(static fn(TiebreakerInterface $tiebreaker): string => $tiebreaker::class, $chain))->toBe([
            WinsTiebreaker::class,
            ScoreDifferenceTiebreaker::class,
            ScoresForTiebreaker::class,
            BuchholzTiebreaker::class,
            SonnebornBergerTiebreaker::class,
            RankingValueTiebreaker::class,
        ])->and(TiebreakerChain::fromIdentifiers([]))->toBe([]);
    });

    it('names every tiebreaker by the name it gives itself, which is the key of its value', function (): void {
        foreach (TiebreakerChain::identifiers() as $identifier) {
            [$tiebreaker] = TiebreakerChain::fromIdentifiers([$identifier]);

            expect($tiebreaker->getName())->toBe($identifier);
        }

        expect(TiebreakerChain::identifiers())->toBe([
            'wins', 'score-difference', 'scores-for', 'ranking-value', 'buchholz', 'sonneborn-berger',
            'between-tied:wins', 'between-tied:score-difference', 'between-tied:scores-for', 'between-tied:ranking-value',
            'between-tied:buchholz', 'between-tied:sonneborn-berger',
        ]);
    });

    it('wraps a measure for the events between the tied participants', function (): void {
        [$tiebreaker] = TiebreakerChain::fromIdentifiers(['between-tied:score-difference']);

        expect($tiebreaker)->toBeInstanceOf(BetweenTiedParticipants::class);
        assert($tiebreaker instanceof BetweenTiedParticipants);
        expect($tiebreaker->getMeasure())->toBeInstanceOf(ScoreDifferenceTiebreaker::class);
    });

    it('reads the bye treatment of a tiebreaker computed from opponents from a map', function (): void {
        $chain = TiebreakerChain::fromIdentifiers([
            ['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value'],
            ['tiebreaker' => 'sonneborn-berger', 'byes' => 'zero'],
            ['tiebreaker' => 'wins'],
        ]);

        expect($chain)->toEqual([
            new BuchholzTiebreaker(ByeTreatment::OwnRankingValue),
            new SonnebornBergerTiebreaker(ByeTreatment::Zero),
            new WinsTiebreaker(),
        ])
            // Left out, null, and the bare identifier are the default
            ->and(TiebreakerChain::fromIdentifiers([['tiebreaker' => 'buchholz']]))->toEqual([new BuchholzTiebreaker()])
            ->and(TiebreakerChain::fromIdentifiers([['tiebreaker' => 'buchholz', 'byes' => null]]))->toEqual([new BuchholzTiebreaker()])
            ->and(TiebreakerChain::fromIdentifiers([['tiebreaker' => 'buchholz', 'byes' => 'uncounted']]))->toEqual([new BuchholzTiebreaker()])
            ->and(TiebreakerChain::fromIdentifiers(['buchholz']))->toEqual([new BuchholzTiebreaker()])
            // A map may carry the version of its form
            ->and(TiebreakerChain::fromIdentifiers([['format_version' => 1, 'tiebreaker' => 'wins']]))->toEqual([new WinsTiebreaker()]);
    });

    it('refuses what it cannot read', function (mixed $entry, InvalidConfigurationReason $reason, string $message): void {
        $exception = chainRefusal(fn() => TiebreakerChain::fromIdentifiers(['wins', $entry]));

        expect($exception->getReason())->toBe($reason)
            ->and($exception->getMessage())->toContain($message);
    })->with([
        'an identifier that is not one' => ['head-to-head', InvalidConfigurationReason::UnknownIdentifier, "Unknown tiebreaker 'head-to-head'"],
        'a measure that is not one' => ['between-tied:goals', InvalidConfigurationReason::UnknownIdentifier, "Unknown tiebreaker 'between-tied:goals'"],
        'a measure between the tied, twice over' => ['between-tied:between-tied:wins', InvalidConfigurationReason::UnknownIdentifier, 'Unknown tiebreaker'],
        'another case' => ['Wins', InvalidConfigurationReason::UnknownIdentifier, "Unknown tiebreaker 'Wins'"],
        'a number' => [3, InvalidConfigurationReason::WrongValueType, 'named by a string'],
        'null' => [null, InvalidConfigurationReason::WrongValueType, 'named by a string'],
        'a map without an identifier' => [['byes' => 'zero'], InvalidConfigurationReason::WrongValueType, 'named by a string'],
        'a map with a misspelt key' => [['tiebreaker' => 'buchholz', 'bye' => 'zero'], InvalidConfigurationReason::UnknownOptionKey, "'bye'"],
        'a list in place of a map' => [['buchholz'], InvalidConfigurationReason::UnknownOptionKey, 'Unknown key in tiebreaker'],
        'a treatment that is not one' => [['tiebreaker' => 'buchholz', 'byes' => 'win'], InvalidConfigurationReason::UnknownIdentifier, "Unknown bye treatment 'win'"],
        'a treatment that is no string' => [['tiebreaker' => 'buchholz', 'byes' => 0], InvalidConfigurationReason::WrongValueType, 'must be a string'],
        'a treatment for a tiebreaker that reads no bye' => [['tiebreaker' => 'wins', 'byes' => 'zero'], InvalidConfigurationReason::IncompatibleOptions, 'takes no bye treatment'],
        'a treatment between the tied, where there is no bye' => [
            ['tiebreaker' => 'between-tied:buchholz', 'byes' => 'zero'],
            InvalidConfigurationReason::IncompatibleOptions,
            'takes no bye treatment',
        ],
        'a name that is in the chain already' => ['wins', InvalidConfigurationReason::DuplicateName, "'wins' is in the chain twice"],
        'a map of a later version of its form' => [
            ['tiebreaker' => 'wins', 'format_version' => 2],
            InvalidConfigurationReason::UnsupportedFormatVersion,
            'Unsupported format version 2 in tiebreaker',
        ],
    ]);

    it('lists the known identifiers with an unknown one', function (): void {
        expect(chainRefusal(fn() => TiebreakerChain::fromIdentifiers(['goal-difference']))->getContext())
            ->toBe(['tiebreaker' => 'goal-difference', 'known' => TiebreakerChain::identifiers()]);
    });

    it('writes a chain as the plain data that builds it', function (): void {
        $plain = [
            'between-tied:ranking-value',
            'between-tied:score-difference',
            ['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value'],
            'sonneborn-berger',
            'score-difference',
            'scores-for',
            'wins',
            'ranking-value',
        ];

        expect(TiebreakerChain::identifiersOf(TiebreakerChain::fromIdentifiers($plain)))->toBe($plain)
            // An option at its default is not written
            ->and(TiebreakerChain::identifiersOf([new BuchholzTiebreaker(ByeTreatment::Uncounted)]))->toBe(['buchholz'])
            ->and(TiebreakerChain::identifiersOf([]))->toBe([]);
    });

    it('refuses to write a tiebreaker that has no identifier', function (TiebreakerInterface $tiebreaker): void {
        expect(chainRefusal(fn() => TiebreakerChain::identifiersOf([new WinsTiebreaker(), $tiebreaker]))->getReason())
            ->toBe(InvalidConfigurationReason::NotSerializable);
    })->with([
        'a tiebreaker of the application' => [new class implements TiebreakerInterface {
            #[Override]
            public function getName(): string
            {
                return 'wins';
            }

            /**
             * @param array<string, StandingEntry> $entries
             */
            #[Override]
            public function calculate(Participant $participant, array $results, array $entries): float
            {
                return 0.0;
            }
        }],
        'a subclass of one of the library' => [new readonly class extends BuchholzTiebreaker {}],
        'a measure of the application between the tied' => [new BetweenTiedParticipants(new readonly class extends BuchholzTiebreaker {})],
        'a measure with an option between the tied' => [new BetweenTiedParticipants(new BuchholzTiebreaker(ByeTreatment::Zero))],
    ]);
});

describe('a standings calculator built from plain data', function (): void {
    it('is the calculator built without arguments when nothing is given', function (): void {
        expect(StandingsCalculator::fromArray([]))->toEqual(new StandingsCalculator())
            ->and(StandingsCalculator::fromArray(['ranking' => null, 'tiebreakers' => null, 'awarded_results' => null]))
            ->toEqual(new StandingsCalculator());
    });

    it('reads a ranking with its value of a bye, a chain and an awarded result policy', function (): void {
        $calculator = StandingsCalculator::fromArray([
            'ranking' => ['win' => 2, 'draw' => 1, 'loss' => 0, 'bye' => 'draw'],
            'tiebreakers' => ['between-tied:ranking-value', ['tiebreaker' => 'buchholz', 'byes' => 'zero'], 'wins'],
            'awarded_results' => ['count_scores' => false],
        ]);

        expect($calculator)->toEqual(new StandingsCalculator(
            new WinDrawLossRanking(2.0, 1.0, 0.0, 1.0),
            [new BetweenTiedParticipants(new RankingValueTiebreaker()), new BuchholzTiebreaker(ByeTreatment::Zero), new WinsTiebreaker()],
            new AwardedResultPolicy(countScores: false)
        ));
    });

    it('writes the three parts under one version key and reads them back', function (): void {
        $calculator = new StandingsCalculator(
            WinDrawLossRanking::oneHalfZero()->withByeValue(0.5),
            [new SonnebornBergerTiebreaker(ByeTreatment::OwnRankingValue), new ScoreDifferenceTiebreaker()],
            new AwardedResultPolicy(countBetweenTiedParticipants: false)
        );

        expect($calculator->toArray())->toBe([
            'format_version' => 1,
            'ranking' => ['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0, 'bye' => 0.5],
            'tiebreakers' => [['tiebreaker' => 'sonneborn-berger', 'byes' => 'own-ranking-value'], 'score-difference'],
            'awarded_results' => ['count_scores' => true, 'count_between_tied_participants' => false],
        ])->and(StandingsCalculator::fromArray($calculator->toArray()))->toEqual($calculator)
            ->and((new StandingsCalculator())->toArray())->toBe([
                'format_version' => 1,
                'ranking' => ['win' => 3.0, 'draw' => 1.0, 'loss' => 0.0],
                'tiebreakers' => [],
                'awarded_results' => ['count_scores' => true, 'count_between_tied_participants' => true],
            ]);
    });

    it('refuses a part that is no array, and what a part refuses', function (array $config, InvalidConfigurationReason $reason): void {
        expect(chainRefusal(fn() => StandingsCalculator::fromArray($config))->getReason())->toBe($reason);
    })->with([
        'a ranking that is a string' => [['ranking' => 'three-one-zero'], InvalidConfigurationReason::WrongValueType],
        'a chain that is one identifier' => [['tiebreakers' => 'wins'], InvalidConfigurationReason::WrongValueType],
        'a policy that is a boolean' => [['awarded_results' => false], InvalidConfigurationReason::WrongValueType],
        'a ranking with a key it does not have' => [['ranking' => ['won' => 3]], InvalidConfigurationReason::UnknownOptionKey],
        'a chain with an unknown identifier' => [['tiebreakers' => ['goals']], InvalidConfigurationReason::UnknownIdentifier],
        'a policy with a value that is no boolean' => [['awarded_results' => ['count_scores' => 1]], InvalidConfigurationReason::WrongValueType],
        'a key the form does not have' => [['tiebreaker' => ['wins']], InvalidConfigurationReason::UnknownOptionKey],
    ]);

    it('refuses to write a calculator that plain data cannot rebuild', function (StandingsCalculator $calculator): void {
        expect(chainRefusal(fn() => $calculator->toArray())->getReason())->toBe(InvalidConfigurationReason::NotSerializable);
    })->with([
        'a ranking strategy of the application' => [new StandingsCalculator(new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return 0.0;
            }
        })],
        'a subclass' => [new readonly class extends StandingsCalculator {}],
        'a tiebreaker of the application' => [new StandingsCalculator(tiebreakers: [new readonly class extends WinsTiebreaker {}])],
    ]);
});
