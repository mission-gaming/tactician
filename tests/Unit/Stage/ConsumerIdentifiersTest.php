<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\MetadataKey;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\Algorithm;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\FormatMetadataKey;
use MissionGaming\Tactician\Stage\MatchOutcomeMode;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeMode;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\SelectionTiePolicy;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;

/**
 * @return list<Participant>
 */
function identifierField(int $size): array
{
    $field = [];
    for ($i = 1; $i <= $size; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}");
    }

    return $field;
}

/**
 * A round robin of four under a constraint no balanced leg keeps (one
 * participant is never first-named), which the default role assignment
 * completes with the generator's roles and marks.
 *
 * @throws IncompleteScheduleException
 * @throws InvalidConfigurationException
 */
function identifierFallenBackSchedule(): Schedule
{
    foreach (identifierField(4) as $participant) {
        $id = $participant->getId();
        $never = ConstraintSet::create()->custom(
            fn(Event $event): bool => $event->getParticipants()[0]->getId() !== $id,
            "{$id} is second-named"
        )->build();

        try {
            $schedule = (new RoundRobinScheduler($never))->schedule(identifierField(4));
        } catch (IncompleteScheduleException) {
            continue;
        }

        if (RoleFallback::of($schedule) !== null) {
            return $schedule;
        }
    }

    throw new LogicException('No participant of four gives a schedule that falls back');
}

/**
 * Every event of a stage an engine plays to the end, the participant
 * listed earlier in the field winning each one (both legs of a tie).
 *
 * @return list<Event>
 * @throws InvalidConfigurationException
 * @throws NoValidPairingException
 */
function identifierPlayedEvents(StageEngineInterface $engine, int $size): array
{
    $state = StageState::start(identifierField($size));
    $events = [];
    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            function (Event $event): Result {
                [$first, $second] = $event->getParticipants();

                return new Result($event, (int) substr($first->getId(), 1) < (int) substr($second->getId(), 1) ? $first : $second);
            },
            $pairing->getEvents()
        ));
        array_push($events, ...$pairing->getEvents());
    }

    return $events;
}

describe('Algorithm', function (): void {
    it('has a case for the identifier of every plan of the library', function (Closure $plan, Algorithm $algorithm): void {
        expect(Algorithm::from($plan()->getAlgorithm()))->toBe($algorithm);
    })->with([
        'round robin' => [fn() => (new RoundRobinScheduler())->getPlan(identifierField(4)), Algorithm::RoundRobin],
        'Swiss, whole schedule' => [fn() => (new SwissScheduler())->getPlan(identifierField(4)), Algorithm::Swiss],
        'Swiss engine' => [fn() => (new SwissPairingEngine())->getPlan(StageState::start(identifierField(4))), Algorithm::Swiss],
        'pot draw' => [
            fn() => (new PotDrawScheduler())->getPlan(identifierField(8), new PotDrawOptions(pots: 2)),
            Algorithm::PotDraw,
        ],
        'single elimination' => [
            fn() => (new SingleEliminationEngine())->getPlan(StageState::start(identifierField(4))),
            Algorithm::SingleElimination,
        ],
        'double elimination' => [
            fn() => (new DoubleEliminationEngine())->getPlan(StageState::start(identifierField(4))),
            Algorithm::DoubleElimination,
        ],
    ]);

    it('is the value a generated schedule records under its algorithm key', function (Closure $schedule, Algorithm $algorithm): void {
        expect($schedule()->getMetadataValue(MetadataKey::ALGORITHM))->toBe($algorithm->value);
    })->with([
        'round robin' => [fn() => (new RoundRobinScheduler())->schedule(identifierField(4)), Algorithm::RoundRobin],
        'Swiss' => [fn() => (new SwissScheduler())->schedule(identifierField(4), new SwissOptions(rounds: 2, seed: 7)), Algorithm::Swiss],
        'pot draw' => [
            fn() => (new PotDrawScheduler())->schedule(identifierField(8), new PotDrawOptions(pots: 2)),
            Algorithm::PotDraw,
        ],
    ]);
});

