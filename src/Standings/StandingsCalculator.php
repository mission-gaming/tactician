<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use InvalidArgumentException;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;

/**
 * Calculates an ordered standings table from recorded results.
 *
 * Entries are ranked by points, then by each configured tiebreaker in order,
 * then by score difference and score for, then by seed (seeded participants
 * first), and finally by natural-order label and ID comparison for a
 * deterministic ordering.
 */
readonly class StandingsCalculator
{
    /**
     * @param array<TiebreakerInterface> $tiebreakers Applied in order after points
     */
    public function __construct(
        private PointsSystem $pointsSystem = new PointsSystem(),
        private array $tiebreakers = []
    ) {
    }

    public function getPointsSystem(): PointsSystem
    {
        return $this->pointsSystem;
    }

    /**
     * Calculate standings for the given participants from recorded results.
     *
     * Participants without any results appear at the bottom of the table with
     * zeroed records.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     *
     * @throws InvalidArgumentException When a result references an unknown participant
     *                                  or two results reference the same event
     */
    public function calculate(array $participants, array $results): Standings
    {
        $seenEvents = [];
        foreach ($results as $result) {
            $eventId = spl_object_id($result->getEvent());
            if (isset($seenEvents[$eventId])) {
                throw new InvalidArgumentException(
                    'Two results reference the same event; each event can have only one result'
                );
            }
            $seenEvents[$eventId] = true;
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        /** @var array<string, array{played: int, wins: int, draws: int, losses: int, for: float, against: float}> $tallies */
        $tallies = [];

        foreach ($participants as $participant) {
            $participantsById[$participant->getId()] = $participant;
            $tallies[$participant->getId()] = [
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'for' => 0.0,
                'against' => 0.0,
            ];
        }

        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();

            foreach ($eventParticipants as $participant) {
                $id = $participant->getId();
                if (!isset($tallies[$id])) {
                    throw new InvalidArgumentException(
                        "Result references participant {$id} who is not in the standings"
                    );
                }

                ++$tallies[$id]['played'];
                if ($result->isDraw()) {
                    ++$tallies[$id]['draws'];
                } elseif ($result->isWinFor($participant)) {
                    ++$tallies[$id]['wins'];
                } else {
                    ++$tallies[$id]['losses'];
                }

                $ownScore = $result->getScoreFor($participant);
                if ($ownScore !== null) {
                    $tallies[$id]['for'] += (float) $ownScore;
                }

                foreach ($eventParticipants as $opponent) {
                    if ($opponent->getId() === $id) {
                        continue;
                    }
                    $opponentScore = $result->getScoreFor($opponent);
                    if ($opponentScore !== null) {
                        $tallies[$id]['against'] += (float) $opponentScore;
                    }
                }
            }
        }

        /** @var array<string, StandingEntry> $entries */
        $entries = [];
        foreach ($tallies as $id => $tally) {
            $points = $tally['wins'] * $this->pointsSystem->getWinPoints()
                + $tally['draws'] * $this->pointsSystem->getDrawPoints()
                + $tally['losses'] * $this->pointsSystem->getLossPoints();

            $entries[$id] = new StandingEntry(
                $participantsById[$id],
                $tally['played'],
                $tally['wins'],
                $tally['draws'],
                $tally['losses'],
                $points,
                $tally['for'],
                $tally['against']
            );
        }

        if ($this->tiebreakers !== []) {
            $baseEntries = $entries;
            foreach ($entries as $id => $entry) {
                $values = [];
                foreach ($this->tiebreakers as $tiebreaker) {
                    $values[$tiebreaker->getName()] = $tiebreaker->calculate(
                        $participantsById[$id],
                        $results,
                        $baseEntries
                    );
                }
                $entries[$id] = $entry->withTiebreakers($values);
            }
        }

        $ordered = array_values($entries);
        usort($ordered, function (StandingEntry $a, StandingEntry $b): int {
            $comparison = $b->getPoints() <=> $a->getPoints();
            if ($comparison !== 0) {
                return $comparison;
            }

            foreach ($this->tiebreakers as $tiebreaker) {
                $name = $tiebreaker->getName();
                $comparison = ($b->getTiebreakerValue($name) ?? 0.0) <=> ($a->getTiebreakerValue($name) ?? 0.0);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $comparison = $b->getScoreDifference() <=> $a->getScoreDifference();
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = $b->getScoreFor() <=> $a->getScoreFor();
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ($a->getParticipant()->getSeed() ?? PHP_INT_MAX) <=> ($b->getParticipant()->getSeed() ?? PHP_INT_MAX);
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strnatcasecmp($a->getParticipant()->getLabel(), $b->getParticipant()->getLabel());
            if ($comparison !== 0) {
                return $comparison;
            }

            return $a->getParticipant()->getId() <=> $b->getParticipant()->getId();
        });

        return new Standings($ordered);
    }
}
