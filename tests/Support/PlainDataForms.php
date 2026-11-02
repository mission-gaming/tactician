<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\EventUnplaced;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\RepackObjective;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\SlotAssignment;
use MissionGaming\Tactician\Repack\UnplacedEvent;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\BracketQualifiersSelector;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\PoolComparison;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerChain;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use Random\Randomizer;

/**
 * Every form the library reads from plain data or writes as it: one entry
 * for each `fromArray()` in `src/`, one for each array nested in such a form
 * that has keys of its own (a blackout window), and the forms that are
 * written and have no reader (a repack outcome and its records).
 *
 * An entry holds what a test needs to treat the forms alike: whether the
 * form is configuration or data (which decides the exception it raises),
 * the keys it reads, a sample that holds every one of those keys with a
 * valid value, the call that reads an array of the form, and a generator of
 * instances for the round-trip sweeps. A form that refers to participants
 * by ID is read against a registry: {@see self::registry()} for the
 * samples, the generated field for generated instances.
 *
 * `tests/Feature/PlainDataFormsTest.php` compares the entries with the
 * `fromArray()` and `toArray()` methods `src/` declares, so a new one fails
 * the suite until it is listed here.
 */
final class PlainDataForms
{
    public const string CONFIGURATION = 'configuration';

    public const string DATA = 'data';

    private const array SESSION_GRID_KEYS = [
        'sessions',
        'timezone',
        'slot_interval',
        'session_count',
        'slots_per_session',
        'slots_per_session_overrides',
        'capacity_per_slot',
    ];

