<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\PinConflictException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\SessionGrid;
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
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\PotDrawPlan;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

// The rule: every configuration error the library raises carries a reason
// (Exceptions\InvalidConfigurationReason), so a caller never has to match the
// message text to tell one mistake from another.
//
// It is checked two ways. The source is read: every place in `src/` that
// builds an InvalidConfigurationException passes `reason:` by name, and every
// case of the enum is passed somewhere. Then real mistakes are made, and the
// exception that comes out is asked for its reason, its message and its
// report. The rule has no exception: no file is allowed a site without one.

/**
 * The first line of the diagnostic report's requirements for a round robin.
 */
const ROUND_ROBIN_REQUIREMENT = 'Participants array must contain at least 2 participants';

/**
 * Every place under `src/` that builds an InvalidConfigurationException: a
 * `new InvalidConfigurationException(...)`, and the `parent::__construct(...)`
 * of a class that extends it.
 *
 * @return list<array{file: string, line: int, reasons: list<string>}> `reasons` holds the enum
 *                                                                     cases named by the
 *                                                                     site's `reason:` argument;
 *                                                                     empty when it has none
 *
 * @throws UnexpectedValueException When `src/` cannot be read
 * @throws AssertionFailedError When no site is found at all
 */
function configurationErrorSites(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    $sites = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($root . '/' . $file);
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            fn(PhpToken $token): bool => !$token->isIgnorable()
        ));
        $extendsIt = preg_match('/class\s+\w+\s+extends\s+InvalidConfigurationException\b/', $source) === 1;
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $open = null;
            if ($tokens[$i]->id === T_NEW
                && str_ends_with($tokens[$i + 1]->text ?? '', 'InvalidConfigurationException')
                && ($tokens[$i + 2]->text ?? '') === '('
            ) {
                $open = $i + 2;
            } elseif ($extendsIt
                && $tokens[$i]->text === 'parent'
                && ($tokens[$i + 2]->text ?? '') === '__construct'
                && ($tokens[$i + 3]->text ?? '') === '('
            ) {
                $open = $i + 3;
            }

            if ($open === null) {
                continue;
            }

            $reasons = [];
            $named = false;
            $depth = 0;
            for ($j = $open; $j < $count; ++$j) {
                // A bracket is a token of its own. The text of a string can be
                // a bracket too ("...round {$round})"), and that one is not.
                $text = $tokens[$j]->text;
                $id = $tokens[$j]->id;
                if ($id === ord('(') || $id === ord('[') || $id === ord('{') || $id === T_CURLY_OPEN) {
                    ++$depth;
                } elseif ($id === ord(')') || $id === ord(']') || $id === ord('}')) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $text === 'reason' && ($tokens[$j + 1]->text ?? '') === ':') {
                    $named = true;
                } elseif ($named
                    && $text === 'InvalidConfigurationReason'
                    && ($tokens[$j + 1]->id ?? null) === T_DOUBLE_COLON
                ) {
                    $reasons[] = $tokens[$j + 2]->text;
                }
            }

            $sites[] = ['file' => $file, 'line' => $tokens[$i]->line, 'reasons' => $reasons];
        }
    }

    if ($sites === []) {
        Assert::fail('No site that builds an InvalidConfigurationException was found under src/.');
    }

    return $sites;
}

/**
 * The configuration error a mistake produces.
 *
 * @param Closure(): mixed $mistake
 *
 * @throws AssertionFailedError When the mistake raises nothing
 */
