<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Thrown when the next round of a results-driven stage is asked for while
 * an event of a recorded round still awaits its result.
 *
 * The next round is paired from the results of the rounds before it: a
 * bracket's next round holds who advanced, a Swiss round follows the
 * table. An engine therefore refuses to pair it from a round that is not
 * finished, and says which events it is waiting for. This is the round
 * still being played, not a fault of the state: reading the stage
 * (`isComplete()`, `getOutcome()`, the standings, the recorded rounds)
 * does not throw for it. To learn the same without catching anything, ask
 * `Stage\HistoryReadingEngine::getReadiness()` first.
 *
 * It is an {@see InvalidConfigurationException}, so a catch clause written
 * for that class still matches. Its reason is always
 * {@see InvalidConfigurationReason::RoundPartiallyResolved}, and its
 * context holds the round under `round` and, under `unresolved`, the
 * participant IDs of each event that awaits a result.
 *
 * @api
 */
final class UnresolvedRoundException extends InvalidConfigurationException
{
    /** @var list<Event> */
    private readonly array $unresolvedEvents;

    /**
     * @param int $roundNumber The earliest recorded round that has an event awaiting a result
     * @param array<Event> $unresolvedEvents The events that await a result, in play order
     * @param string $advice What the caller can do about it, as a sentence
     */
    public function __construct(
        private readonly int $roundNumber,
        array $unresolvedEvents,
        string $advice = 'Record the remaining results before pairing the next round.'
    ) {
        $this->unresolvedEvents = array_values($unresolvedEvents);

        $count = count($this->unresolvedEvents);

        parent::__construct(
            "Round {$roundNumber} is not finished: {$count} " . ($count === 1 ? 'event awaits' : 'events await')
                . ' a result. ' . $advice,
            [
                'round' => $roundNumber,
                'unresolved' => array_map(
                    static fn(Event $event): array => array_map(
                        static fn(Participant $participant): string => $participant->getId(),
                        array_values($event->getParticipants())
                    ),
                    $this->unresolvedEvents
                ),
            ],
            reason: InvalidConfigurationReason::RoundPartiallyResolved
        );
    }

    /**
     * The number of the earliest recorded round that has an event awaiting
     * a result.
     */
    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * The events that still await a result, in play order: the objects the
     * state holds, so a result can be recorded against them.
     *
     * @return list<Event>
     */
    public function getUnresolvedEvents(): array
    {
        return $this->unresolvedEvents;
    }
}
