<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Shape declaration for an elimination bracket stage.
 *
 * A single elimination bracket knows its shape up front: log2(bracket
 * size) rounds and (n-1) × legsPerTie events (every tie eliminates exactly
 * one participant). A third-place tie adds one tie to the last round of a
 * field of 4 or more: the rounds are the same and the events are
 * n × legsPerTie. Double
 * elimination cannot know either — the grand final may or may not reset —
 * so both report null (unknowable), never a fabricated value.
 *
 * Brackets have no legs: getLegs() is null. Two-legged ties do not change
 * that — legsPerTie is a tie-structure fact exposed as getLegsPerTie(),
 * and conflating the two would recreate the legs/rounds overload this
 * design removed.
 *
 * @experimental
 */
final readonly class EliminationPlan implements StagePlan
{
    /**
     * The smallest field the third-place option accepts. A field of 3 has
     * one entrant who goes out before the last round, who is third without
     * a tie; a field of 2 has no third place.
     */
    public const int THIRD_PLACE_MINIMUM = 3;

    /** @var array<Participant> */
    private array $participants;

    /** @var array<string, true> */
    private array $participantIds;

    /**
     * @param array<Participant> $participants The entrants in seeding order; IDs are not checked
     *                                         for uniqueness here
     * @param string $algorithm 'single-elimination' or 'double-elimination'; anything else is rejected
     * @param int $legsPerTie Events per knockout tie (1 or 2)
     * @param bool $thirdPlace Whether the bracket was given the third-place option
     *                         (EliminationOptions::$thirdPlace). Single elimination only. A
     *                         field of 3 is accepted and has no such tie: see
     *                         hasThirdPlaceTie()
     * @throws InvalidConfigurationException When the algorithm is unknown (reason
     *                                       `UnknownIdentifier`), legsPerTie is not 1 or 2
     *                                       (`InvalidLegCount`), there are fewer than 2
     *                                       participants, or fewer than 3 with thirdPlace
     *                                       (`TooFewParticipants`), or thirdPlace is given for
     *                                       double elimination (`IncompatibleOptions`)
     */
    public function __construct(
        array $participants,
        private string $algorithm,
        private int $legsPerTie = 1,
        private bool $thirdPlace = false
    ) {
        if (!in_array($algorithm, ['single-elimination', 'double-elimination'], true)) {
            throw new InvalidConfigurationException(
                'Unknown elimination algorithm identifier',
                ['algorithm' => $algorithm, 'known' => ['single-elimination', 'double-elimination']],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        if ($legsPerTie !== 1 && $legsPerTie !== 2) {
            throw new InvalidConfigurationException(
                'Ties are played over 1 or 2 legs',
                ['legs_per_tie' => $legsPerTie],
                reason: InvalidConfigurationReason::InvalidLegCount
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Elimination brackets require at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }

        if ($thirdPlace && $algorithm !== 'single-elimination') {
            throw new InvalidConfigurationException(
                'A third-place tie is a single elimination option: the losers bracket of a double elimination'
                    . ' bracket already decides third place',
                ['algorithm' => $algorithm],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        if ($thirdPlace && count($participants) < self::THIRD_PLACE_MINIMUM) {
            throw new InvalidConfigurationException(
                'A bracket with the third-place option requires at least ' . self::THIRD_PLACE_MINIMUM . ' participants:'
                    . ' with 2 there is no third place to decide',
                ['participant_count' => count($participants), 'minimum_required' => self::THIRD_PLACE_MINIMUM],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }

        $this->participants = array_values($participants);
        $ids = [];
        foreach ($this->participants as $participant) {
            $ids[$participant->getId()] = true;
        }
        $this->participantIds = $ids;
    }

    /**
     * 'single-elimination' or 'double-elimination', as constructed.
     */
    #[Override]
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Single elimination knows its rounds (log2 of the bracket size);
     * double elimination cannot (the grand final may reset), so it
     * reports null rather than a fabricated count. A third-place tie is in
     * the last round and adds none.
     */
    #[Override]
    public function getTotalRounds(): ?int
    {
        if ($this->algorithm !== 'single-elimination') {
            return null;
        }

        return (int) log($this->getBracketSize(), 2);
    }

    /**
     * Brackets have no legs concept; always null. Two-legged ties are a
     * tie-structure fact — see getLegsPerTie().
     */
    #[Override]
    public function getLegs(): ?int
    {
        return null;
    }

    /**
     * Brackets have no legs concept; always null, as getLegs() is.
     */
    #[Override]
    public function getRoundsPerLeg(): ?int
    {
        return null;
    }

    /**
     * Events per knockout tie: 1, or 2 for two-legged ties. Both events
     * of a two-legged tie are in the same round.
     */
    public function getLegsPerTie(): int
    {
        return $this->legsPerTie;
    }

    /**
     * Whether the last round of the bracket holds a third-place tie beside
     * the final: the option was given and the field has 4 entrants or
     * more.
     *
     * A field of 3 with the option has none. Its bracket has one tie in
     * the round before the last (the top entrant has a bye there), so one
     * entrant goes out in it and is third without playing for it.
     */
    public function hasThirdPlaceTie(): bool
    {
        return $this->thirdPlace && count($this->participants) > self::THIRD_PLACE_MINIMUM;
    }

    /**
     * Single elimination: every tie eliminates exactly one participant, so
     * n-1 ties × legsPerTie events, and one tie more where the bracket has
     * a third-place tie (hasThirdPlaceTie()), which is played over the
     * same number of legs. Double elimination: null (the grand final may
     * or may not reset).
     */
    #[Override]
    public function getExpectedEventCount(): ?int
    {
        if ($this->algorithm !== 'single-elimination') {
            return null;
        }

        $ties = count($this->participants) - 1 + ($this->hasThirdPlaceTie() ? 1 : 0);

        return $ties * $this->legsPerTie;
    }

    /**
     * The entrants as a list, in the order given: the seeding order.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * The bracket size: the participant count rounded up to a power of two.
     */
    public function getBracketSize(): int
    {
        $size = 2;
        while ($size < count($this->participants)) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Checks two things of every event: that it has exactly 2
     * participants and that both are entrants of the stage. Events are
     * numbered from 1 in the messages, in schedule order.
     *
     * It does not check the bracket itself: who meets whom depends on
     * results, so the number of events, the rounds and the path through
     * the bracket are not compared with anything.
     *
     * @return array<string>
     */
    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; elimination events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            foreach ($eventParticipants as $participant) {
                if (!isset($this->participantIds[$participant->getId()])) {
                    $violations[] = sprintf(
                        'Event %d contains a participant that is not in the tournament.',
                        $index + 1
                    );
                    break;
                }
            }
        }

        return $violations;
    }
}
