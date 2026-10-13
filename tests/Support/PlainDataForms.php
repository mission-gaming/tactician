<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

/**
 * Every form the library reads from plain data: one entry for each
 * `fromArray()` in `src/`, and one for each array nested in such a form that
 * has keys of its own (a blackout window).
 *
 * An entry holds what a test needs to treat the forms alike: whether the
 * form is configuration or data (which decides the exception it raises),
 * the keys it reads, a sample that holds every one of those keys with a
 * valid value, and the call that reads an array of the form. A form that
 * needs the participants its IDs refer to is read against
 * {@see self::registry()}.
 *
 * `tests/Feature/PlainDataFormsTest.php` compares the entries with the
 * `fromArray()` methods `src/` declares, so a new one fails the suite until
 * it is listed here.
 */
final class PlainDataForms
{
    public const string CONFIGURATION = 'configuration';

    public const string DATA = 'data';

    /**
     * @return array<string, array{
     *     class: class-string,
     *     kind: self::CONFIGURATION|self::DATA,
     *     keys: list<string>,
     *     sample: array<string, mixed>,
     *     read: Closure(array<array-key, mixed>): mixed,
     * }> Keyed by a name for the form
     */
    public static function all(): array
    {
        $registry = self::registry();

        $event = ['participants' => ['a', 'b'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => ['court' => 2]];
        $participants = [
            ['id' => 'a', 'label' => 'Alice', 'seed' => 1, 'metadata' => []],
            ['id' => 'b', 'label' => 'Bob', 'seed' => 2, 'metadata' => []],
            ['id' => 'c', 'label' => 'Carol', 'seed' => null, 'metadata' => []],
        ];
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
                'read' => static fn(array $data): mixed => RoundRobinOptions::fromArray(self::stringKeyed($data)),
            ],
            'Swiss options' => [
                'class' => SwissOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['rounds'],
                'sample' => ['rounds' => 5],
                'read' => static fn(array $data): mixed => SwissOptions::fromArray(self::stringKeyed($data)),
            ],
            'pot draw options' => [
                'class' => PotDrawOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['pots', 'opponents_per_pot', 'seed'],
                'sample' => ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026],
                'read' => static fn(array $data): mixed => PotDrawOptions::fromArray(self::stringKeyed($data)),
            ],
            'elimination options' => [
                'class' => EliminationOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['legs_per_tie', 'reseed_each_round', 'grand_final_reset'],
                'sample' => ['legs_per_tie' => 2, 'reseed_each_round' => true, 'grand_final_reset' => false],
                'read' => static fn(array $data): mixed => EliminationOptions::fromArray(self::stringKeyed($data)),
            ],
            'repack options' => [
                'class' => RepackOptions::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['consolidation_weight', 'early_fill_weight', 'step_budget', 'throw_on_violations'],
                'sample' => [
                    'consolidation_weight' => 5,
                    'early_fill_weight' => 2,
                    'step_budget' => 1_000,
                    'throw_on_violations' => true,
                ],
                'read' => static fn(array $data): mixed => RepackOptions::fromArray(self::stringKeyed($data)),
            ],
            'win-draw-loss ranking' => [
                'class' => WinDrawLossRanking::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['win', 'draw', 'loss'],
                'sample' => ['win' => 2.0, 'draw' => 0.5, 'loss' => -1.0],
                'read' => static fn(array $data): mixed => WinDrawLossRanking::fromArray(self::stringKeyed($data)),
            ],
            'match outcome selector' => [
                'class' => MatchOutcomeSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['mode'],
                'sample' => ['mode' => 'losers'],
                'read' => static fn(array $data): mixed => MatchOutcomeSelector::fromArray(self::stringKeyed($data)),
            ],
            'rank range selector' => [
                'class' => RankRangeSelector::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['mode', 'from', 'to'],
                'sample' => ['mode' => 'per-group', 'from' => 2, 'to' => 3],
                'read' => static fn(array $data): mixed => RankRangeSelector::fromArray(self::stringKeyed($data)),
            ],
            'minimum rest rule' => [
                'class' => MinimumRestRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['rest'],
                'sample' => ['rest' => 'PT48H'],
                'read' => static fn(array $data): mixed => MinimumRestRule::fromArray(self::stringKeyed($data)),
            ],
            'blackout rule' => [
                'class' => BlackoutRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['windows'],
                'sample' => ['windows' => [$window]],
                'read' => static fn(array $data): mixed => BlackoutRule::fromArray(self::stringKeyed($data)),
            ],
            'blackout window' => [
                'class' => BlackoutRule::class,
                'kind' => self::CONFIGURATION,
                'keys' => ['from', 'to', 'timezone', 'label'],
                'sample' => $window,
                'read' => static fn(array $data): mixed => BlackoutRule::fromArray(['windows' => [$data]]),
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
                'read' => static fn(array $data): mixed => TimelineDefinition::fromArray(self::stringKeyed($data)),
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
                'read' => static fn(array $data): mixed => SessionGrid::fromArray(self::stringKeyed($data)),
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
                'read' => static fn(array $data): mixed => SessionGrid::fromArray(self::stringKeyed($data)),
            ],
            'participant' => [
                'class' => Participant::class,
                'kind' => self::DATA,
                'keys' => ['id', 'label', 'seed', 'metadata'],
                'sample' => ['id' => 'a', 'label' => 'Alice', 'seed' => 1, 'metadata' => ['club' => 'North']],
                'read' => static fn(array $data): mixed => Participant::fromArray(self::stringKeyed($data)),
            ],
            'round' => [
                'class' => Round::class,
                'kind' => self::DATA,
                'keys' => ['number', 'metadata'],
                'sample' => ['number' => 3, 'metadata' => ['label' => 'semifinal']],
                'read' => static fn(array $data): mixed => Round::fromArray(self::stringKeyed($data)),
            ],
            'event' => [
                'class' => Event::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'round', 'metadata'],
                'sample' => $event,
                'read' => static fn(array $data): mixed => Event::fromArray(self::stringKeyed($data), $registry),
            ],
            'result' => [
                'class' => Result::class,
                'kind' => self::DATA,
                'keys' => ['event', 'winner', 'scores', 'metadata'],
                'sample' => ['event' => $event, 'winner' => 'a', 'scores' => ['a' => 2, 'b' => 1], 'metadata' => ['note' => 'x']],
                'read' => static fn(array $data): mixed => Result::fromArray(self::stringKeyed($data), $registry),
            ],
            'round pairing' => [
                'class' => RoundPairing::class,
                'kind' => self::DATA,
                'keys' => ['round', 'label', 'events', 'byes'],
                'sample' => ['round' => 1, 'label' => 'semifinal', 'events' => [$event], 'byes' => ['c']],
                'read' => static fn(array $data): mixed => RoundPairing::fromArray(self::stringKeyed($data), $registry),
            ],
            'schedule' => [
                'class' => Schedule::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'events', 'metadata'],
                'sample' => ['participants' => $participants, 'events' => [$event], 'metadata' => ['algorithm' => 'by hand']],
                'read' => static fn(array $data): mixed => Schedule::fromArray(self::stringKeyed($data)),
            ],
            'stage state' => [
                'class' => StageState::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'active', 'rounds', 'results', 'engine_fingerprint'],
                'sample' => [
                    'participants' => $participants,
                    'active' => ['a', 'b', 'c'],
                    'rounds' => [['round' => 1, 'label' => null, 'events' => [$event], 'byes' => ['c']]],
                    'results' => [['event' => $event, 'winner' => 'a', 'scores' => [], 'metadata' => []]],
                    'engine_fingerprint' => 'an engine of our own',
                ],
                'read' => static fn(array $data): mixed => StageState::fromArray(self::stringKeyed($data)),
            ],
            'scheduled event' => [
                'class' => ScheduledEvent::class,
                'kind' => self::DATA,
                'keys' => ['event', 'kickoff', 'resource'],
                'sample' => ['event' => $event, 'kickoff' => '2026-08-01T18:00:00Z', 'resource' => 'Court 1'],
                'read' => static fn(array $data): mixed => ScheduledEvent::fromArray(self::stringKeyed($data), $registry),
            ],
            'scheduled schedule' => [
                'class' => ScheduledSchedule::class,
                'kind' => self::DATA,
                'keys' => ['participants', 'events'],
                'sample' => [
                    'participants' => $participants,
                    'events' => [['event' => $event, 'kickoff' => '2026-08-01T18:00:00Z', 'resource' => null]],
                ],
                'read' => static fn(array $data): mixed => ScheduledSchedule::fromArray(self::stringKeyed($data)),
            ],
        ];
    }

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
     * Ways to mistype a key that leave one wrong key: a character left out,
     * two neighbours swapped, a character doubled, the first letter in
     * upper case, a plural, a hyphen for an underscore, and the camel-case
     * spelling of the constructor. A spelling that is the key itself, or
     * another key the form knows, is left out.
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