function configurationErrorFrom(Closure $mistake): InvalidConfigurationException
{
    try {
        $mistake();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    Assert::fail('The mistake raised no InvalidConfigurationException.');
}

describe('the reason of a configuration error', function (): void {
    it('is set at every site in src/ that builds one', function (): void {
        $sites = configurationErrorSites();

        $without = [];
        foreach ($sites as $site) {
            if ($site['reasons'] === []) {
                $without[] = "{$site['file']}:{$site['line']}";
            }
        }

        expect($without)->toBe([], "These sites build an InvalidConfigurationException without `reason:`:\n" . implode("\n", $without));

        // The reader of the source does find the sites of StageState, which
        // were the last to gain a reason: as many as the file has, counted
        // another way, so that the rule cannot hold there for want of sites.
        $inStageState = array_filter($sites, fn(array $site): bool => $site['file'] === 'src/Stage/StageState.php');
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Stage/StageState.php');
        expect(count($inStageState))->toBe(substr_count($source, 'new InvalidConfigurationException('))
            ->and(count($inStageState))->toBeGreaterThan(0);
    });

    it('names only cases that exist', function (): void {
        $known = array_map(fn(InvalidConfigurationReason $reason): string => $reason->name, InvalidConfigurationReason::cases());

        foreach (configurationErrorSites() as $site) {
            foreach ($site['reasons'] as $reason) {
                expect($known)->toContain($reason);
            }
        }
    });

    it('has no case that no site uses', function (): void {
        $used = [];
        foreach (configurationErrorSites() as $site) {
            foreach ($site['reasons'] as $reason) {
                $used[$reason] = true;
            }
        }

        $unused = [];
        foreach (InvalidConfigurationReason::cases() as $reason) {
            if (!isset($used[$reason->name])) {
                $unused[] = $reason->name;
            }
        }

        expect($unused)->toBe([]);
    });

    // The backing strings are identifiers a caller may log, store and
    // dispatch on. Changing one is a breaking change, so each is pinned.
    it('keeps the backing string of every case', function (): void {
        $values = [];
        foreach (InvalidConfigurationReason::cases() as $reason) {
            $values[$reason->name] = $reason->value;
        }

        expect($values)->toBe([
            'TooFewParticipants' => 'too_few_participants',
            'DuplicateParticipantIds' => 'duplicate_participant_ids',
            'InvalidLegCount' => 'invalid_leg_count',
            'InvalidRoundCount' => 'invalid_round_count',
            'UnsupportedOptions' => 'unsupported_options',
            'IncompatibleOptions' => 'incompatible_options',
            'UnknownIdentifier' => 'unknown_identifier',
            'WrongValueType' => 'wrong_value_type',
            'ValueOutOfRange' => 'value_out_of_range',
            'EmptyList' => 'empty_list',
            'DuplicateName' => 'duplicate_name',
            'NotSerializable' => 'not_serializable',
            'UnsatisfiableLegStrategy' => 'unsatisfiable_leg_strategy',
            'UnknownOptionKey' => 'unknown_option_key',
            'OddParticipantCount' => 'odd_participant_count',
            'UnequalPots' => 'unequal_pots',
            'TooManyOpponentsPerPot' => 'too_many_opponents_per_pot',
            'OddPotWithOddOpponents' => 'odd_pot_with_odd_opponents',
            'ConfigurationNotYetSupported' => 'configuration_not_yet_supported',
            'BracketComplete' => 'bracket_complete',
            'RoundPartiallyResolved' => 'round_partially_resolved',
            'EventWithoutRoundNumber' => 'event_without_round_number',
            'InvalidResult' => 'invalid_result',
            'DuplicateResult' => 'duplicate_result',
            'UndecidedTie' => 'undecided_tie',
            'IncompatibleOutcome' => 'incompatible_outcome',
            'RankUnavailable' => 'rank_unavailable',
            'RoundOutOfSequence' => 'round_out_of_sequence',
            'EventNotInRound' => 'event_not_in_round',
            'NoRoundRecorded' => 'no_round_recorded',
            'ResultNotRecorded' => 'result_not_recorded',
            'RoundSuperseded' => 'round_superseded',
            'EmptyEngineFingerprint' => 'empty_engine_fingerprint',
            'EngineFingerprintMismatch' => 'engine_fingerprint_mismatch',
            'EmptyEventId' => 'empty_event_id',
            'IdenticalParticipants' => 'identical_participants',
            'DuplicateEventId' => 'duplicate_event_id',
            'PinOffGrid' => 'pin_off_grid',
            'PinCapacityExceeded' => 'pin_capacity_exceeded',
            'PinConflict' => 'pin_conflict',
            'PositionOutOfRange' => 'position_out_of_range',
            'UnparseableTime' => 'unparseable_time',
            'TimezoneMismatch' => 'timezone_mismatch',
            'NonAdvancingTime' => 'non_advancing_time',
            'TimeRuleViolation' => 'time_rule_violation',
            'TimelineCapacityExceeded' => 'timeline_capacity_exceeded',
            'ConstraintViolation' => 'constraint_violation',
            'InvalidSchedule' => 'invalid_schedule',
        ]);
    });

    // The usage guide lists the cases for a reader who has no source to
    // read. A case added to the enum has to be added to that table.
    it('is listed in the usage guide, case by case', function (): void {
        $guide = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/USAGE.md');

        $listed = [];
        preg_match_all('/^\| `(\w+)` \| `([a-z_]+)` \| \S/m', $guide, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            $listed[$row[1]] = $row[2];
        }

        $cases = [];
        foreach (InvalidConfigurationReason::cases() as $reason) {
            $cases[$reason->name] = $reason->value;
        }

        expect($listed)->toBe($cases);
    });

    it('is the one the mistake calls for', function (Closure $mistake, InvalidConfigurationReason $reason): void {
        expect(configurationErrorFrom($mistake)->getReason())->toBe($reason);
    })->with([
        'one participant in a round robin' => [
            fn() => (new RoundRobinScheduler())->schedule([new Participant('a', 'A')]),
            InvalidConfigurationReason::TooFewParticipants,
        ],
        'two participants with one ID' => [
            fn() => (new RoundRobinScheduler())->schedule([new Participant('a', 'A'), new Participant('a', 'B')]),
            InvalidConfigurationReason::DuplicateParticipantIds,
        ],
        'zero legs' => [
            fn() => new RoundRobinOptions(legs: 0),
            InvalidConfigurationReason::InvalidLegCount,
        ],
        'legs given as a string' => [
            fn() => RoundRobinOptions::fromArray(['legs' => '2']),
            InvalidConfigurationReason::InvalidLegCount,
        ],
        'a leg strategy nobody knows' => [
            fn() => RoundRobinOptions::fromArray(['strategy' => 'sideways']),
            InvalidConfigurationReason::UnknownIdentifier,
        ],
        'Swiss options given to the round-robin scheduler' => [
            fn() => (new RoundRobinScheduler())->schedule(
                [new Participant('a', 'A'), new Participant('b', 'B')],
                new SwissOptions(rounds: 1)
            ),
            InvalidConfigurationReason::UnsupportedOptions,
        ],
        'zero Swiss rounds' => [
            fn() => new SwissOptions(rounds: 0),
            InvalidConfigurationReason::InvalidRoundCount,
        ],
        'a pot draw option nobody knows' => [
            fn() => PotDrawOptions::fromArray(['pots' => 4, 'rounds' => 8]),
            InvalidConfigurationReason::UnknownOptionKey,
        ],
        'zero pots' => [
            fn() => new PotDrawOptions(pots: 0),
            InvalidConfigurationReason::ValueOutOfRange,
        ],
        'pots given as a string' => [
            fn() => PotDrawOptions::fromArray(['pots' => '4']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'Swiss options given to the pot draw scheduler' => [
            fn() => (new PotDrawScheduler())->schedule(
                [new Participant('a', 'A'), new Participant('b', 'B')],
                new SwissOptions(rounds: 1)
            ),
            InvalidConfigurationReason::UnsupportedOptions,
        ],
        'a pot draw of an odd field' => [
            fn() => (new PotDrawScheduler())->schedule(configurationErrorField(19), new PotDrawOptions(pots: 1, opponentsPerPot: 2)),
            InvalidConfigurationReason::OddParticipantCount,
        ],
        'a pot draw whose pots cannot be of equal size' => [
            fn() => (new PotDrawScheduler())->schedule(configurationErrorField(20), new PotDrawOptions(pots: 3)),
            InvalidConfigurationReason::UnequalPots,
        ],
        'more opponents per pot than a pot has other members' => [
            fn() => (new PotDrawScheduler())->schedule(configurationErrorField(20), new PotDrawOptions(pots: 5, opponentsPerPot: 4)),
            InvalidConfigurationReason::TooManyOpponentsPerPot,
        ],
        'pots of odd size with an odd number of opponents per pot' => [
            fn() => (new PotDrawScheduler())->schedule(configurationErrorField(20), new PotDrawOptions(pots: 4, opponentsPerPot: 1)),
            InvalidConfigurationReason::OddPotWithOddOpponents,
        ],
        'a pot draw the library cannot construct yet' => [
            fn() => (new PotDrawScheduler())->schedule(configurationErrorField(36), new PotDrawOptions(pots: 4, opponentsPerPot: 4)),
            InvalidConfigurationReason::ConfigurationNotYetSupported,
        ],
        'a pot draw plan with one participant' => [
            fn() => new PotDrawPlan(configurationErrorField(1), 1, 1),
            InvalidConfigurationReason::TooFewParticipants,
        ],
        'a pot draw plan with two participants of one ID' => [
            fn() => new PotDrawPlan([new Participant('a', 'A'), new Participant('a', 'B')], 1, 1),
            InvalidConfigurationReason::DuplicateParticipantIds,
        ],
        'an unknown timezone' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01 19:00:00',
                'timezone' => 'Neverland/Nowhere',
                'round_interval' => 'P7D',
            ]),
            InvalidConfigurationReason::UnparseableTime,
        ],
        'a duration that is not ISO 8601' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01 19:00:00',
                'timezone' => 'UTC',
                'round_interval' => 'a week',
            ]),
            InvalidConfigurationReason::UnparseableTime,
        ],
        'a start that carries its own offset' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01T19:00:00+05:00',
                'timezone' => 'Europe/London',
                'round_interval' => 'P7D',
            ]),
            InvalidConfigurationReason::TimezoneMismatch,
        ],
        'a grid with an empty session list' => [
            fn() => SessionGrid::fromArray(['sessions' => [], 'timezone' => 'UTC', 'slot_interval' => 'PT25M']),
            InvalidConfigurationReason::EmptyList,
        ],
        'a grid whose sessions are not a list' => [
            fn() => SessionGrid::fromArray(['sessions' => 'tonight', 'timezone' => 'UTC', 'slot_interval' => 'PT25M']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'a slot interval of zero' => [
            fn() => new SessionGrid(
                [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
                new DateInterval('PT0M')
            ),
            InvalidConfigurationReason::NonAdvancingTime,
        ],
        'a movable event with an empty ID' => [
            fn() => new MovableEvent('', new Participant('a', 'A'), new Participant('b', 'B')),
            InvalidConfigurationReason::EmptyEventId,
        ],
        'an event of one participant against itself' => [
            fn() => new MovableEvent('e1', new Participant('a', 'A'), new Participant('a', 'A again')),
            InvalidConfigurationReason::IdenticalParticipants,
        ],
        'a pin at a negative session' => [
            fn() => new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), -1, 0),
            InvalidConfigurationReason::PositionOutOfRange,
        ],
        'one event ID used twice in a request' => [
            fn() => new RepackRequest(
                [new MovableEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'))],
                [new PinnedEvent('e1', new Participant('c', 'C'), new Participant('d', 'D'), 0, 0)],
                configurationErrorGrid()
            ),
            InvalidConfigurationReason::DuplicateEventId,
        ],
        'a pin off the grid' => [
            fn() => new RepackRequest(
                [],
                [new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 4)],
                configurationErrorGrid()
            ),
            InvalidConfigurationReason::PinOffGrid,
        ],
        'more pins at a position than a slot holds' => [
            fn() => new RepackRequest(
                [],
                [
                    new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 0),
                    new PinnedEvent('e2', new Participant('c', 'C'), new Participant('d', 'D'), 0, 0),
                ],
                configurationErrorGrid(1)
            ),
            InvalidConfigurationReason::PinCapacityExceeded,
        ],
        'one participant pinned twice at a position' => [
            fn() => new RepackRequest(
                [],
                [
                    new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 0),
                    new PinnedEvent('e2', new Participant('a', 'A'), new Participant('c', 'C'), 0, 0),
                ],
                configurationErrorGrid(2)
            ),
            InvalidConfigurationReason::PinConflict,
        ],
        'a finished bracket asked for another round' => [
            function (): void {
                $engine = new SingleEliminationEngine();
                $alice = new Participant('a', 'Alice');
                $state = StageState::start([$alice, new Participant('b', 'Bob')]);
                $pairing = $engine->pairNextRound($state);
                $final = $pairing->getEvents()[0];
                $state = $state->withRoundPlayed($pairing, [
                    new Result($final, $alice),
                ]);
                $engine->pairNextRound($state);
            },
            InvalidConfigurationReason::BracketComplete,
        ],
        'a bracket tie that ended level' => [
            function (): void {
                $engine = new SingleEliminationEngine();
                $state = StageState::start([
                    new Participant('a', 'Alice'),
                    new Participant('b', 'Bob'),
                    new Participant('c', 'Carol'),
                    new Participant('d', 'Dan'),
                ]);
                $pairing = $engine->pairNextRound($state);
                $state = $state->withRoundPlayed($pairing, array_map(
                    fn(Event $event) => new Result($event),
                    $pairing->getEvents()
                ));
                $engine->pairNextRound($state);
            },
            InvalidConfigurationReason::UndecidedTie,
        ],
        'the next round asked for with one semi-final unplayed' => [
            function (): void {
                $engine = new SingleEliminationEngine();
                $alice = new Participant('a', 'Alice');
                $state = StageState::start([
                    $alice,
                    new Participant('b', 'Bob'),
                    new Participant('c', 'Carol'),
                    new Participant('d', 'Dan'),
                ]);
                $pairing = $engine->pairNextRound($state);
                foreach ($pairing->getEvents() as $event) {
                    if ($event->hasParticipant($alice)) {
                        $state = $state->withRoundPlayed($pairing, [new Result($event, $alice)]);
                    }
                }
                $engine->pairNextRound($state);
            },
            InvalidConfigurationReason::RoundPartiallyResolved,
        ],
        'two results recorded for one bracket match' => [
            function (): void {
                $engine = new SingleEliminationEngine();
                $alice = new Participant('a', 'Alice');
                $bob = new Participant('b', 'Bob');
                $state = StageState::start([$alice, $bob]);
                $pairing = $engine->pairNextRound($state);
                $final = $pairing->getEvents()[0];
                $state = $state->withRoundPlayed($pairing, [new Result($final, $alice), new Result($final, $bob)]);
                $engine->pairNextRound($state);
            },
            InvalidConfigurationReason::DuplicateResult,
        ],
        'a result between two pools' => [
            function (): void {
                $pools = PoolDistributor::serpentine(configurationErrorField(4), 2);
                PoolDistributor::splitResults($pools, [new Result(new Event([$pools['A'][0], $pools['B'][0]]))]);
            },
            InvalidConfigurationReason::InvalidResult,
        ],
        'a result of participants who are in no pool' => [
            function (): void {
                $pools = PoolDistributor::serpentine(configurationErrorField(4), 2);
                PoolDistributor::splitResults($pools, [
                    new Result(new Event([new Participant('x', 'X'), new Participant('y', 'Y')])),
                ]);
            },
            InvalidConfigurationReason::InvalidResult,
        ],
        'two events a round on a timeline of one slot' => [
            fn() => (new TimelineAssigner())->assign(
                (new RoundRobinScheduler())->schedule(configurationErrorField(4)),
                new TimelineDefinition(
                    new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
                    new DateInterval('P7D')
                )
            ),
            InvalidConfigurationReason::TimelineCapacityExceeded,
        ],
        'daily rounds under a rule of two days of rest' => [
            fn() => (new TimelineAssigner([new MinimumRestRule(new DateInterval('PT48H'))]))->assign(
                (new RoundRobinScheduler())->schedule(configurationErrorField(4)),
                new TimelineDefinition(
                    new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
                    new DateInterval('P1D'),
                    2,
                    new DateInterval('PT2H')
                )
            ),
            InvalidConfigurationReason::TimeRuleViolation,
        ],
        'an event with no round number given a kickoff' => [
            fn() => (new TimelineAssigner())->assign(
                new Schedule([new Event([new Participant('a', 'A'), new Participant('b', 'B')])]),
                new TimelineDefinition(
                    new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
                    new DateInterval('P7D')
                )
            ),
            InvalidConfigurationReason::EventWithoutRoundNumber,
        ],
        // The two sites that choose between two reasons when they throw
        'a blackout rule with an empty window list' => [
            fn() => BlackoutRule::fromArray(['windows' => []]),
            InvalidConfigurationReason::EmptyList,
        ],
        'a blackout rule whose windows are not a list' => [
            fn() => BlackoutRule::fromArray(['windows' => 'weekends']),
            InvalidConfigurationReason::WrongValueType,
        ],
        // A key left out is read as null, which is not of the type needed
        'a blackout rule with no windows key' => [
            fn() => BlackoutRule::fromArray([]),
            InvalidConfigurationReason::WrongValueType,
        ],
        'a grid with no sessions key' => [
            fn() => SessionGrid::fromArray(['timezone' => 'UTC', 'slot_interval' => 'PT25M']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'a timeline with no timezone' => [
            fn() => TimelineDefinition::fromArray(['start' => '2026-08-01 19:00:00', 'round_interval' => 'P7D']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'a timeline with no round interval' => [
            fn() => TimelineDefinition::fromArray(['start' => '2026-08-01 19:00:00', 'timezone' => 'UTC']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'two slots a round and no slot interval' => [
            fn() => new TimelineDefinition(
                new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
                new DateInterval('P7D'),
                2
            ),
            InvalidConfigurationReason::IncompatibleOptions,
        ],
        'one resource named twice' => [
            fn() => new TimelineDefinition(
                new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
                new DateInterval('P7D'),
                1,
                null,
                ['Pitch 1', 'Pitch 1']
            ),
            InvalidConfigurationReason::DuplicateName,
        ],
        'a round interval of zero' => [
            fn() => new TimelineDefinition(
                new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
                new DateInterval('PT0S')
            ),
            InvalidConfigurationReason::NonAdvancingTime,
        ],
        'a blackout window that ends before it starts' => [
            fn() => BlackoutRule::fromArray(['windows' => [
                ['from' => '2026-08-02 00:00', 'to' => '2026-08-01 00:00', 'timezone' => 'UTC'],
            ]]),
            InvalidConfigurationReason::NonAdvancingTime,
        ],
        'a session index past the end of the grid' => [
            fn() => configurationErrorGrid()->getSessionStart(1),
            InvalidConfigurationReason::PositionOutOfRange,
        ],
        'a negative objective weight' => [
            fn() => new RepackOptions(consolidationWeight: -1),
            InvalidConfigurationReason::ValueOutOfRange,
        ],
        'a step budget given as a string' => [
            fn() => RepackOptions::fromArray(['step_budget' => '200']),
            InvalidConfigurationReason::WrongValueType,
        ],
        'three legs in an elimination tie' => [
            fn() => new EliminationOptions(legsPerTie: 3),
            InvalidConfigurationReason::InvalidLegCount,
        ],
        're-seeding asked of a double elimination' => [
            fn() => new DoubleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
            InvalidConfigurationReason::IncompatibleOptions,
        ],
        'no pools' => [
            fn() => PoolDistributor::serpentine([new Participant('a', 'A'), new Participant('b', 'B')], 0),
            InvalidConfigurationReason::ValueOutOfRange,
        ],
        'two pools for three participants' => [
            fn() => PoolDistributor::serpentine(
                [new Participant('a', 'A'), new Participant('b', 'B'), new Participant('c', 'C')],
                2
            ),
            InvalidConfigurationReason::TooFewParticipants,
        ],
        'more Swiss rounds than there are opponents' => [
            fn() => (new SwissScheduler())->schedule(
                [new Participant('a', 'A'), new Participant('b', 'B')],
                new SwissOptions(rounds: 2)
            ),
            InvalidConfigurationReason::InvalidRoundCount,
        ],
        'round-robin options given to the Swiss scheduler' => [
            fn() => (new SwissScheduler())->schedule(
                [new Participant('a', 'A'), new Participant('b', 'B')],
                new RoundRobinOptions()
            ),
            InvalidConfigurationReason::UnsupportedOptions,
        ],
    ]);
});

/**
 * Participants p1, p2, ... in that order.
 *
 * @return list<Participant>
 */
function configurationErrorField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}");
    }

    return $field;
}

