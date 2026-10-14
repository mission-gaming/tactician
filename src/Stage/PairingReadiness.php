<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;

/**
 * Whether the next round of a stage can be paired now, and what it is
 * waiting for when it cannot: the answer of
 * HistoryReadingEngine::getReadiness().
 *
 * One of three things is true of a stage, and exactly one of them here:
 *
 * - it is ready: every recorded event has what the engine needs, and
 *   pairNextRound() gives the round with the number getRoundNumber() says;
 * - it awaits results: getUnresolvedEvents() lists the events, and
 *   pairNextRound() refuses with an UnresolvedRoundException that carries
 *   the same list. A bracket can await a decision as well: a tie that has
 *   every result, finished level and carries no decider.
 *   getUndecidedEvents() lists the events of such ties, and
 *   pairNextRound() refuses with the reason `UndecidedTie` while there is
 *   one;
 * - it is complete: there is no further round.
 *
 * Asking is how an application avoids catching an exception for a round
 * that is simply still being played.
 *
 * @experimental
 */
final readonly class PairingReadiness
{
    /**
     * @param list<Event> $unresolvedEvents
     * @param list<Event> $undecidedEvents
     */
    private function __construct(
        private bool $ready,
        private bool $complete,
        private ?int $roundNumber,
        private array $unresolvedEvents,
        private array $undecidedEvents = []
    ) {}

    /**
     * The next round can be paired.
     *
     * @param int $roundNumber The number the next round will carry
     */
    public static function ready(int $roundNumber): self
    {
        return new self(true, false, $roundNumber, []);
    }

    /**
     * The next round cannot be paired yet: a recorded round has events
     * that await a result, or ties that are level and await their decider.
     *
     * @param int $roundNumber The earliest recorded round with such an event
     * @param array<Event> $unresolvedEvents The events that await a result, in play order
     * @param array<Event> $undecidedEvents The events of the ties that have every result, are
     *                                      level and carry no decider, in play order
     */
    public static function awaitingResults(int $roundNumber, array $unresolvedEvents, array $undecidedEvents = []): self
    {
        return new self(false, false, $roundNumber, array_values($unresolvedEvents), array_values($undecidedEvents));
    }

    /**
     * The stage has no further round.
     */
    public static function complete(): self
    {
        return new self(false, true, null, []);
    }

    /**
     * Whether pairNextRound() would pair a round now.
     */
    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Whether the stage has no further round. A stage that is neither
     * ready nor complete awaits results.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Whether the stage is waiting for a recorded round: for results of it
     * (getUnresolvedEvents()), for the decision of a tie of it that
     * finished level (getUndecidedEvents()), or for both.
     */
    public function isAwaitingResults(): bool
    {
        return !$this->ready && !$this->complete;
    }

    /**
     * The round the answer is about: the number the next round will carry
     * when the stage is ready, the earliest recorded round that has an
     * event awaiting a result or a tie awaiting its decision when it is
     * not, and null when the stage is complete.
     */
    public function getRoundNumber(): ?int
    {
        return $this->roundNumber;
    }

    /**
     * The events that await a result, in play order: the objects the state
     * holds, so a result can be recorded against them. Empty when the
     * stage is ready or complete.
     *
     * @return list<Event>
     */
    public function getUnresolvedEvents(): array
    {
        return $this->unresolvedEvents;
    }

    /**
     * The events of the ties that have every result, finished level and
     * carry no decider, in play order: the objects the state holds. Each
     * awaits the application's decision, which is recorded as the decider
     * of a result of the tie (DTO\Decider; StageState::withResultReplaced()
     * puts it on a result that is already recorded). Both legs of a
     * two-legged tie are listed. Empty when the stage awaits no decision,
     * and always for a Swiss stage, which has no ties.
     *
     * @return list<Event>
     */
    public function getUndecidedEvents(): array
    {
        return $this->undecidedEvents;
    }
}
