<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\LegBalanceRule;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;

/**
 * A schedule of one event with the given metadata.
 *
 * @param array<string, mixed> $metadata
 */
function roleFallbackSchedule(array $metadata): Schedule
{
    $a = new Participant('a', 'A');
    $b = new Participant('b', 'B');

    return new Schedule([new Event([$a, $b], new Round(1))], $metadata, [$a, $b]);
}

describe('the mark of a schedule that fell back', function (): void {
    // The key and the three reasons are stored data: an application reads
    // them from a schedule it kept, so they are pinned as strings.
    it('is stored under one key, as one of three strings', function (): void {
        expect(RoleFallback::METADATA_KEY)->toBe('role_fallback')
            ->and(array_map(static fn(RoleFallback $case): string => $case->value, RoleFallback::cases()))->toBe([
                'no_balanced_roles',
                'search_stopped_at_bound',
                'balanced_roles_rejected',
            ]);
    });

    it('reads no mark from a schedule without the key', function (): void {
        expect(RoleFallback::of(roleFallbackSchedule([])))->toBeNull()
            ->and(RoleFallback::of(roleFallbackSchedule(['byes' => [], 'algorithm' => 'round-robin'])))->toBeNull();
    });

    it('reads each reason, and reads it again after the schedule has been plain data', function (RoleFallback $reason): void {
        $schedule = roleFallbackSchedule(['byes' => [], RoleFallback::METADATA_KEY => $reason->value]);

        expect(RoleFallback::of($schedule))->toBe($reason)
            ->and(RoleFallback::of(Schedule::fromArray($schedule->toArray())))->toBe($reason)
            ->and(RoleFallback::of(Schedule::fromJson($schedule->toJson())))->toBe($reason);
    })->with(RoleFallback::cases());

    // A value it does not know is not read as "balanced": a schedule
    // stored by a later release may carry a reason this one has no case
    // for, and a null under the key is a key that is there.
    it('refuses a value under the key that is not one of the reasons', function (mixed $value): void {
        expect(fn() => RoleFallback::of(roleFallbackSchedule([RoleFallback::METADATA_KEY => $value])))
            ->toThrow(InvalidInputException::class, "The schedule's 'role_fallback' metadata must be one of: no_balanced_roles, search_stopped_at_bound, balanced_roles_rejected");
    })->with([
        'a reason of a later release' => ['layout_not_searched'],
        'a case name' => ['NoBalancedRoles'],
        'true' => [true],
        'null' => [null],
        'a list' => [['no_balanced_roles']],
    ]);
});

describe('the role assignment that is balanced where possible', function (): void {
    // It decides a leg as the balanced role assignment does and states the
    // same rule: the fall-back is the scheduler's, and is held by
    // tests/Feature/RoleConstraintsAcrossLegsTest.php.
    it('answers as the balanced role assignment does, with and without fixed seatings', function (): void {
        $participants = [];
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6'] as $id) {
            $participants[$id] = new Participant($id, $id);
        }
        $seat = static fn(string $first, string $second): array => [$participants[$first], $participants[$second]];
        // A leg of six in which p1 is first-named five times
        $rounds = [
            [$seat('p1', 'p6'), $seat('p2', 'p5'), $seat('p3', 'p4')],
            [$seat('p1', 'p5'), $seat('p6', 'p4'), $seat('p2', 'p3')],
            [$seat('p1', 'p4'), $seat('p5', 'p3'), $seat('p6', 'p2')],
            [$seat('p1', 'p3'), $seat('p4', 'p2'), $seat('p5', 'p6')],
            [$seat('p1', 'p2'), $seat('p3', 'p6'), $seat('p4', 'p5')],
        ];
        $strict = new BalancedRoleAssignment();
        $wherePossible = new BalancedWherePossibleRoleAssignment();

        foreach ([new RoleAssignmentContext(), new RoleAssignmentContext(2, 3), new RoleAssignmentContext(1, 2, [[0, 0], [3, 1]])] as $context) {
            $answer = $wherePossible->assignRoles($rounds, $context);

            expect($answer)->toBe($strict->assignRoles($rounds, $context))
                ->and($answer)->not->toBe($rounds);
        }

        expect($wherePossible->rule())->toBeInstanceOf(LegBalanceRule::class);
    });
});