/**
 * One session of four slots.
 *
 * @throws InvalidConfigurationException
 */
function configurationErrorGrid(int $capacityPerSlot = 2): SessionGrid
{
    return new SessionGrid(
        [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
        new DateInterval('PT25M'),
        4,
        [],
        $capacityPerSlot
    );
}

describe('the message of a configuration error', function (): void {
    // A downstream consumer matches this substring. The reason is the way
    // to tell the error apart from now on, and the text stays what it was.
    it('still says that the format requires at least 2 participants', function (Closure $mistake, string $message): void {
        $exception = configurationErrorFrom($mistake);

        expect($exception->getMessage())->toBe($message)
            ->and($exception->getMessage())->toContain('requires at least 2 participants')
            ->and($exception->getReason())->toBe(InvalidConfigurationReason::TooFewParticipants)
            ->and($exception->getContext())->toBe(['participant_count' => 1, 'minimum_required' => 2]);
    })->with([
        'the round-robin scheduler' => [
            fn() => (new RoundRobinScheduler())->schedule([new Participant('a', 'A')]),
            'Invalid scheduler configuration: Round-robin scheduling requires at least 2 participants',
        ],
        'the round-robin plan' => [
            fn() => new RoundRobinPlan([new Participant('a', 'A')], 1),
            'Invalid scheduler configuration: Round-robin scheduling requires at least 2 participants',
        ],
        'the Swiss scheduler' => [
            fn() => (new SwissScheduler())->schedule([new Participant('a', 'A')], new SwissOptions(rounds: 1)),
            'Invalid scheduler configuration: Swiss scheduling requires at least 2 participants',
        ],
        'the Swiss plan' => [
            fn() => new SwissPlan([new Participant('a', 'A')], 1),
            'Invalid scheduler configuration: Swiss scheduling requires at least 2 participants',
        ],
        'the Swiss pairing engine' => [
            fn() => (new SwissPairingEngine())->pairNextRound(StageState::start([new Participant('a', 'A')])),
            'Invalid scheduler configuration: Swiss pairing requires at least 2 participants',
        ],
    ]);
});

describe('the requirements of a configuration error', function (): void {
    // The requirements block used to be the round-robin list whatever had
    // failed. It belongs to the component that failed.
    it('lists the round-robin requirements for a round-robin error', function (Closure $mistake): void {
        $exception = configurationErrorFrom($mistake);

        expect($exception->getRequirements())->toBe(InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS)
            ->and($exception->getDiagnosticReport())->toEndWith(
                "\n\n=== REQUIREMENTS ===\n"
                . "• Participants array must contain at least 2 participants\n"
                . "• Legs must be a positive integer (≥ 1)\n"
                . "• All participants must have unique IDs\n"
                . "• Constraint set must be valid\n"
                . '• Scheduler must support the requested configuration'
            );
    })->with([
        'the scheduler' => [fn() => (new RoundRobinScheduler())->schedule([new Participant('a', 'A')])],
        'the options' => [fn() => new RoundRobinOptions(legs: 0)],
        'the options as plain data' => [fn() => RoundRobinOptions::fromArray(['legs' => '2'])],
        'the plan' => [fn() => new RoundRobinPlan([new Participant('a', 'A')], 1)],
    ]);

    it('lists no round-robin requirement for an error that is not about a round robin', function (Closure $mistake): void {
        $exception = configurationErrorFrom($mistake);
        $report = $exception->getDiagnosticReport();

        expect($exception->getRequirements())->toBe([])
            ->and($report)->not->toContain(ROUND_ROBIN_REQUIREMENT)
            ->and($report)->not->toContain('Legs must be a positive integer')
            ->and($report)->not->toContain('REQUIREMENTS');
    })->with([
        'a timezone that does not exist' => [fn() => TimelineDefinition::fromArray([
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'Neverland/Nowhere',
            'round_interval' => 'P7D',
        ])],
        'a grid timezone that does not exist' => [fn() => SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:00'],
            'timezone' => 'Neverland/Nowhere',
            'slot_interval' => 'PT25M',
        ])],
        'a participant pinned twice' => [fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 0),
                new PinnedEvent('e2', new Participant('a', 'A'), new Participant('c', 'C'), 0, 0),
            ],
            configurationErrorGrid(2)
        )],
        'one participant in a Swiss stage' => [fn() => new SwissPlan([new Participant('a', 'A')], 1)],
        'a pot draw of an odd field' => [fn() => (new PotDrawScheduler())->schedule(
            configurationErrorField(19),
            new PotDrawOptions(pots: 1, opponentsPerPot: 2)
        )],
        // The errors of StageState are covered one by one further down
        'a result replaced before any round is recorded' => [function (): void {
            $alice = new Participant('a', 'Alice');
            $bob = new Participant('b', 'Bob');
            StageState::start([$alice, $bob])->withResultReplaced(new Result(new Event([$alice, $bob]), $alice));
        }],
        'a result replaced that was never recorded' => [function (): void {
            $alice = new Participant('a', 'Alice');
            $bob = new Participant('b', 'Bob');
            $event = new Event([$alice, $bob], new Round(1));
            StageState::start([$alice, $bob])
                ->withRoundPlayed(new RoundPairing(1, null, [$event]), [])
                ->withResultReplaced(new Result($event, $alice));
        }],
        'a result replaced under a later round' => [function (): void {
            $alice = new Participant('a', 'Alice');
            $bob = new Participant('b', 'Bob');
            $first = new Event([$alice, $bob], new Round(1));
            StageState::start([$alice, $bob])
                ->withRoundPlayed(new RoundPairing(1, null, [$first]), [new Result($first, $alice)])
                ->withRoundPlayed(new RoundPairing(2, null, [new Event([$bob, $alice], new Round(2))]), [])
                ->withResultReplaced(new Result($first, $bob));
        }],
        'an empty engine fingerprint' => [
            fn() => StageState::start([new Participant('a', 'A')])->withEngineFingerprint(''),
        ],
        'a state stamped by another engine' => [
            fn() => (new SingleEliminationEngine())->isComplete(
                StageState::start([new Participant('a', 'A'), new Participant('b', 'B')])
                    ->withEngineFingerprint((new SwissPairingEngine())->getFingerprint())
            ),
        ],
    ]);

    it('writes the whole report of a timezone error', function (): void {
        $exception = configurationErrorFrom(fn() => TimelineDefinition::fromArray([
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'Neverland/Nowhere',
            'round_interval' => 'P7D',
        ]));

        expect($exception->getDiagnosticReport())->toBe(
            "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
            . "\n"
            . "Issue: start or its timezone is not parseable\n"
            . "\n"
            . "=== CONFIGURATION DETAILS ===\n"
            . "• start: 2026-08-01 19:00:00\n"
            . '• timezone: Neverland/Nowhere'
        );
    });
});

