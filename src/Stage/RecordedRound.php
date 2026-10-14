<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;

/**
 * How an engine reads one round recorded on a stage state: the round as it
 * was recorded, the round the engine would have paired in its place, and
 * the events of it that still await a result.
 *
 * A recorded round is a fact. The engine never pairs it again and never
 * refuses it for being another round than its own: where the two differ,
 * the round is reported here as manual, with the engine's own derivation
 * beside it, so that an application can show the difference and keep a
 * record of it. Every later round is derived from the round as recorded.
 *
 * @experimental
 */
final readonly class RecordedRound
{
    /**
     * @param RoundPairing $pairing The round as recorded
     * @param RoundPairing|null $derivation The round the engine would have paired from the
     *                                      history before this round, or null when it cannot
     *                                      say
     * @param bool $manual Whether the recorded round pairs other participants than the derivation
     * @param array<Event> $unresolvedEvents The events of the round that await a result
     * @param array<Event> $uncontestedEvents The events of the round that await none because an
     *                                        entrant of their tie has withdrawn
     * @param array<Event> $undecidedEvents The events of the ties of the round that have every
     *                                      result, are level and await their decider
     */
    public function __construct(
        private RoundPairing $pairing,
        private ?RoundPairing $derivation,
        private bool $manual,
        private array $unresolvedEvents = [],
        private array $uncontestedEvents = [],
        private array $undecidedEvents = []
    ) {}

    /**
     * The number of the round.
     */
    public function getRoundNumber(): int
    {
        return $this->pairing->getRoundNumber();
    }

    /**
     * The round as it is recorded on the state.
     */
    public function getPairing(): RoundPairing
    {
        return $this->pairing;
    }

    /**
     * The round the engine would have paired here from what was known
     * when the recorded round was paired: the entrant list, the rounds
     * recorded before this one and their results, the adjustments made
     * before it (Adjustment::getAfterRound()) and the entrants who had not
     * withdrawn by then (StageState::getWithdrawals()).
     *
     * Null when the engine cannot say: it draws from a randomizer, whose
     * draw at the time is not part of the state; it finds no pairing of
     * its own for the round; or the state holds an adjustment that does
     * not say when it was made, and the round is one whose pairing an
     * adjustment can change (every round of a Swiss stage, every round
     * after the first of a bracket that re-seeds).
     *
     * It is there to be shown beside a manual round. Nothing is paired
     * from it. A withdrawal the state does not know the time of is read
     * from the recorded rounds: the entrant was in the stage for every
     * round that names it, and for none after the last that does.
     */
    public function getDerivation(): ?RoundPairing
    {
        return $this->derivation;
    }

    /**
     * Whether the recorded round is not the round the engine would have
     * paired: some two participants meet in one and not in the other, or
     * another participant sits the round out.
     *
     * Roles, the order of the events, labels and metadata are not
     * compared. A bracket compares the ties only, and a tie that is not in
     * the round because an entrant of it had withdrawn when the round was
     * paired makes no difference.
     *
     * What the flag does not say:
     *
     * - That nobody drew the round by hand. A round an organiser drew that
     *   is the round the engine pairs is not manual. An application that
     *   needs to know who paired a round keeps a mark of its own.
     * - Anything, where the engine has no derivation to compare with
     *   (getDerivation() is null): the flag is then false, whoever paired
     *   the round.
     * - That another engine would agree. The derivation is this engine's,
     *   with its options: a round paired by an engine with other options,
     *   or by an earlier release with another pairing order, is manual to
     *   this one.
     *
     * What it does say, for a state whose adjustments and withdrawals
     * carry their rounds (every state built by the verbs): a round this
     * engine paired is not reported as manual, whatever was recorded after
     * it. Where a withdrawal carries no round and neither the round nor a
     * later one names the entrant, a tie that is missing from the round
     * because the entrant has withdrawn is not counted as a difference, so
     * a round drawn by hand can go unreported. And an adjustment made while
     * a round was played that was recorded only afterwards counts as known
     * when that round was paired (see StageState::withAdjustment()).
     *
     * The round is read as recorded either way.
     */
    public function isManual(): bool
    {
        return $this->manual;
    }

    /**
     * Whether the round was paired, on purpose, while an event of an
     * earlier round still awaited its result (see
     * RoundPairing::isPairedOnIncompleteResults()).
     */
    public function isPairedOnIncompleteResults(): bool
    {
        return $this->pairing->isPairedOnIncompleteResults();
    }

    /**
     * The events of the round that still await a result, in the order of
     * the round; empty when it awaits none.
     *
     * @return list<Event>
     */
    public function getUnresolvedEvents(): array
    {
        return array_values($this->unresolvedEvents);
    }

    /**
     * The events of the ties of the round that have every result, finished
     * level and carry no decider (DTO\Decider), in the order of the round:
     * the round awaits the application's decision of each as it awaits a
     * result. Both legs of a two-legged tie are listed. Only a bracket has
     * them; empty otherwise.
     *
     * @return list<Event>
     */
    public function getUndecidedEvents(): array
    {
        return array_values($this->undecidedEvents);
    }

    /**
     * Whether the round awaits nothing: no event of it awaits a result,
     * and no tie of it the decision of a level result.
     */
    public function isResolved(): bool
    {
        return $this->unresolvedEvents === [] && $this->undecidedEvents === [];
    }

    /**
     * The events of the round that have no result and await none, because
     * an entrant of their tie has withdrawn: the other entrant of the tie
     * goes on without them being played. Only a bracket has them; empty
     * otherwise.
     *
     * @return list<Event>
     */
    public function getUncontestedEvents(): array
    {
        return array_values($this->uncontestedEvents);
    }
}
