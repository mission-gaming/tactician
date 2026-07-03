<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\BacktrackingRoundRobinGenerator;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @return array<Participant>
 */
function backtrackingField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("t{$i}", "Team {$i}", $i);
    }

    return $participants;
}

/**
 * Fixes Team 1's opponent order across a 4-team leg: t2 in leg round 1,
 * t3 in round 2, t4 in round 3. A valid decomposition exists, but none of
 * the circle method's rotated orderings produce it.
 */
function fixturePlacementConstraints(): ConstraintSet
{
    return ConstraintSet::create()->custom(static function (Event $event): bool {
        $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);
        $round = $event->getRound()?->getNumber();
        if ($round === null) {
            return true;
        }
        $legRound = (($round - 1) % 3) + 1;

        return match (implode('|', $ids)) {
            't1|t2' => $legRound === 1,
            't1|t3' => $legRound === 2,
            't1|t4' => $legRound === 3,
            default => true,
        };
    }, 'Fixture Placement')->build();
}

describe('Backtracking round-robin generation', function (): void {
    // The known limitation this milestone removes: a satisfiable
    // configuration every greedy rotation fails on
    it('finds schedules the greedy rotations cannot', function (): void {
        $constraints = fixturePlacementConstraints();

        expect(fn () => (new RoundRobinScheduler($constraints))->schedule(backtrackingField(4)))
            ->toThrow(IncompleteScheduleException::class);

        $schedule = (new RoundRobinScheduler($constraints))
            ->schedule(backtrackingField(4), new RoundRobinOptions(backtracking: true));

        expect($schedule)->toHaveCount(6);
        foreach ($schedule as $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            if ($ids === ['t1', 't2']) {
                expect($event->getRound()?->getNumber())->toBe(1);
            }
            if ($ids === ['t1', 't4']) {
                expect($event->getRound()?->getNumber())->toBe(3);
            }
        }
    });

    it('handles odd fields, rotating a bye per round', function (): void {
        $constraints = ConstraintSet::create()->custom(static function (Event $event): bool {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $round = $event->getRound()?->getNumber();

            return match (implode('|', $ids)) {
                't1|t2' => $round === 5,
                't1|t3' => $round === 4,
                default => true,
            };
        }, 'Odd Placement')->build();

        // Greedy fails this placement; the search does not
        expect(fn () => (new RoundRobinScheduler($constraints))->schedule(backtrackingField(5)))
            ->toThrow(IncompleteScheduleException::class);

        $schedule = (new RoundRobinScheduler($constraints))
            ->schedule(backtrackingField(5), new RoundRobinOptions(backtracking: true));

        expect($schedule)->toHaveCount(10);
        $byes = $schedule->getMetadataValue('byes');
        expect($byes)->toHaveCount(5);
        expect(array_unique($byes))->toHaveCount(5); // everyone sits out exactly once
    });

    it('derives later legs from the backtracked first leg', function (): void {
        $schedule = (new RoundRobinScheduler(fixturePlacementConstraints()))
            ->schedule(backtrackingField(4), new RoundRobinOptions(legs: 2, backtracking: true));

        expect($schedule)->toHaveCount(12);

        // Mirrored strategy: round r+3 replays round r with roles reversed
        $byRound = $schedule->getEventsByRound();
        foreach ([1, 2, 3] as $round) {
            foreach ($byRound[$round] as $index => $event) {
                $mirror = $byRound[$round + 3][$index];
                expect(array_map(fn (Participant $p) => $p->getId(), $mirror->getParticipants()))
                    ->toBe(array_reverse(array_map(fn (Participant $p) => $p->getId(), $event->getParticipants())));
            }
        }
    });

    it('proves unsatisfiable configurations by exhausting the search space', function (): void {
        $never = ConstraintSet::create()->custom(fn () => false, 'Reject Everything')->build();

        expect(fn () => (new RoundRobinScheduler($never))
            ->schedule(backtrackingField(4), new RoundRobinOptions(backtracking: true)))
            ->toThrow(IncompleteScheduleException::class, 'exhausted the search space');
    });

    it('fails loudly when the step budget runs out', function (): void {
        // Forbidding the final round is unsatisfiable, but the search space
        // for 12 participants is astronomic - the budget trips first
        $noFinalRound = ConstraintSet::create()
            ->custom(fn (Event $e) => $e->getRound()?->getNumber() !== 11, 'No Round 11')
            ->build();

        expect(fn () => (new RoundRobinScheduler($noFinalRound))
            ->schedule(backtrackingField(12), new RoundRobinOptions(backtracking: true)))
            ->toThrow(IncompleteScheduleException::class, 'step budget');
    });

    it('fails loudly when constraints reject a later leg derivation', function (): void {
        // Leg 1 needs the backtracked placement; its round-4 mirror
        // (t1 vs t2) is then rejected, and the search stops at leg edges
        $constraints = ConstraintSet::create()->custom(static function (Event $event): bool {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $round = $event->getRound()?->getNumber();
            if ($round === null) {
                return true;
            }
            if (implode('|', $ids) === 't1|t2' && $round === 4) {
                return false;
            }
            $legRound = (($round - 1) % 3) + 1;

            return match (implode('|', $ids)) {
                't1|t2' => $round > 3 || $legRound === 1,
                't1|t3' => $round > 3 || $legRound === 2,
                't1|t4' => $round > 3 || $legRound === 3,
                default => true,
            };
        }, 'Placement')->build();

        expect(fn () => (new RoundRobinScheduler($constraints))
            ->schedule(backtrackingField(4), new RoundRobinOptions(legs: 2, backtracking: true)))
            ->toThrow(IncompleteScheduleException::class, 'does not cross leg boundaries');
    });

    it('is deterministic for a seeded randomizer', function (): void {
        $signature = function (Schedule $schedule): string {
            $parts = [];
            foreach ($schedule as $event) {
                $parts[] = $event->getRound()?->getNumber() . ':'
                    . implode('v', array_map(fn (Participant $p) => $p->getId(), $event->getParticipants()));
            }

            return implode(';', $parts);
        };

        $run = fn () => $signature((new RoundRobinScheduler(fixturePlacementConstraints(), new Randomizer(new Mt19937(7))))
            ->schedule(backtrackingField(4), new RoundRobinOptions(backtracking: true)));

        expect($run())->toBe($run());
    });

    it('does not run when backtracking is disabled', function (): void {
        expect(fn () => (new RoundRobinScheduler(fixturePlacementConstraints()))
            ->schedule(backtrackingField(4), new RoundRobinOptions()))
            ->toThrow(IncompleteScheduleException::class);
    });
});