/**
 * Every configuration error `Stage\StageState` raises, by the mistake that
 * raises it: the reason it states, the issue it reports and its context.
 *
 * The issue and the context are the ones each error had before it stated a
 * reason. Alice (`a`), Bob (`b`), Carol (`c`) and Dan (`d`) are the field.
 *
 * @return array<string, array{0: Closure(): mixed, 1: InvalidConfigurationReason, 2: string, 3: array<string, mixed>}>
 */
function stageStateMistakes(): array
{
    $alice = new Participant('a', 'Alice');
    $bob = new Participant('b', 'Bob');
    $carol = new Participant('c', 'Carol');
    $dan = new Participant('d', 'Dan');

    $first = new Event([$alice, $bob], new Round(1));
    $second = new Event([$bob, $alice], new Round(2));
    $roundOne = new RoundPairing(1, null, [$first]);
    $roundTwo = new RoundPairing(2, null, [$second]);

    $start = fn(): StageState => StageState::start([$alice, $bob, $carol, $dan]);

    return [
        'two participants with one ID at the start of a stage' => [
            fn() => StageState::start([$alice, new Participant('a', 'Alice again'), $bob]),
            InvalidConfigurationReason::DuplicateParticipantIds,
            'All participants must have unique IDs',
            ['participant_count' => 3, 'unique_ids' => 2],
        ],
        'a round recorded a second time' => [
            fn() => $start()->withRoundPlayed($roundOne, [])->withRoundPlayed($roundOne, []),
            InvalidConfigurationReason::RoundOutOfSequence,
            'Rounds must be recorded in play order with increasing round numbers',
            ['last_round' => 1, 'pairing_round' => 1],
        ],
        'round 1 recorded after round 2' => [
            fn() => $start()->withRoundPlayed($roundTwo, [])->withRoundPlayed($roundOne, []),
            InvalidConfigurationReason::RoundOutOfSequence,
            'Rounds must be recorded in play order with increasing round numbers',
            ['last_round' => 2, 'pairing_round' => 1],
        ],
        'a pairing of round 1 that holds an event of round 2' => [
            fn() => $start()->withRoundPlayed(new RoundPairing(1, null, [$first, $second]), []),
            InvalidConfigurationReason::EventNotInRound,
            'Pairing contains an event from a different round',
            ['pairing_round' => 1, 'event_round' => 2],
        ],
        // An event with no round number at all is the mistake the engines
        // and the timeline assigner report as EventWithoutRoundNumber. It is
        // the same mistake here, with the message this error always had.
        'a pairing that holds an event with no round' => [
            fn() => $start()->withRoundPlayed(new RoundPairing(1, null, [new Event([$alice, $bob])]), []),
            InvalidConfigurationReason::EventWithoutRoundNumber,
            'Pairing contains an event from a different round',
            ['pairing_round' => 1, 'event_round' => null],
        ],
        'a result whose event has no round, recorded with round 1' => [
            fn() => $start()->withRoundPlayed($roundOne, [new Result(new Event([$alice, $bob]), $alice)]),
            InvalidConfigurationReason::EventWithoutRoundNumber,
            'Result belongs to a different round than the pairing being recorded',
            ['pairing_round' => 1, 'result_round' => null],
        ],
        'a further result whose event has no round' => [
            fn() => $start()->withRoundPlayed($roundOne, [])
                ->withAdditionalResults([new Result(new Event([$alice, $bob]), $alice)]),
            InvalidConfigurationReason::EventWithoutRoundNumber,
            'Result belongs to a different round than the pairing being recorded',
            ['pairing_round' => 1, 'result_round' => null],
        ],
        'a result of round 2 recorded with round 1' => [
            fn() => $start()->withRoundPlayed($roundOne, [new Result($second, $bob)]),
            InvalidConfigurationReason::EventNotInRound,
            'Result belongs to a different round than the pairing being recorded',
            ['pairing_round' => 1, 'result_round' => 2],
        ],
        'a result for an event the round does not hold' => [
            fn() => $start()->withRoundPlayed($roundOne, [new Result(new Event([$carol, $dan], new Round(1)), $carol)]),
            InvalidConfigurationReason::EventNotInRound,
            'Result references an event that is not part of the pairing being recorded',
            ['pairing_round' => 1, 'event' => '1:c|d:1'],
        ],
        'a further result for an event the last round does not hold' => [
            fn() => $start()->withRoundPlayed($roundOne, [])
                ->withAdditionalResults([new Result(new Event([$carol, $dan], new Round(1)), $carol)]),
            InvalidConfigurationReason::EventNotInRound,
            'Result references an event that is not part of the pairing being recorded',
            ['pairing_round' => 1, 'event' => '1:c|d:1'],
        ],
        'further results before any round is recorded' => [
            fn() => $start()->withAdditionalResults([new Result($first, $alice)]),
            InvalidConfigurationReason::NoRoundRecorded,
            'No round has been recorded to add results to',
            [],
        ],
        'a result replaced before any round is recorded' => [
            fn() => $start()->withResultReplaced(new Result($first, $alice)),
            InvalidConfigurationReason::NoRoundRecorded,
            'No round has been recorded to replace a result in',
            [],
        ],
        'a result replaced that was never recorded' => [
            fn() => $start()->withRoundPlayed($roundOne, [])->withResultReplaced(new Result($first, $alice)),
            InvalidConfigurationReason::ResultNotRecorded,
            'No result is recorded for the event; record a first result with withRoundPlayed() or withAdditionalResults()',
            ['round' => 1, 'participants' => ['a', 'b']],
        ],
        'a result of round 1 replaced after round 2 was recorded' => [
            fn() => $start()
                ->withRoundPlayed($roundOne, [new Result($first, $alice)])
                ->withRoundPlayed($roundTwo, [])
                ->withResultReplaced(new Result($first, $bob)),
            InvalidConfigurationReason::RoundSuperseded,
            'A result of round 1 cannot be replaced: round 2 was paired from the results of round 1. Rebuild the state'
                . ' up to round 1 with the corrected result (StageState::start(), then withRoundPlayed() for each round'
                . ' that stands) and pair again.',
            ['round' => 1, 'last_round' => 2],
        ],
        'an empty engine fingerprint' => [
            fn() => $start()->withEngineFingerprint(''),
            InvalidConfigurationReason::EmptyEngineFingerprint,
            'An engine fingerprint cannot be empty',
            [],
        ],
        'a state stamped by one engine and read by another' => [
            fn() => $start()->withEngineFingerprint('engine-one')->requireEngineFingerprint('engine-two'),
            InvalidConfigurationReason::EngineFingerprintMismatch,
            "The stage state was recorded by a different engine or configuration (the stamp is not this engine's);"
                . ' stamp it again with withEngineFingerprint() if the change is deliberate',
            ['recorded' => 'engine-one', 'engine' => 'engine-two', 'differences' => ["the stamp is not this engine's"]],
        ],
    ];
}