describe('Selector modes and tie policies', function (): void {
    it('state the mode a selector was built with, which is the identifier of its plain data', function (): void {
        foreach ([MatchOutcomeSelector::winners(), MatchOutcomeSelector::losers()] as $selector) {
            expect($selector->toArray()['mode'])->toBe($selector->getMode()->value)
                ->and(MatchOutcomeSelector::fromArray(['mode' => $selector->getMode()->value])->getMode())
                ->toBe($selector->getMode());
        }

        expect(MatchOutcomeSelector::winners()->getMode())->toBe(MatchOutcomeMode::Winners)
            ->and(MatchOutcomeSelector::losers()->getMode())->toBe(MatchOutcomeMode::Losers)
            ->and(RankRangeSelector::overall(1, 4)->getMode())->toBe(RankRangeMode::Overall)
            ->and(RankRangeSelector::perGroup(1, 2)->getMode())->toBe(RankRangeMode::PerGroup)
            ->and(RankRangeSelector::topPerGroup(2)->getMode())->toBe(RankRangeMode::PerGroup)
            ->and(RankRangeSelector::overall(1, 4)->toArray()['mode'])->toBe(RankRangeMode::Overall->value)
            ->and(RankRangeSelector::topPerGroup(2)->toArray()['mode'])->toBe(RankRangeMode::PerGroup->value);
    });

    it('state the tie policy of a rank selector, which is the identifier of its plain data', function (
        RankRangeSelector|BestOfRankSelector $selector,
        SelectionTiePolicy $policy
    ): void {
        expect($selector->getTiePolicy())->toBe($policy)
            ->and($selector->toArray()['ties'] ?? SelectionTiePolicy::Refused->value)->toBe($policy->value);
    })->with([
        'rank range, default' => [RankRangeSelector::overall(1, 4), SelectionTiePolicy::Refused],
        'rank range, inside' => [RankRangeSelector::overall(1, 4)->withTiesInsideSelectionAccepted(), SelectionTiePolicy::InsideSelectionAccepted],
        'rank range, table order' => [RankRangeSelector::overall(1, 4)->withTableOrderAccepted(), SelectionTiePolicy::TableOrderAccepted],
        'best of rank, default' => [BestOfRankSelector::ofRank(3, 2), SelectionTiePolicy::Refused],
        'best of rank, inside' => [BestOfRankSelector::ofRank(3, 2)->withTiesInsideSelectionAccepted(), SelectionTiePolicy::InsideSelectionAccepted],
        'best of rank, table order' => [BestOfRankSelector::ofRank(3, 2)->withTableOrderAccepted(), SelectionTiePolicy::TableOrderAccepted],
    ]);

    it('read stored plain data by the enum values', function (): void {
        foreach (SelectionTiePolicy::cases() as $policy) {
            expect(RankRangeSelector::fromArray(['mode' => RankRangeMode::Overall->value, 'to' => 2, 'ties' => $policy->value])->getTiePolicy())
                ->toBe($policy)
                ->and(BestOfRankSelector::fromArray(['rank' => 2, 'count' => 1, 'ties' => $policy->value])->getTiePolicy())
                ->toBe($policy);
        }
    });
});

