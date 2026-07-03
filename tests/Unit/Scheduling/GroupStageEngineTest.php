<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\GroupStageEngine;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

/**
 * @return array<Participant>
 */
function groupField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("t{$i}", "Team {$i}", $i);
    }

    return $participants;
}

/**
 * Play out a schedule with the better (lower) original seed always winning.
 *
 * @param iterable<Event> $events
 * @return array<Result>
 */
function playFavourites(iterable $events): array
{
    $results = [];
    foreach ($events as $event) {
        $participants = $event->getParticipants();
        $winner = ($participants[0]->getSeed() ?? PHP_INT_MAX) < ($participants[1]->getSeed() ?? PHP_INT_MAX)
            ? $participants[0]
            : $participants[1];
        $results[] = new Result($event, $winner);
    }

    return $results;
}

describe('GroupStageEngine', function (): void {
    it('distributes participants into serpentine-seeded groups', function (): void {
        $groups = (new GroupStageEngine())->createGroups(groupField(8), 2);

        $idsByGroup = array_map(
            fn (array $group) => array_map(fn (Participant $p) => $p->getId(), $group),
            $groups
        );

        expect($idsByGroup)->toBe([
            'A' => ['t1', 't4', 't5', 't8'],
            'B' => ['t2', 't3', 't6', 't7'],
        ]);
    });

    it('balances three groups with the snake pattern', function (): void {
        $groups = (new GroupStageEngine())->createGroups(groupField(6), 3);

        $idsByGroup = array_map(
            fn (array $group) => array_map(fn (Participant $p) => $p->getId(), $group),
            $groups
        );

        expect($idsByGroup)->toBe([
            'A' => ['t1', 't6'],
            'B' => ['t2', 't5'],
            'C' => ['t3', 't4'],
        ]);
    });

    it('places unseeded participants in input order behind seeded ones', function (): void {
        $participants = [
            new Participant('u1', 'Unseeded One'),
            new Participant('u2', 'Unseeded Two'),
            new Participant('top', 'Top Seed', 1),
            new Participant('second', 'Second Seed', 2),
        ];

        $groups = (new GroupStageEngine())->createGroups($participants, 2);

        expect(array_map(fn (Participant $p) => $p->getId(), $groups['A']))->toBe(['top', 'u2']);
        expect(array_map(fn (Participant $p) => $p->getId(), $groups['B']))->toBe(['second', 'u1']);
    });

    it('rejects invalid group configurations', function (): void {
        $engine = new GroupStageEngine();

        expect(fn () => $engine->createGroups(groupField(8), 0))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => $engine->createGroups(groupField(8), 27))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => $engine->createGroups(groupField(3), 2))
            ->toThrow(InvalidConfigurationException::class);

        $duplicates = [new Participant('t1', 'One'), new Participant('t1', 'Clone'), new Participant('t2', 'Two'), new Participant('t3', 'Three')];
        expect(fn () => $engine->createGroups($duplicates, 2))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('schedules a labelled round robin per group', function (): void {
        $engine = new GroupStageEngine();
        $groups = $engine->createGroups(groupField(8), 2);
        $schedules = $engine->scheduleGroups($groups);

        expect(array_keys($schedules))->toBe(['A', 'B']);
        foreach ($schedules as $label => $schedule) {
            expect(count($schedule))->toBe(6); // 4 participants -> 6 events
            expect($schedule->getMetadataValue('group'))->toBe($label);
            expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
        }
    });

    it('calculates standings per group and rejects cross-group results', function (): void {
        $engine = new GroupStageEngine();
        $groups = $engine->createGroups(groupField(8), 2);
        $schedules = $engine->scheduleGroups($groups);

        $results = [...playFavourites($schedules['A']), ...playFavourites($schedules['B'])];
        $standings = $engine->calculateGroupStandings($groups, $results);

        // Better seeds win every game, so group order follows seeds
        expect($standings['A']->getEntries()[0]->getParticipant()->getId())->toBe('t1');
        expect($standings['A']->getEntries()[0]->getPoints())->toBe(9.0);
        expect($standings['B']->getEntries()[0]->getParticipant()->getId())->toBe('t2');

        // A result pairing participants from different groups is rejected
        $crossGroup = new Result(
            new Event([$groups['A'][0], $groups['B'][0]]),
            $groups['A'][0]
        );
        expect(fn () => $engine->calculateGroupStandings($groups, [$crossGroup]))
            ->toThrow(InvalidConfigurationException::class, 'spans multiple groups');
    });

    it('reseeds qualifiers in winner and runner-up blocks', function (): void {
        $engine = new GroupStageEngine();
        $groups = $engine->createGroups(groupField(8), 2);
        $schedules = $engine->scheduleGroups($groups);
        $results = [...playFavourites($schedules['A']), ...playFavourites($schedules['B'])];

        $qualifiers = $engine->getQualifiers($groups, $results, 2);

        $seededIds = array_map(
            fn (Participant $p) => [$p->getId(), $p->getSeed()],
            $qualifiers
        );

        // Winners (A: t1, B: t2) take seeds 1-2; runners-up (A: t4, B: t3) take 3-4
        expect($seededIds)->toBe([
            ['t1', 1],
            ['t2', 2],
            ['t4', 3],
            ['t3', 4],
        ]);
    });

    it('rejects qualifier counts a group cannot supply', function (): void {
        $engine = new GroupStageEngine();
        $groups = $engine->createGroups(groupField(6), 3);

        expect(fn () => $engine->getQualifiers($groups, [], 3))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => $engine->getQualifiers($groups, [], 0))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects qualification while group play is incomplete', function (): void {
        $engine = new GroupStageEngine();
        $groups = $engine->createGroups(groupField(8), 2);
        $schedules = $engine->scheduleGroups($groups);

        // Group A fully played, group B missing its final result
        $groupBResults = playFavourites($schedules['B']);
        array_pop($groupBResults);
        $results = [...playFavourites($schedules['A']), ...$groupBResults];

        expect(fn () => $engine->getQualifiers($groups, $results, 2))
            ->toThrow(InvalidConfigurationException::class, 'Group B play is incomplete');
    });

    it('runs a full multi-stage tournament from groups to a champion', function (): void {
        $groupEngine = new GroupStageEngine();
        $knockoutEngine = new SingleEliminationEngine();
        $participants = groupField(8);

        // Group stage
        $groups = $groupEngine->createGroups($participants, 2);
        $schedules = $groupEngine->scheduleGroups($groups);
        $groupResults = [...playFavourites($schedules['A']), ...playFavourites($schedules['B'])];

        // Knockout stage
        $qualifiers = $groupEngine->getQualifiers($groups, $groupResults, 2);
        expect($qualifiers)->toHaveCount(4);

        $semifinals = $knockoutEngine->pairNextRound($qualifiers, []);
        expect($semifinals->getStage())->toBe('semifinal');

        // Cross-group semifinals: each group winner meets the other group's runner-up
        foreach ($semifinals->getEvents() as $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            expect($ids)->toBeIn([['t1', 't3'], ['t2', 't4']]);
        }

        $knockoutResults = playFavourites($semifinals->getEvents());
        $final = $knockoutEngine->pairNextRound($qualifiers, $knockoutResults);
        expect($final->getStage())->toBe('final');

        $knockoutResults = [...$knockoutResults, ...playFavourites($final->getEvents())];
        $champion = $knockoutEngine->getChampion($qualifiers, $knockoutResults);

        expect($champion?->getId())->toBe('t1');
    });
});