describe('a configuration error of StageState', function (): void {
    it('states the reason the mistake calls for', function (Closure $mistake, InvalidConfigurationReason $reason): void {
        expect(configurationErrorFrom($mistake)->getReason())->toBe($reason);
    })->with(fn(): array => stageStateMistakes());

    // Stating a reason changed nothing else a caller can read
    it('keeps its message, its context, its code and no previous exception', function (
        Closure $mistake,
        InvalidConfigurationReason $reason,
        string $issue,
        array $context
    ): void {
        $exception = configurationErrorFrom($mistake);

        expect($exception->getConfigurationIssue())->toBe($issue)
            ->and($exception->getMessage())->toBe("Invalid scheduler configuration: {$issue}")
            ->and($exception->getContext())->toBe($context)
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();
    })->with(fn(): array => stageStateMistakes());

    // None of them is about a round robin, and three of them ended with the
    // round-robin requirements all the same.
    it('lists no requirements, and no round-robin requirement in its report', function (Closure $mistake): void {
        $exception = configurationErrorFrom($mistake);
        $report = $exception->getDiagnosticReport();

        expect($exception->getRequirements())->toBe([])
            ->and($report)->not->toContain('REQUIREMENTS')
            ->and($report)->not->toContain(ROUND_ROBIN_REQUIREMENT);
    })->with(fn(): array => stageStateMistakes());

    it('is raised through the engines with the same reason', function (): void {
        $exception = configurationErrorFrom(fn() => (new SingleEliminationEngine())->isComplete(
            StageState::start([new Participant('a', 'A'), new Participant('b', 'B')])
                ->withEngineFingerprint((new SwissPairingEngine())->getFingerprint())
        ));

        expect($exception->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch);
    });

    it('writes the whole report of a round recorded out of order', function (): void {
        $event = new Event([new Participant('a', 'Alice'), new Participant('b', 'Bob')], new Round(1));
        $pairing = new RoundPairing(1, null, [$event]);
        $exception = configurationErrorFrom(
            fn() => StageState::start($event->getParticipants())->withRoundPlayed($pairing, [])->withRoundPlayed($pairing, [])
        );

        expect($exception->getDiagnosticReport())->toBe(
            "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
            . "\n"
            . "Issue: Rounds must be recorded in play order with increasing round numbers\n"
            . "\n"
            . "=== CONFIGURATION DETAILS ===\n"
            . "• last_round: 1\n"
            . '• pairing_round: 1'
        );
    });
});