describe('BacktrackingRoundRobinGenerator', function (): void {
    it('generates complete unconstrained legs for any field size', function (): void {
        foreach ([2, 3, 4, 5, 6, 7, 8] as $size) {
            $participants = backtrackingField($size);
            $plan = new RoundRobinPlan($participants, 1);
            $generator = new BacktrackingRoundRobinGenerator();

            $events = $generator->generateFirstLeg($participants, $plan);

            expect($events)->not->toBeNull("size {$size}");
            assert($events !== null);
            expect($events)->toHaveCount(intdiv($size * ($size - 1), 2), "size {$size}");
            expect($generator->wasBudgetExhausted())->toBeFalse("size {$size}");

            // Every pair exactly once
            $pairs = array_map(function (Event $event): string {
                $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
                sort($ids);

                return implode('|', $ids);
            }, $events);
            expect(array_unique($pairs))->toHaveCount(count($pairs), "size {$size}");

            // Odd fields: one bye per round, everyone exactly once
            if ($size % 2 === 1) {
                expect($generator->getRoundByes())->toHaveCount($size, "size {$size}");
                expect(array_unique($generator->getRoundByes()))->toHaveCount($size, "size {$size}");
            }
        }
    });
});

describe('RoundRobinOptions backtracking flag', function (): void {
    it('round-trips through configuration', function (): void {
        $options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'repeated', 'backtracking' => true]);

        expect($options->backtracking)->toBeTrue();
        expect($options->toArray())->toBe(['legs' => 2, 'strategy' => 'repeated', 'backtracking' => true]);
        expect(RoundRobinOptions::fromArray([])->backtracking)->toBeFalse();
    });

    it('rejects a non-boolean flag', function (): void {
        RoundRobinOptions::fromArray(['backtracking' => 'yes']);
    })->throws(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class, 'boolean');
});
