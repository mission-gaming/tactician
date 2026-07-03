<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Distributes participants into pools — the generic composition primitive
 * behind group stages.
 *
 * A pool is just a bucket of participants: what format the bucket plays,
 * how it is scored, and how it progresses are separate concerns (any
 * per-stage format, StandingsCalculator, progression selectors over the
 * pools' combined StageOutcome). "Group stage vs bracket" is a matter of
 * per-pool format and display, not a different kind of object.
 */
final readonly class PoolDistributor
{
    /**
     * Distribute participants into balanced pools using serpentine seeding.
     *
     * List position is authoritative (position 1 is the top seed, per the
     * stage entry contract); rows are dealt in a snake pattern so total
     * strength balances — with two pools, positions 1, 4, 5, 8 land in
     * pool A and 2, 3, 6, 7 in B.
     *
     * @param array<Participant> $participants In seeding order, best first
     * @return array<string, array<Participant>> Pools keyed by label ('A', 'B', ...)
     *
     * @throws InvalidConfigurationException When the pool configuration is invalid
     */
    public static function serpentine(array $participants, int $pools): array
    {
        $participants = array_values($participants);

        if ($pools < 1 || $pools > 26) {
            throw new InvalidConfigurationException(
                'Pool count must be between 1 and 26',
                ['pools' => $pools]
            );
        }

        if (count($participants) < $pools * 2) {
            throw new InvalidConfigurationException(
                'Each pool needs at least 2 participants',
                ['participant_count' => count($participants), 'pools' => $pools]
            );
        }

        $ids = array_map(fn (Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
            );
        }

        $distributed = [];
        for ($index = 0; $index < $pools; ++$index) {
            $distributed[chr(65 + $index)] = [];
        }
        $labels = array_keys($distributed);

        foreach ($participants as $position => $participant) {
            $row = intdiv($position, $pools);
            $column = $position % $pools;
            // Serpentine: odd rows deal right-to-left
            $label = $labels[$row % 2 === 0 ? $column : $pools - 1 - $column];
            $distributed[$label][] = $participant;
        }

        return $distributed;
    }

    /**
     * Split results by the pool their participants belong to.
     *
     * @param array<string, array<Participant>> $pools Pools keyed by label
     * @param array<Result> $results
     * @return array<string, array<Result>> Results keyed by pool label
     *
     * @throws InvalidConfigurationException When a result spans two pools or references a participant in no pool
     */
    public static function splitResults(array $pools, array $results): array
    {
        $poolByParticipantId = [];
        foreach ($pools as $label => $poolParticipants) {
            foreach ($poolParticipants as $participant) {
                $poolByParticipantId[$participant->getId()] = $label;
            }
        }

        /** @var array<string, array<Result>> $resultsByPool */
        $resultsByPool = array_fill_keys(array_keys($pools), []);
        foreach ($results as $result) {
            $eventPools = [];
            foreach ($result->getEvent()->getParticipants() as $participant) {
                $label = $poolByParticipantId[$participant->getId()] ?? null;
                if ($label === null) {
                    throw new InvalidConfigurationException(
                        "Result references participant {$participant->getId()} who is not in any pool",
                        ['participant' => $participant->getId()]
                    );
                }
                $eventPools[$label] = true;
            }

            if (count($eventPools) !== 1) {
                throw new InvalidConfigurationException(
                    'Result spans multiple pools: ' . implode(', ', array_keys($eventPools)),
                    ['pools' => array_keys($eventPools)]
                );
            }

            $resultsByPool[array_key_first($eventPools)][] = $result;
        }

        return $resultsByPool;
    }
}