describe('a pin conflict', function (): void {
    it('carries both event ids, the participant and the position', function (): void {
        // Given: Participant "a" pinned in e1 and in e2 at session 0, slot 3
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [new MovableEvent('m1', new Participant('x', 'X'), new Participant('y', 'Y'))],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e9', new Participant('c', 'C'), new Participant('d', 'D'), 0, 1),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(2)
        ));

        // Then: The typed exception names the two events through its accessors
        expect($exception)->toBeInstanceOf(PinConflictException::class);
        assert($exception instanceof PinConflictException);
        expect($exception->getEventIds())->toBe(['e1', 'e2'])
            ->and($exception->getParticipantId())->toBe('a')
            ->and($exception->getSession())->toBe(0)
            ->and($exception->getSlot())->toBe(3)
            ->and($exception->getReason())->toBe(InvalidConfigurationReason::PinConflict);
    });

    it('names both event ids in the diagnostic report', function (): void {
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(2)
        ));

        expect($exception->getDiagnosticReport())->toBe(
            "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
            . "\n"
            . "Issue: A participant is pinned twice at one position\n"
            . "\n"
            . "=== CONFIGURATION DETAILS ===\n"
            . "• participant: a\n"
            . "• session: 0\n"
            . "• slot: 3\n"
            . '• event_ids: ["e1", "e2"]'
        );
    });

    it('names the first and the colliding event when others are pinned between them', function (): void {
        // Given: e1 and e3 share participant "a" at 0:3; e2 sits there too with others
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('d', 'D'), 0, 3),
                new PinnedEvent('e3', new Participant('e', 'E'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(3)
        ));

        assert($exception instanceof PinConflictException);
        expect($exception->getEventIds())->toBe(['e1', 'e3'])
            ->and($exception->getParticipantId())->toBe('a');
    });

    it('reports one participant when two events share both', function (): void {
        // Given: The same two participants pinned twice at 0:3, sides swapped
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('b', 'B'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(2)
        ));

        // Then: Both events are named; the participant is one of the two shared
        assert($exception instanceof PinConflictException);
        expect($exception->getEventIds())->toBe(['e1', 'e2'])
            ->and(['a', 'b'])->toContain($exception->getParticipantId());
    });

    it('is not raised for one participant pinned at two different positions', function (): void {
        $request = new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 2),
                new PinnedEvent('e2', new Participant('a', 'A'), new Participant('c', 'C'), 0, 3),
            ],
            configurationErrorGrid(2)
        );

        expect($request->getPinnedEvents())->toHaveCount(2);
    });

    // A position is the session, the slot and the participant together. An
    // ID that holds the separator the request uses must not make two
    // different participants look like one.
    it('is not raised for two participants whose IDs differ only around a colon', function (): void {
        $request = new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('1:a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('a', 'A2'), new Participant('c', 'C'), 0, 3),
            ],
            configurationErrorGrid(2)
        );

        expect($request->getPinnedEvents())->toHaveCount(2);
    });

    // The request checks the capacity of a slot before the participants in
    // it. On a grid whose slots hold one event, two pins at one position are
    // an overflow whoever plays in them, and that error carries no event ID.
    it('gives way to the capacity error where the slot holds one event', function (): void {
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(1)
        ));

        expect($exception)->not->toBeInstanceOf(PinConflictException::class)
            ->and($exception->getReason())->toBe(InvalidConfigurationReason::PinCapacityExceeded)
            ->and($exception->getMessage())->toBe("Invalid scheduler configuration: Pinned events overflow a slot's declared capacity")
            ->and($exception->getContext())->toBe(['session' => 0, 'slot' => 3, 'capacity_per_slot' => 1]);
    });

    // The message, the context and the class a catch clause matches are
    // what they were before the typed exception existed.
    it('keeps the message and the context, and is still an InvalidConfigurationException', function (): void {
        $exception = configurationErrorFrom(fn() => new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 3),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('a', 'A'), 0, 3),
            ],
            configurationErrorGrid(2)
        ));

        expect($exception->getMessage())->toBe('Invalid scheduler configuration: A participant is pinned twice at one position')
            ->and($exception->getConfigurationIssue())->toBe('A participant is pinned twice at one position')
            ->and($exception->getContext())->toBe([
                'participant' => 'a',
                'session' => 0,
                'slot' => 3,
                'event_ids' => ['e1', 'e2'],
            ])
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();
    });
});