describe('MetadataKey', function (): void {
    it('names exactly the keys the round-robin scheduler writes, the keys the stable surface owns', function (): void {
        $written = [];
        foreach ([
            (new RoundRobinScheduler())->schedule(identifierField(5), new RoundRobinOptions(legs: 2)),
            (new RoundRobinScheduler())->schedule(identifierField(6)),
            identifierFallenBackSchedule(),
        ] as $schedule) {
            array_push($written, ...array_keys($schedule->getMetadata()));
        }
        $written = array_values(array_unique($written));
        $named = array_values((new ReflectionClass(MetadataKey::class))->getConstants());
        sort($written);
        sort($named);

        expect($named)->toBe($written);
    });

    it('leaves the keys of the experimental formats to FormatMetadataKey, and names none twice', function (): void {
        $stable = (new ReflectionClass(MetadataKey::class))->getConstants();
        $format = (new ReflectionClass(FormatMetadataKey::class))->getConstants();

        expect(array_intersect($stable, $format))->toBe([])
            ->and(array_intersect_key($stable, $format))->toBe([])
            ->and(array_unique($format))->toBe($format);
    });

    it('names every key a whole-schedule generator writes', function (Closure $schedule): void {
        $keys = [
            ...array_values((new ReflectionClass(MetadataKey::class))->getConstants()),
            ...array_values((new ReflectionClass(FormatMetadataKey::class))->getConstants()),
        ];
        /** @var Schedule $generated */
        $generated = $schedule();

        expect(array_diff(array_keys($generated->getMetadata()), $keys))->toBe([]);
    })->with([
        'round robin, odd field' => [fn() => (new RoundRobinScheduler())->schedule(identifierField(5), new RoundRobinOptions(legs: 2))],
        'round robin under a constraint' => [fn() => (new RoundRobinScheduler(ConstraintSet::create()->add(new NoRepeatPairings())->build()))->schedule(identifierField(6))],
        'round robin that fell back' => [identifierFallenBackSchedule(...)],
        'Swiss' => [fn() => (new SwissScheduler())->schedule(identifierField(5), new SwissOptions(rounds: 3, seed: 7))],
        'pot draw' => [fn() => (new PotDrawScheduler())->schedule(identifierField(8), new PotDrawOptions(pots: 2))],
    ]);

    it('names every key an elimination engine writes on a round or an event', function (StageEngineInterface $engine, int $size): void {
        $roundKeys = [];
        $eventKeys = [];
        foreach (identifierPlayedEvents($engine, $size) as $event) {
            array_push($eventKeys, ...array_keys($event->getMetadata()));
            array_push($roundKeys, ...array_keys($event->getRound()?->getMetadata() ?? []));
        }

        expect(array_values(array_unique($roundKeys)))->toBe([FormatMetadataKey::ROUND_LABEL])
            ->and(array_diff($eventKeys, [FormatMetadataKey::TIE_LEG, FormatMetadataKey::BRACKET_TIE]))->toBe([]);
    })->with([
        'single elimination, two legs' => [new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)), 6],
        'single elimination, third place' => [new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)), 8],
        'double elimination' => [new DoubleEliminationEngine(), 6],
    ]);

    it('writes the keys the library has always written', function (): void {
        expect(MetadataKey::ROLE_FALLBACK)->toBe(RoleFallback::METADATA_KEY)
            ->and(FormatMetadataKey::BRACKET_TIE)->toBe(BracketTie::METADATA_KEY)
            ->and(FormatMetadataKey::TIE_LEG)->toBe('tie_leg')
            ->and(FormatMetadataKey::ROUND_LABEL)->toBe('label')
            ->and(FormatMetadataKey::ROUNDS)->toBe('rounds')
            ->and(FormatMetadataKey::SEED)->toBe('seed')
            ->and(MetadataKey::ALGORITHM)->toBe('algorithm')
            ->and(MetadataKey::BYES)->toBe('byes');
    });

    it('writes the two legs of a tie and the third-place mark under their keys', function (): void {
        $twoLegs = identifierPlayedEvents(new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)), 4);
        $thirdPlace = identifierPlayedEvents(new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)), 4);

        expect(array_map(fn(Event $event): mixed => $event->getMetadataValue(FormatMetadataKey::TIE_LEG), array_slice($twoLegs, 0, 2)))
            ->toBe([1, 2])
            ->and(array_filter(
                $thirdPlace,
                fn(Event $event): bool => $event->hasMetadata(FormatMetadataKey::BRACKET_TIE)
            ))->not->toBe([]);
    });
});