    /**
     * The forms that are read.
     *
     * @return array<string, array{
     *     class: class-string,
     *     kind: self::CONFIGURATION|self::DATA,
     *     keys: list<string>,
     *     sample: array<string, mixed>,
     *     read: Closure(array<array-key, mixed>, array<string, Participant>): object,
     *     generate: (Closure(Randomizer, array<Participant>): object)|null,
     * }> Keyed by a name for the form. `generate` is null for a form that is only a part of
     *    another entry's array and has no class of its own
     */
    public static function all(): array
    {
        $event = ['participants' => ['a', 'b'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => ['court' => 2]];
        $participants = [
            ['id' => 'a', 'label' => 'Alice', 'seed' => 1, 'metadata' => []],
            ['id' => 'b', 'label' => 'Bob', 'seed' => 2, 'metadata' => []],
            ['id' => 'c', 'label' => 'Carol', 'seed' => null, 'metadata' => []],
        ];
        $award = ['label' => 'no-show', 'reason' => 'Bob did not arrive'];
        $decider = ['participants' => ['b'], 'label' => 'drawn by lot', 'reason' => 'The event finished level'];
        // The order of a set of level entries, as a rank selector takes it
        $decision = ['participants' => ['c', 'a', 'b'], 'label' => 'drawn by lot', 'reason' => 'Level on every figure'];
        $adjustment = ['participant' => 'b', 'amount' => -3.0, 'label' => 'deduction', 'reason' => 'a late entry', 'after_round' => 1];
        $tiebreaker = ['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value'];
        $window = ['from' => '2026-11-09 00:00', 'to' => '2026-11-17 00:00', 'timezone' => 'Europe/London', 'label' => 'break'];

        return [
            'round-robin options' => [
                'class' => RoundRobinOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['legs', 'strategy', 'backtracking', 'strategy_seed', 'role_assignment'],
                'sample' => [
                    'legs' => 2,
                    'strategy' => 'shuffled',
                    'backtracking' => true,
                    'strategy_seed' => 7,
                    'role_assignment' => 'balanced',
                ],
                'read' => static fn(array $data, array $registry): object => RoundRobinOptions::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => new RoundRobinOptions(
                    $random->getInt(1, 4),
                    match ($random->getInt(0, 2)) {
                        0 => null,
                        1 => new RepeatedLegStrategy(),
                        default => new ShuffledLegStrategy(seed: $random->getInt(-9, 9_999)),
                    },
                    $random->getInt(0, 1) === 1,
                    match ($random->getInt(0, 3)) {
                        0 => null,
                        1 => new RoundParityRoleAssignment(),
                        2 => new BalancedWherePossibleRoleAssignment(),
                        default => new BalancedRoleAssignment(),
                    }
                ),
            ],
            'Swiss options' => [
                'class' => SwissOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['rounds', 'seed'],
                'sample' => ['rounds' => 5, 'seed' => 2026],
                'read' => static fn(array $data, array $registry): object => SwissOptions::fromArray(self::stringKeyed($data)),
                // Half of them with a seed, which is any integer, 0 included
                'generate' => static fn(Randomizer $random, array $field): object => new SwissOptions(
                    $random->getInt(1, 11),
                    $random->getInt(0, 1) === 1 ? [0, -7, PHP_INT_MAX, PHP_INT_MIN, $random->getInt(1, 9_999)][$random->getInt(0, 4)] : null
                ),
            ],
            'pot draw options' => [
                'class' => PotDrawOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['pots', 'opponents_per_pot', 'seed'],
                'sample' => ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026],
                'read' => static fn(array $data, array $registry): object => PotDrawOptions::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => new PotDrawOptions(
                    $random->getInt(1, 6),
                    $random->getInt(1, 4),
                    $random->getInt(PHP_INT_MIN, PHP_INT_MAX)
                ),
            ],
            'elimination options' => [
                'class' => EliminationOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['legs_per_tie', 'reseed_each_round', 'grand_final_reset', 'third_place'],
                'sample' => ['legs_per_tie' => 2, 'reseed_each_round' => true, 'grand_final_reset' => false, 'third_place' => true],
                'read' => static fn(array $data, array $registry): object => EliminationOptions::fromArray(self::stringKeyed($data)),
                // Half of them with a third-place tie, which writes the key
                // `third_place`; the rest write no such key
                'generate' => static fn(Randomizer $random, array $field): object => new EliminationOptions(
                    $random->getInt(1, 2),
                    $random->getInt(0, 1) === 1,
                    $random->getInt(0, 1) === 1,
                    $random->getInt(0, 1) === 1
                ),
            ],
            'repack options' => [
                'class' => RepackOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['consolidation_weight', 'early_fill_weight', 'step_budget', 'throw_on_violations', 'objectives'],
                'sample' => [
                    'consolidation_weight' => 5,
                    'early_fill_weight' => 2,
                    'step_budget' => 1_000,
                    'throw_on_violations' => true,
                    'objectives' => ['least_churn', 'fewest_idle_sessions'],
                ],
                'read' => static fn(array $data, array $registry): object => RepackOptions::fromArray(self::stringKeyed($data)),
                // Half of them with objectives, some of the three in a
                // random order, which writes the key `objectives`; the
                // rest write no such key
                'generate' => static fn(Randomizer $random, array $field): object => new RepackOptions(
                    $random->getInt(0, 50),
                    $random->getInt(0, 50),
                    $random->getInt(1, 500_000),
                    $random->getInt(0, 1) === 1,
                    $random->getInt(0, 1) === 1
                        ? array_slice($random->shuffleArray(RepackObjective::cases()), 0, $random->getInt(1, 3))
                        : []
                ),
            ],
            'win-draw-loss ranking' => [
                'class' => WinDrawLossRanking::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['win', 'draw', 'loss', 'bye'],
                'sample' => ['win' => 2.0, 'draw' => 0.5, 'loss' => -1.0, 'bye' => 0.25],
                'read' => static fn(array $data, array $registry): object => WinDrawLossRanking::fromArray(self::stringKeyed($data)),
                'generate' => self::ranking(...),
            ],
            'standings calculator' => [
                'class' => StandingsCalculator::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['ranking', 'tiebreakers', 'awarded_results'],
                'sample' => [
                    'ranking' => ['win' => 2.0, 'draw' => 1.0, 'loss' => 0.0, 'bye' => 'draw'],
                    'tiebreakers' => ['between-tied:ranking-value', 'score-difference', $tiebreaker],
                    'awarded_results' => ['count_scores' => false, 'count_between_tied_participants' => true],
                ],
                'read' => static fn(array $data, array $registry): object => StandingsCalculator::fromArray($data),
                'generate' => static function (Randomizer $random, array $field): object {
                    // Any of the identifiers, each once at most, in a drawn
                    // order; the two that read byes with any treatment
                    $chain = [];
                    foreach ($random->shuffleArray(TiebreakerChain::identifiers()) as $identifier) {
                        $leftOut = $random->getInt(0, 2);
                        $drawn = $random->getInt(0, 2);
                        if ($leftOut > 0) {
                            continue;
                        }
                        $treatment = ByeTreatment::cases()[$drawn];
                        $chain[] = in_array($identifier, ['buchholz', 'sonneborn-berger'], true) && $treatment !== ByeTreatment::Uncounted
                            ? ['tiebreaker' => $identifier, 'byes' => $treatment->value]
                            : $identifier;
                    }

                    return new StandingsCalculator(
                        self::ranking($random, $field),
                        TiebreakerChain::fromIdentifiers($chain),
                        new AwardedResultPolicy($random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1)
                    );
                },
            ],
            'tiebreaker with options' => [
                'class' => StandingsCalculator::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['tiebreaker', 'byes'],
                'sample' => $tiebreaker,
                'read' => static fn(array $data, array $registry): object => StandingsCalculator::fromArray(['tiebreakers' => [$data]]),
                'generate' => null,
            ],
            'match outcome selector' => [
                'class' => MatchOutcomeSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['mode'],
                'sample' => ['mode' => 'losers'],
                'read' => static fn(array $data, array $registry): object => MatchOutcomeSelector::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => $random->getInt(0, 1) === 1
                    ? MatchOutcomeSelector::winners()
                    : MatchOutcomeSelector::losers(),
            ],
            'rank range selector' => [
                'class' => RankRangeSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['mode', 'from', 'to', 'ties', 'comparison', 'decisions'],
                // An overall selection: a per-group one takes no comparison
                'sample' => [
                    'mode' => 'overall',
                    'from' => 2,
                    'to' => 3,
                    'ties' => 'accept-inside-selection',
                    'comparison' => 'per-event',
                    'decisions' => [$decision],
                ],
                'read' => static fn(array $data, array $registry): object => RankRangeSelector::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $from = $random->getInt(1, 8);
                    $to = $from + $random->getInt(0, 8);

                    // An overall selection with no comparison method writes
                    // no `comparison` key; one that was given either of the
                    // two it takes writes it
                    $selector = match ($random->getInt(0, 4)) {
                        0 => RankRangeSelector::overall($from, $to),
                        1 => RankRangeSelector::overall($from, $to)->comparedBy(PoolComparison::RawTotals),
                        2 => RankRangeSelector::overall($from, $to)->comparedBy(PoolComparison::PerEvent),
                        3 => RankRangeSelector::perGroup($from, $to),
                        default => RankRangeSelector::topPerGroup($to),
                    };

                    // A selector that refuses an undecided order writes no
                    // `ties` key; the other two write theirs
                    $selector = match ($random->getInt(0, 2)) {
                        0 => $selector,
                        1 => $selector->withTiesInsideSelectionAccepted(),
                        default => $selector->withTableOrderAccepted(),
                    };

                    // One in two was given decisions, which writes the key
                    // `decisions`; the rest write no such key
                    return $selector->withDecisions(self::decisions($random, array_values($field)));
                },
            ],
            'best-of-rank selector' => [
                'class' => BestOfRankSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['rank', 'count', 'comparison', 'ties', 'decisions'],
                'sample' => ['rank' => 3, 'count' => 2, 'comparison' => 'discard-lowest', 'ties' => 'accept-table-order', 'decisions' => [$decision]],
                'read' => static fn(array $data, array $registry): object => BestOfRankSelector::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $selector = BestOfRankSelector::ofRank($random->getInt(1, 8), $random->getInt(1, 8));

                    // No method chosen writes no `comparison` key
                    $compared = $random->getInt(0, 3);
                    if ($compared > 0) {
                        $selector = $selector->comparedBy(PoolComparison::cases()[$compared - 1]);
                    }

                    $selector = match ($random->getInt(0, 2)) {
                        0 => $selector,
                        1 => $selector->withTiesInsideSelectionAccepted(),
                        default => $selector->withTableOrderAccepted(),
                    };

                    return $selector->withDecisions(self::decisions($random, array_values($field)));
                },
            ],
            'bracket qualifiers selector' => [
                'class' => BracketQualifiersSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['per_group', 'best_of_rank'],
                // Each selection in its own form, without a format version
                'sample' => [
                    'per_group' => ['mode' => 'per-group', 'from' => 1, 'to' => 2, 'ties' => 'accept-inside-selection'],
                    'best_of_rank' => ['rank' => 3, 'count' => 2, 'comparison' => 'per-event', 'decisions' => [$decision]],
                ],
                'read' => static fn(array $data, array $registry): object => BracketQualifiersSelector::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $from = $random->getInt(1, 4);
                    $to = $from + $random->getInt(0, 3);
                    $perGroup = $from === 1 && $random->getInt(0, 1) === 1
                        ? RankRangeSelector::topPerGroup($to)
                        : RankRangeSelector::perGroup($from, $to);
                    $bestOfRank = BestOfRankSelector::ofRank($to + $random->getInt(1, 2), $random->getInt(1, 8));

                    // One in two of each was given a tie policy, a method or
                    // decisions, which their forms write
                    if ($random->getInt(0, 1) === 1) {
                        $perGroup = $perGroup->withTableOrderAccepted()->withDecisions(self::decisions($random, array_values($field)));
                    }
                    if ($random->getInt(0, 1) === 1) {
                        $bestOfRank = $bestOfRank->comparedBy(PoolComparison::DiscardLowest)->withTiesInsideSelectionAccepted();
                    }

                    return BracketQualifiersSelector::of($perGroup, $bestOfRank);
                },
            ],
            'minimum rest rule' => [
                'class' => MinimumRestRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['rest'],
                'sample' => ['rest' => 'PT48H'],
                'read' => static fn(array $data, array $registry): object => MinimumRestRule::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => new MinimumRestRule(
                    new DateInterval(['PT48H', 'P1D', 'PT90M', 'P2DT12H', 'P1M', 'PT45S'][$random->getInt(0, 5)])
                ),
            ],
            'blackout rule' => [
                'class' => BlackoutRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['windows'],
                'sample' => ['windows' => [$window]],
                'read' => static fn(array $data, array $registry): object => BlackoutRule::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $windows = [];
                    for ($i = $random->getInt(1, 3); $i > 0; --$i) {
                        $from = self::instant($random);
                        $window = [
                            'from' => self::sometimesWithFraction($random, $from),
                            'to' => $from->setTimestamp($from->getTimestamp() + 3_600 * $random->getInt(1, 400)),
                        ];
                        if ($random->getInt(0, 1) === 1) {
                            $window['label'] = 'closure ' . $random->getInt(1, 99);
                        }
                        $windows[] = $window;
                    }

                    return new BlackoutRule($windows);
                },
            ],
            'blackout window' => [
                'class' => BlackoutRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['from', 'to', 'timezone', 'label'],
                'sample' => $window,
                'read' => static fn(array $data, array $registry): object => BlackoutRule::fromArray(['windows' => [$data]]),
                'generate' => null,
            ],
            'timeline definition' => [
                'class' => TimelineDefinition::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['start', 'timezone', 'round_interval', 'slots_per_round', 'slot_interval', 'resources'],
                'sample' => [
                    'start' => '2026-08-01 19:00:00',
                    'timezone' => 'Europe/London',
                    'round_interval' => 'P7D',
                    'slots_per_round' => 2,
                    'slot_interval' => 'PT1H',
                    'resources' => ['Court 1', 'Court 2'],
                ],
                'read' => static fn(array $data, array $registry): object => TimelineDefinition::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $slots = $random->getInt(1, 3);

                    // Three slots at most, 90 minutes apart at most: a round ends
                    // well inside the day the shortest round interval gives it
                    return new TimelineDefinition(
                        self::sometimesWithFraction($random, self::localEvening($random)),
                        new DateInterval(['P7D', 'P1D', 'P14D', 'PT48H', 'P1M'][$random->getInt(0, 4)]),
                        $slots,
                        $slots > 1 || $random->getInt(0, 1) === 1
                            ? new DateInterval(['PT1H', 'PT90M', 'PT25M'][$random->getInt(0, 2)])
                            : null,
                        array_slice(['Court 1', 'Court 2', 'Hall'], 0, $random->getInt(0, 3))
                    );
                },
            ],
            'session grid with instants' => [
                'class' => SessionGrid::class,
                'kind' => self::CONFIGURATION,
                'keys' => self::SESSION_GRID_KEYS,
                'sample' => [
                    'sessions' => ['2026-08-12 20:15:00', '2026-08-19 20:15:00'],
                    'timezone' => 'Europe/London',
                    'slot_interval' => 'PT25M',
                    'slots_per_session' => 4,
                    'capacity_per_slot' => 7,
                    'slots_per_session_overrides' => [1 => 6],
                ],
                'read' => static fn(array $data, array $registry): object => SessionGrid::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $first = self::sometimesWithFraction($random, self::localEvening($random));
                    $sessions = [];
                    for ($i = 0, $count = $random->getInt(1, 5); $i < $count; ++$i) {
                        $sessions[] = $first->add(new DateInterval('P' . ($i * 7) . 'D'));
                    }
                    $slots = $random->getInt(1, 5);

                    return new SessionGrid(
                        $sessions,
                        new DateInterval(['PT25M', 'PT30M', 'PT20M'][$random->getInt(0, 2)]),
                        $slots,
                        $random->getInt(0, 1) === 1 ? [$random->getInt(0, count($sessions) - 1) => $slots + 1] : [],
                        $random->getInt(0, 3) === 0 ? null : $random->getInt(1, 8)
                    );
                },
            ],
            'session grid of a shape only' => [
                'class' => SessionGrid::class,
                'kind' => self::CONFIGURATION,
                'keys' => self::SESSION_GRID_KEYS,
                'sample' => [
                    'session_count' => 2,
                    'slots_per_session' => 4,
                    'capacity_per_slot' => 'unbounded',
                    'slots_per_session_overrides' => [1 => 6],
                ],
                'read' => static fn(array $data, array $registry): object => SessionGrid::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $sessions = $random->getInt(1, 6);
                    $slots = $random->getInt(1, 5);

                    return SessionGrid::shapeOnly(
                        $sessions,
                        $slots,
                        $random->getInt(0, 1) === 1 ? [$random->getInt(0, $sessions - 1) => $slots + 2] : [],
                        $random->getInt(0, 3) === 0 ? null : $random->getInt(1, 8)
                    );
                },
            ],
            'participant' => [
                'class' => Participant::class,
                'kind' => self::DATA,
                'keys' => ['id', 'label', 'seed', 'metadata'],
                'sample' => ['id' => 'a', 'label' => 'Alice', 'seed' => 1, 'metadata' => ['club' => 'North']],
                'read' => static fn(array $data, array $registry): object => Participant::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => new Participant(
                    $field[$random->getInt(0, count($field) - 1)]->getId(),
                    'Label ' . $random->getInt(0, 999),
                    $random->getInt(0, 2) === 0 ? null : $random->getInt(-3, 64),
                    self::metadata($random)
                ),
            ],
            'round' => [
                'class' => Round::class,
                'kind' => self::DATA,
                'keys' => ['number', 'metadata'],
                'sample' => ['number' => 3, 'metadata' => ['label' => 'semifinal']],
                'read' => static fn(array $data, array $registry): object => Round::fromArray(self::stringKeyed($data)),
                'generate' => static fn(Randomizer $random, array $field): object => new Round($random->getInt(1, 60), self::metadata($random)),
            ],
            'event' => [
                'class' => Event::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'round', 'metadata'],
                'sample' => $event,
                'read' => static fn(array $data, array $registry): object => Event::fromArray(self::stringKeyed($data), $registry),
                'generate' => self::event(...),
            ],
            'result' => [
                'class' => Result::class,
                'kind' => self::DATA,
                'keys' => ['event', 'winner', 'scores', 'metadata', 'award', 'decider'],
                'sample' => [
                    'event' => $event,
                    'winner' => 'a',
                    'scores' => ['a' => 2, 'b' => 1],
                    'metadata' => ['note' => 'x'],
                    'award' => $award,
                    'decider' => $decider,
                ],
                'read' => static fn(array $data, array $registry): object => Result::fromArray(self::stringKeyed($data), $registry),
                'generate' => static fn(Randomizer $random, array $field): object => self::result($random, self::event($random, $field)),
            ],
            'award' => [
                'class' => Award::class,
                'kind' => self::DATA,
                'keys' => ['label', 'reason'],
                'sample' => $award,
                'read' => static fn(array $data, array $registry): object => Award::fromArray($data),
                'generate' => static fn(Randomizer $random, array $field): object => self::award($random),
            ],
            'decider' => [
                'class' => Decider::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'label', 'reason'],
                'sample' => $decider,
                'read' => static fn(array $data, array $registry): object => Decider::fromArray($data),
                'generate' => static fn(Randomizer $random, array $field): object => self::decider($random, array_values($field)),
            ],
            'adjustment' => [
                'class' => Adjustment::class,
                'kind' => self::DATA,
                'keys' => ['participant', 'amount', 'label', 'reason', 'after_round'],
                'sample' => $adjustment,
                'read' => static fn(array $data, array $registry): object => Adjustment::fromArray($data),
                'generate' => self::adjustment(...),
            ],
            'awarded result policy' => [
                'class' => AwardedResultPolicy::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['count_scores', 'count_between_tied_participants'],
                'sample' => ['count_scores' => false, 'count_between_tied_participants' => false],
                'read' => static fn(array $data, array $registry): object => AwardedResultPolicy::fromArray($data),
                'generate' => static fn(Randomizer $random, array $field): object => new AwardedResultPolicy(
                    $random->getInt(0, 1) === 1,
                    $random->getInt(0, 1) === 1
                ),
            ],
            'round pairing' => [
                'class' => RoundPairing::class,
                'kind' => self::DATA,
                'keys' => ['round', 'label', 'events', 'byes', 'paired_on_incomplete_results', 'unresolved_when_paired'],
                'sample' => [
                    'round' => 2,
                    'label' => 'semifinal',
                    'events' => [['participants' => ['a', 'b'], 'round' => ['number' => 2, 'metadata' => []], 'metadata' => ['court' => 2]]],
                    'byes' => ['c'],
                    'paired_on_incomplete_results' => true,
                    'unresolved_when_paired' => [$event],
                ],
                'read' => static fn(array $data, array $registry): object => RoundPairing::fromArray(self::stringKeyed($data), $registry),
                'generate' => static function (Randomizer $random, array $field): object {
                    $number = $random->getInt(1, 30);

                    return self::pairing($random, $field, $number, self::earlierEvents($random, $field, $number));
                },
            ],
            'schedule' => [
                'class' => Schedule::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'events', 'metadata'],
                'sample' => ['participants' => $participants, 'events' => [$event], 'metadata' => ['algorithm' => 'by hand']],
                'read' => static fn(array $data, array $registry): object => Schedule::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $events = [];
                    for ($i = $random->getInt(0, 12); $i > 0; --$i) {
                        $events[] = self::event($random, $field);
                    }

                    // Half of them state their entrants, in an order of their own
                    // and with those who have no event; the rest leave the list
                    // to be read from the events
                    return new Schedule(
                        $events,
                        self::metadata($random),
                        $random->getInt(0, 1) === 1 ? $random->shuffleArray(array_values($field)) : null
                    );
                },
            ],
            'stage state' => [
                'class' => StageState::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'active', 'rounds', 'results', 'engine_fingerprint', 'adjustments', 'entrants', 'withdrawals'],
                'sample' => [
                    'participants' => $participants,
                    // b has withdrawn from the live stage and keeps its place
                    'active' => ['a', 'c'],
                    'rounds' => [['round' => 1, 'label' => null, 'events' => [$event], 'byes' => ['c']]],
                    'results' => [['event' => $event, 'winner' => 'a', 'scores' => [], 'metadata' => []]],
                    'engine_fingerprint' => 'an engine of our own',
                    'adjustments' => [$adjustment],
                    'entrants' => ['a', 'b', 'c'],
                    'withdrawals' => [['participant' => 'b', 'after_round' => 1]],
                ],
                'read' => static fn(array $data, array $registry): object => StageState::fromArray(self::stringKeyed($data)),
                'generate' => self::stageState(...),
            ],
            'scheduled event' => [
                'class' => ScheduledEvent::class,
                'kind' => self::DATA,
                'keys' => ['event', 'kickoff', 'resource'],
                'sample' => ['event' => $event, 'kickoff' => '2026-08-01T18:00:00Z', 'resource' => 'Court 1'],
                'read' => static fn(array $data, array $registry): object => ScheduledEvent::fromArray(self::stringKeyed($data), $registry),
                'generate' => self::scheduledEvent(...),
            ],
            'scheduled schedule' => [
                'class' => ScheduledSchedule::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'events', 'entrants'],
                'sample' => [
                    'participants' => $participants,
                    'events' => [['event' => $event, 'kickoff' => '2026-08-01T18:00:00Z', 'resource' => null]],
                    'entrants' => ['c', 'a', 'b'],
                ],
                'read' => static fn(array $data, array $registry): object => ScheduledSchedule::fromArray(self::stringKeyed($data)),
                'generate' => static function (Randomizer $random, array $field): object {
                    $events = [];
                    for ($i = $random->getInt(0, 10); $i > 0; --$i) {
                        $events[] = self::scheduledEvent($random, $field);
                    }

                    return new ScheduledSchedule($events);
                },
            ],
        ];
    }

    /**
     * The classes of the forms 0.3.0 added. No release before it wrote one,
     * so the values stored by 0.2.x hold none of them.
     *
     * @return list<class-string>
     */
    public static function addedInZeroThree(): array
    {
        return [Award::class, Decider::class, Adjustment::class, AwardedResultPolicy::class, BestOfRankSelector::class, BracketQualifiersSelector::class, StandingsCalculator::class];
    }

    /**
     * The forms that are written and never read: a repack outcome and the
     * records it holds.
     *
     * @return array<string, array{class: class-string, generate: Closure(Randomizer, array<Participant>): object}>
     */
    public static function writtenOnly(): array
    {
        $one = static fn(Randomizer $random, array $field): Participant => $field[$random->getInt(0, count($field) - 1)];
        $reason = static fn(Randomizer $random): UnplacedReason => UnplacedReason::cases()[$random->getInt(0, 1)];

        $assignment = static fn(Randomizer $random, array $field): object => new SlotAssignment(
            'e' . $random->getInt(1, 9_999),
            $random->getInt(0, 9),
            $random->getInt(0, 9),
            $random->getInt(0, 3) === 0 ? null : self::instant($random)
        );
        $unplaced = static fn(Randomizer $random, array $field): object => new UnplacedEvent(
            'e' . $random->getInt(1, 9_999),
            $reason($random),
            $random->getInt(0, 1) === 1 ? $one($random, $field) : null
        );
        $violations = [
            'participant double booked' => [ParticipantDoubleBooked::class, static fn(Randomizer $random, array $field): object => new ParticipantDoubleBooked(
                $one($random, $field),
                $random->getInt(0, 9),
                $random->getInt(0, 9),
                ['e' . $random->getInt(1, 99), 'e' . $random->getInt(100, 199)]
            )],
            'contiguity broken' => [ContiguityBroken::class, static fn(Randomizer $random, array $field): object => new ContiguityBroken(
                $one($random, $field),
                $random->getInt(0, 9),
                $random->getInt(1, 4),
                [0, $random->getInt(2, 6)]
            )],
            'event unplaced' => [EventUnplaced::class, static fn(Randomizer $random, array $field): object => new EventUnplaced(
                'e' . $random->getInt(1, 9_999),
                $reason($random),
                $random->getInt(0, 1) === 1 ? $one($random, $field) : null
            )],
            'late start' => [LateStart::class, static fn(Randomizer $random, array $field): object => new LateStart(
                $one($random, $field),
                $random->getInt(0, 9),
                $random->getInt(1, 9)
            )],
            'capacity exceeded' => [CapacityExceeded::class, static fn(Randomizer $random, array $field): object => new CapacityExceeded(
                $random->getInt(0, 1) === 1 ? $one($random, $field) : null,
                $random->getInt(5, 9),
                $random->getInt(0, 4)
            )],
        ];

        $forms = [
            'slot assignment' => ['class' => SlotAssignment::class, 'generate' => $assignment],
            'unplaced event' => ['class' => UnplacedEvent::class, 'generate' => $unplaced],
        ];
        foreach ($violations as $name => [$class, $generate]) {
            $forms[$name] = ['class' => $class, 'generate' => $generate];
        }

        $forms['repack outcome'] = [
            'class' => RepackOutcome::class,
            'generate' => static function (Randomizer $random, array $field) use ($assignment, $unplaced, $violations): object {
                $assignments = [];
                for ($i = $random->getInt(0, 6); $i > 0; --$i) {
                    $made = $assignment($random, $field);
                    $assignments[$made->getEventId()] = $made;
                }
                $left = [];
                for ($i = $random->getInt(0, 3); $i > 0; --$i) {
                    $left[] = $unplaced($random, $field);
                }
                $found = [];
                foreach ($violations as [, $generate]) {
                    if ($random->getInt(0, 1) === 1) {
                        $found[] = $generate($random, $field);
                    }
                }

                // The figures, of which half the outcomes state some
                $stated = $random->getInt(0, 1) === 1;

                return new RepackOutcome(
                    array_values($assignments),
                    $left,
                    $found,
                    $random->getInt(0, 1) === 1,
                    $stated ? [$random->getInt(0, 4), $random->getInt(5, 9)] : [],
                    $stated ? $random->getInt(0, 12) : 0,
                    $stated ? ['e' . $random->getInt(1, 9_999)] : []
                );
            },
        ];

        return $forms;
    }

    /**
     * Alice (`a`), Bob (`b`) and Carol (`c`), keyed by ID: the participants
     * the samples refer to.
     *
     * @return array<string, Participant>
     */
    public static function registry(): array
    {
        return [
            'a' => new Participant('a', 'Alice', 1),
            'b' => new Participant('b', 'Bob', 2),
            'c' => new Participant('c', 'Carol'),
        ];
    }

    /**
     * Participants keyed by ID, as the readers take a registry.
     *
     * @param array<Participant> $field
     *
     * @return array<string, Participant>
     */
    public static function registryOf(array $field): array
    {
        $registry = [];
        foreach ($field as $participant) {
            $registry[$participant->getId()] = $participant;
        }

        /** @var array<string, Participant> $registry A numeric ID is an integer key, which is what a registry built in an application holds too */
        return $registry;
    }

    /**
     * Ways to mistype a key that leave one wrong key: a character left out,
     * two neighbours swapped, a character doubled, the first letter in
     * upper case, a plural, a space in front, a hyphen for an underscore,
     * and the camel-case spelling of the constructor. A spelling that is
     * the key itself, or another key the form knows, is left out.
     *
     * @param list<string> $known Every key the form reads
     *
     * @return list<string>
     */
    public static function typosOf(string $key, array $known): array
    {
        $typos = [];
        $length = strlen($key);

        for ($i = 0; $i < $length; ++$i) {
            $typos[] = substr($key, 0, $i) . substr($key, $i + 1);
            $typos[] = substr($key, 0, $i) . $key[$i] . substr($key, $i);
            if ($i + 1 < $length) {
                $typos[] = substr($key, 0, $i) . $key[$i + 1] . $key[$i] . substr($key, $i + 2);
            }
        }

        $typos[] = ucfirst($key);
        $typos[] = $key . 's';
        $typos[] = ' ' . $key;
        $typos[] = str_replace('_', '-', $key);
        $typos[] = lcfirst(str_replace('_', '', ucwords($key, '_')));

        return array_values(array_unique(array_filter(
            $typos,
            static fn(string $typo): bool => $typo !== $key && !in_array($typo, $known, true)
        )));
    }

    /**
     * The array with one key renamed and everything else, the order
     * included, as it was.
     *
     * @param array<string, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function withKeyRenamed(array $data, string $from, string $to): array
    {
        $renamed = [];
        foreach ($data as $key => $value) {
            $renamed[$key === $from ? $to : $key] = $value;
        }

        return $renamed;
    }

    /**
     * Free-form metadata as JSON holds it: strings, integers, booleans,
     * null, floats with a fraction, and lists and maps of them.
     *
     * @return array<string, mixed>
     */
    public static function metadata(Randomizer $random): array
    {
        $values = [
            'North',
            '',
            'é 日本',
            0,
            -17,
            true,
            false,
            null,
            2.5,
            ['a', 'b'],
            ['home' => 'Court 1', 'tags' => [1, 2, 3]],
            [],
        ];

        $metadata = [];
        for ($i = $random->getInt(0, 3); $i > 0; --$i) {
            $metadata['key' . $random->getInt(1, 6)] = $values[$random->getInt(0, count($values) - 1)];
        }

        return $metadata;
    }

    /**
     * An event of two different participants of the field, in a round or
     * in none.
     *
     * @param array<Participant> $field
     */
    private static function event(Randomizer $random, array $field): Event
    {
        $field = array_values($field);
        $first = $random->getInt(0, count($field) - 1);
        $second = ($first + $random->getInt(1, count($field) - 1)) % count($field);

        return new Event(
            [$field[$first], $field[$second]],
            $random->getInt(0, 5) === 0 ? null : new Round($random->getInt(1, 30), self::metadata($random)),
            self::metadata($random)
        );
    }

    /**
     * A win/draw/loss scale; half of them with a value for a bye, which
     * writes the key `bye`, and the rest with none.
     *
     * @param array<Participant> $field
     */
    private static function ranking(Randomizer $random, array $field): WinDrawLossRanking
    {
        return new WinDrawLossRanking(
            $random->getInt(-4, 12) / 2,
            $random->getInt(-4, 12) / 4,
            $random->getInt(-4, 12) / 8,
            $random->getInt(0, 1) === 1 ? $random->getInt(-4, 12) / 4 : null
        );
    }

    /**
     * The mark of an awarded result, with a label, a reason, both or
     * neither.
     */
    private static function award(Randomizer $random): Award
    {
        return new Award(
            [null, '', 'no-show', 'é 日本'][$random->getInt(0, 3)],
            [null, 'The event was not played', ''][$random->getInt(0, 2)]
        );
    }

    /**
     * A decider for one or more of the participants given, in a drawn
     * order, with a label, a reason, both or neither. Most name one
     * participant, as the decider of a tie between two does; one in four
     * names every participant given, which the type allows.
     *
     * @param list<Participant> $participants
     */
    private static function decider(Randomizer $random, array $participants): Decider
    {
        $order = $random->shuffleArray($participants);
        $named = $random->getInt(0, 3) === 0 ? $order : [$order[0]];

        return new Decider(
            array_map(static fn(Participant $participant): string => $participant->getId(), $named),
            [null, '', 'drawn by lot', 'é 日本'][$random->getInt(0, 3)],
            [null, 'The event finished level', ''][$random->getInt(0, 2)]
        );
    }

    /**
     * The decisions a rank selector is given: none for one selector in two,
     * and otherwise one to three, each as decider() draws one. A selector
     * holds what it is given and checks it against a table only when it
     * selects, so the decisions need fit no table here.
     *
     * @param list<Participant> $participants
     *
     * @return list<Decider>
     */
    private static function decisions(Randomizer $random, array $participants): array
    {
        $decisions = [];
        for ($i = $random->getInt(0, 1) * $random->getInt(1, 3); $i > 0; --$i) {
            $decisions[] = self::decider($random, $participants);
        }

        return $decisions;
    }

    /**
     * An adjustment for one of the field: a whole amount, a fraction, a
     * zero, an amount JSON writes with an exponent, of either sign. Two in
     * three say after which round they were made, before the first
     * included, and one in three does not.
     *
     * @param array<Participant> $field
     */
    private static function adjustment(Randomizer $random, array $field): Adjustment
    {
        $field = array_values($field);

        return new Adjustment(
            $field[$random->getInt(0, count($field) - 1)]->getId(),
            [-3.0, 1.0, 0.0, 0.1, -0.125, 1.0e-9, -2.5e15, 0.30000000000000004][$random->getInt(0, 7)],
            [null, 'deduction', ''][$random->getInt(0, 2)],
            [null, 'a late entry'][$random->getInt(0, 1)],
            [null, 0, $random->getInt(1, 6)][$random->getInt(0, 2)]
        );
    }

    /**
     * A result of the event: a win for either side or a draw, with scores
     * or without, one in four of them awarded and one in four of them with
     * a decider for participants of the event.
     */
    private static function result(Randomizer $random, Event $event): Result
    {
        [$first, $second] = array_values($event->getParticipants());

        return new Result(
            $event,
            [$first, $second, null][$random->getInt(0, 2)],
            match ($random->getInt(0, 2)) {
                0 => [],
                1 => [$first->getId() => $random->getInt(0, 9), $second->getId() => $random->getInt(0, 9)],
                default => [$second->getId() => $random->getInt(0, 20) / 4 + 0.125],
            },
            self::metadata($random),
            $random->getInt(0, 3) === 0 ? self::award($random) : null,
            $random->getInt(0, 3) === 0 ? self::decider($random, [$first, $second]) : null
        );
    }

    /**
     * A pairing of one round: the field paired off in a shuffled order,
     * some of it left with a bye. Now and then a pair meets over two legs,
     * which is two events of one round told apart by `tie_leg`. Now and
     * then it is marked as paired before the results were all in: with
     * some of the given events of earlier rounds as those that awaited one,
     * or, as an application's row may have it, with none.
     *
     * @param array<Participant> $field
     * @param list<Event> $earlier Events of earlier rounds, which the round may say awaited a result
     */
    private static function pairing(Randomizer $random, array $field, int $number, array $earlier = []): RoundPairing
    {
        $order = $random->shuffleArray($field);
        $round = new Round($number, $random->getInt(0, 1) === 1 ? ['label' => 'round of ' . count($field)] : []);
        $paired = intdiv(count($order), 2) - $random->getInt(0, 1);

        $events = [];
        for ($i = 0; $i < $paired; ++$i) {
            [$first, $second] = [$order[2 * $i], $order[2 * $i + 1]];
            if ($random->getInt(0, 4) === 0) {
                $events[] = new Event([$first, $second], $round, ['tie_leg' => 1]);
                $events[] = new Event([$second, $first], $round, ['tie_leg' => 2]);
            } else {
                $events[] = new Event([$first, $second], $round, self::metadata($random));
            }
        }

        // Now and then a round paired before the results were all in
        $early = $random->getInt(0, 5) === 0;
        $unresolved = [];
        if ($early && $earlier !== [] && $random->getInt(0, 3) !== 0) {
            $unresolved = array_slice($random->shuffleArray($earlier), 0, $random->getInt(1, count($earlier)));
        }

        return new RoundPairing(
            $number,
            $random->getInt(0, 1) === 1 ? 'label ' . $number : null,
            $events,
            array_slice($order, 2 * $paired),
            $early,
            $unresolved
        );
    }

    /**
     * Events of the rounds before the given one, as a round pairing may
     * name them as awaiting a result when it was paired.
     *
     * @param array<Participant> $field
     *
     * @return list<Event>
     */
    private static function earlierEvents(Randomizer $random, array $field, int $number): array
    {
        $events = [];
        for ($i = $number > 1 ? $random->getInt(1, 3) : 0; $i > 0; --$i) {
            $order = $random->shuffleArray(array_values($field));
            if (count($order) < 2) {
                break;
            }
            $events[] = new Event([$order[0], $order[1]], new Round($random->getInt(1, $number - 1)));
        }

        return $events;
    }

    /**
     * A stage state recorded the way an application records one: started,
     * rounds recorded in order and numbered 1, 2, 3 (a state refuses a gap),
     * some events with a result and some without, a result added later, a
     * withdrawal, and a stamp or none. Its adjustments are recorded after
     * a round of the stage: the one the state gives them, or an earlier
     * one they state themselves.
     *
     * @param array<Participant> $field
     *
     * @throws InvalidConfigurationException When the generator records something a state refuses
     */
    private static function stageState(Randomizer $random, array $field): StageState
    {
        $field = array_values($field);
        $state = StageState::start($random->shuffleArray($field));

        $number = 0;
        for ($rounds = $random->getInt(0, 4); $rounds > 0; --$rounds) {
            ++$number;
            $pairing = self::pairing($random, $field, $number, $state->getEventsWithoutResult());

            $results = [];
            $later = [];
            foreach ($pairing->getEvents() as $event) {
                match ($random->getInt(0, 3)) {
                    0 => null,
                    1 => $later[] = self::result($random, $event),
                    default => $results[] = self::result($random, $event),
                };
            }

            $state = $state->withRoundPlayed($pairing, $results);
            if ($later !== []) {
                $state = $state->withAdditionalResults($later);
            }
            if ($random->getInt(0, 5) === 0) {
                $state = $state->withoutParticipant($field[$random->getInt(0, count($field) - 1)]);
            }
        }

        // Half of them with adjustments, recorded for anyone of the field
        // the state has seen: one who is active, and one who withdrew
        if ($random->getInt(0, 1) === 1) {
            $seen = array_map(static fn(Participant $participant): string => $participant->getId(), $state->getAllSeenParticipants());
            for ($i = $random->getInt(1, 3); $i > 0 && $seen !== []; --$i) {
                $adjustment = self::adjustment($random, $field);
                if (in_array($adjustment->getParticipantId(), $seen, true)) {
                    // A round the adjustment states is one the state holds
                    $state = $state->withAdjustment(new Adjustment(
                        $adjustment->getParticipantId(),
                        $adjustment->getAmount(),
                        $adjustment->getLabel(),
                        $adjustment->getReason(),
                        $adjustment->getAfterRound() === null ? null : min($adjustment->getAfterRound(), $number)
                    ));
                }
            }
        }

        return match ($random->getInt(0, 2)) {
            0 => $state,
            1 => $state->withEngineFingerprint('an engine of our own'),
            default => $state->withEngineFingerprint(new SwissPairingEngine()->getFingerprint()),
        };
    }

    /**
     * @param array<Participant> $field
     */
    private static function scheduledEvent(Randomizer $random, array $field): ScheduledEvent
    {
        return new ScheduledEvent(
            self::event($random, $field),
            self::instant($random),
            $random->getInt(0, 1) === 1 ? 'Court ' . $random->getInt(1, 4) : null
        );
    }

    /**
     * An instant on a whole second, in UTC.
     */
    private static function instant(Randomizer $random): DateTimeImmutable
    {
        return new DateTimeImmutable('@0')
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTimestamp(1_785_610_800 + $random->getInt(0, 40_000_000));
    }

    /**
     * 19:00, 19:30 or 20:15 local time on a day of 2026 or 2027, in one of
     * a handful of timezones: a time of day no clock change skips or
     * repeats.
     */
    private static function localEvening(Randomizer $random): DateTimeImmutable
    {
        $zone = match ($random->getInt(0, 4)) {
            0 => new DateTimeZone('UTC'),
            1 => new DateTimeZone('Europe/London'),
            2 => new DateTimeZone('America/New_York'),
            3 => new DateTimeZone('Asia/Kolkata'),
            default => new DateTimeZone('+02:00'),
        };
        [$hour, $minute] = [[19, 0], [19, 30], [20, 15]][$random->getInt(0, 2)];

        // Day 1 + n of January 2026 counts on through the months
        return new DateTimeImmutable('2026-01-01 00:00:00', $zone)
            ->setDate(2026, 1, 1 + $random->getInt(0, 700))
            ->setTime($hour, $minute);
    }

    /**
     * The time, one draw in three with a fraction of a second: a timeline
     * start, a session start and a bound of a blackout window are written
     * with the fraction they have, and without one when they have none.
     */
    private static function sometimesWithFraction(Randomizer $random, DateTimeImmutable $time): DateTimeImmutable
    {
        return $random->getInt(0, 2) === 0
            ? $time->setTime((int) $time->format('G'), (int) $time->format('i'), (int) $time->format('s'), [250_000, 1, 999_999][$random->getInt(0, 2)])
            : $time;
    }

    /**
     * The same array, typed as the `fromArray()` methods take it. PHP turns
     * a key that is a numeric string into an integer, so an array cannot be
     * forced to string keys; what a reader does with such a key is part of
     * what is tested.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        /** @var array<string, mixed> $data */
        return $data;
    }
}
