<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;

/**
 * Runs the group stage of a multi-stage tournament.
 *
 * Participants are distributed into balanced groups with serpentine seeding,
 * each group plays round robin, and per-group standings determine qualifiers.
 * Qualifiers are reseeded for the knockout stage - group winners first, then
 * runners-up, and so on, in group order - which produces cross-group
 * first-round pairings in SingleEliminationEngine when the group count is a
 * power of two (e.g. A1 vs B2 and B1 vs A2 with two groups). With other group
 * counts, same-group first-round clashes are possible.
 */
readonly class GroupStageEngine
{
    public function __construct(
        private RoundRobinScheduler $scheduler = new RoundRobinScheduler(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
    }

    /**
     * Distribute participants into balanced groups using serpentine seeding.
     *
     * Participants are ordered by seed (unseeded last, keeping input order)
     * and dealt in a snake pattern so total seed strength is balanced:
     * with two groups, seeds 1, 4, 5, 8 land in group A and 2, 3, 6, 7 in B.
     *
     * @param array<Participant> $participants
     * @return array<string, array<Participant>> Groups keyed by label ('A', 'B', ...)
     *
     * @throws InvalidConfigurationException When the group configuration is invalid
     */
    public function createGroups(array $participants, int $groupCount): array
    {
        $participants = array_values($participants);

        if ($groupCount < 1 || $groupCount > 26) {
            throw new InvalidConfigurationException(
                'Group count must be between 1 and 26',
                ['group_count' => $groupCount]
            );
        }

        if (count($participants) < $groupCount * 2) {
            throw new InvalidConfigurationException(
                'Each group needs at least 2 participants',
                ['participant_count' => count($participants), 'group_count' => $groupCount]
            );
        }

        $ids = array_map(fn (Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
            );
        }

        $ordered = $this->orderBySeed($participants);

        $groups = [];
        for ($index = 0; $index < $groupCount; ++$index) {
            $groups[chr(65 + $index)] = [];
        }
        $labels = array_keys($groups);

        foreach ($ordered as $position => $participant) {
            $row = intdiv($position, $groupCount);
            $column = $position % $groupCount;
            // Serpentine: odd rows deal right-to-left
            $label = $labels[$row % 2 === 0 ? $column : $groupCount - 1 - $column];
            $groups[$label][] = $participant;
        }

        return $groups;
    }

    /**
     * Generate a round-robin schedule for each group.
     *
     * Each schedule's metadata gains a 'group' entry with the group label.
     *
     * @param array<string, array<Participant>> $groups
     * @return array<string, Schedule> Schedules keyed by group label
     *
     * @throws InvalidConfigurationException
     * @throws IncompleteScheduleException
     */
    public function scheduleGroups(array $groups, int $legs = 1, ?LegStrategyInterface $strategy = null): array
    {
        $schedules = [];
        foreach ($groups as $label => $groupParticipants) {
            $schedule = $this->scheduler->schedule($groupParticipants, 2, $legs, $strategy);
            $schedules[$label] = new Schedule(
                $schedule->getEvents(),
                [...$schedule->getMetadata(), 'group' => $label]
            );
        }

        return $schedules;
    }

    /**
     * Calculate standings for each group from the recorded results.
     *
     * @param array<string, array<Participant>> $groups
     * @param array<Result> $results
     * @return array<string, Standings> Standings keyed by group label
     *
     * @throws InvalidConfigurationException When a result spans two groups or references an unknown participant
     */
    public function calculateGroupStandings(array $groups, array $results): array
    {
        $groupByParticipantId = [];
        foreach ($groups as $label => $groupParticipants) {
            foreach ($groupParticipants as $participant) {
                $groupByParticipantId[$participant->getId()] = $label;
            }
        }

        /** @var array<string, array<Result>> $resultsByGroup */
        $resultsByGroup = array_fill_keys(array_keys($groups), []);
        foreach ($results as $result) {
            $eventGroups = [];
            foreach ($result->getEvent()->getParticipants() as $participant) {
                $label = $groupByParticipantId[$participant->getId()] ?? null;
                if ($label === null) {
                    throw new InvalidConfigurationException(
                        "Result references participant {$participant->getId()} who is not in any group",
                        ['participant' => $participant->getId()]
                    );
                }
                $eventGroups[$label] = true;
            }

            if (count($eventGroups) !== 1) {
                throw new InvalidConfigurationException(
                    'Result spans multiple groups: ' . implode(', ', array_keys($eventGroups)),
                    ['groups' => array_keys($eventGroups)]
                );
            }

            $resultsByGroup[array_key_first($eventGroups)][] = $result;
        }

        $standings = [];
        foreach ($groups as $label => $groupParticipants) {
            $standings[$label] = $this->standingsCalculator->calculate(
                $groupParticipants,
                $resultsByGroup[$label]
            );
        }

        return $standings;
    }

    /**
     * Get the knockout qualifiers, reseeded for the bracket.
     *
     * Seeds are assigned block by block in group order: group winners take
     * seeds 1..G, runners-up G+1..2G, and so on. Feed the returned
     * participants straight into SingleEliminationEngine.
     *
     * @param array<string, array<Participant>> $groups
     * @param array<Result> $results
     * @return array<Participant> Qualifiers with knockout seeds assigned
     *
     * @throws InvalidConfigurationException When qualifiersPerGroup exceeds a group's size
     */
    public function getQualifiers(array $groups, array $results, int $qualifiersPerGroup): array
    {
        if ($qualifiersPerGroup < 1) {
            throw new InvalidConfigurationException(
                'Qualifiers per group must be at least 1',
                ['qualifiers_per_group' => $qualifiersPerGroup]
            );
        }

        foreach ($groups as $label => $groupParticipants) {
            if (count($groupParticipants) < $qualifiersPerGroup) {
                throw new InvalidConfigurationException(
                    "Group {$label} has fewer participants than qualifiers requested",
                    ['group' => $label, 'group_size' => count($groupParticipants), 'qualifiers_per_group' => $qualifiersPerGroup]
                );
            }
        }

        $groupStandings = $this->calculateGroupStandings($groups, $results);

        $qualifiers = [];
        $seed = 1;
        for ($position = 0; $position < $qualifiersPerGroup; ++$position) {
            foreach ($groupStandings as $standings) {
                $entry = $standings->getEntries()[$position];
                $qualifiers[] = $entry->getParticipant()->withSeed($seed);
                ++$seed;
            }
        }

        return $qualifiers;
    }

    /**
     * Order participants by seed (unseeded last, keeping input order).
     *
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function orderBySeed(array $participants): array
    {
        $indexed = [];
        foreach ($participants as $index => $participant) {
            $indexed[] = ['participant' => $participant, 'index' => $index];
        }

        usort(
            $indexed,
            fn (array $first, array $second): int => (($first['participant']->getSeed() ?? PHP_INT_MAX) <=> ($second['participant']->getSeed() ?? PHP_INT_MAX))
                ?: ($first['index'] <=> $second['index'])
        );

        return array_map(fn (array $entry) => $entry['participant'], $indexed);
    }
}