describe('a timezone that holds a NUL byte', function (): void {
    // PHP rejects such a name with a ValueError, which is not an Exception.
    // It reached the caller as a PHP error; it is one more bad timezone.
    it('is reported as a configuration error', function (Closure $mistake, string $issue): void {
        $exception = configurationErrorFrom($mistake);

        expect($exception->getReason())->toBe(InvalidConfigurationReason::UnparseableTime)
            ->and($exception->getMessage())->toBe("Invalid scheduler configuration: {$issue}")
            ->and($exception->getPrevious())->toBeInstanceOf(ValueError::class)
            ->and($exception->getContext()['timezone'])->toBe("Europe/Lon\0don");
    })->with([
        'in a timeline definition' => [
            fn() => TimelineDefinition::fromArray([
                'start' => '2026-08-01 19:00:00',
                'timezone' => "Europe/Lon\0don",
                'round_interval' => 'P7D',
            ]),
            'start or its timezone is not parseable',
        ],
        'in a session grid' => [
            fn() => SessionGrid::fromArray([
                'sessions' => ['2026-08-12 20:00'],
                'timezone' => "Europe/Lon\0don",
                'slot_interval' => 'PT25M',
            ]),
            'sessions[0] or its timezone is not parseable',
        ],
        // The bad zone is on `from`, which is parsed first
        'in a blackout rule' => [
            fn() => BlackoutRule::fromArray(['windows' => [
                ['from' => '2026-08-01 00:00', 'to' => '2026-08-02 00:00', 'timezone' => "Europe/Lon\0don"],
            ]]),
            'from or its timezone is not parseable',
        ],
        'in JSON-decoded configuration' => [
            fn() => TimelineDefinition::fromArray((array) json_decode(
                '{"start": "2026-08-01 19:00:00", "timezone": "Europe/Lon\u0000don", "round_interval": "P7D"}',
                true
            )),
            'start or its timezone is not parseable',
        ],
    ]);
});
