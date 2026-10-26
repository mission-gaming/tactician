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
     * withdrawn by then (StageState::getWithdrawals()). For a round paired
     * before the results were all in, the results of the events it says
     * awaited one (RoundPairing::getUnresolvedWhenPaired()) are left out,
     * whenever they arrived: the round is read from the table it was
     * paired from.
     *
     * Null when the engine cannot say: it draws from a randomizer, whose
     * draw at the time is not part of the state, it finds no pairing of
     * its own for the round, or the round carries the mark of a round
     * paired early (RoundPairing::isPairedOnIncompleteResults()) and lists
     * no event as awaiting a result, so which results were in is not
     * known. An engine whose rounds no result changes (the round-robin
     * engine) needs no such list.
     *
     * It is there to be shown beside a manual round. Nothing is paired
     * from it. A withdrawal the state does not know the time of (data
     * stored by 0.2.x) is read from the recorded rounds: the entrant was in
     * the stage for every round that names it, and for none after the last
     * that does.
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
     *   the round. A marked round that lists no event as awaiting a result
     *   is one of these to the Swiss engine. The mark excuses nothing: a
     *   round paired early by hand is compared with the round the engine
     *   pairs from the same results, and is manual where the two differ.
     * - That another engine would agree. The derivation is this engine's,
     *   with its options: a round paired by an engine with other options,
     *   or by an earlier release with another pairing order, is manual to
     *   this one.
     *
     * What it does say, for a state whose adjustments and withdrawals
     * carry their rounds (every state built by the verbs): a round this
     * engine paired is not reported as manual, whatever was recorded after
     * it, a late result of an earlier round included where the round was
     * paired early with the events that awaited one listed (the engine
     * lists them), with two exceptions that StageState::withoutParticipant()
     * describes: a later round that names a participant who had withdrawn
     * before this one (the participant is then read as in the stage for
     * this round too; not by the round-robin engine, which pairs a withdrawn
     * entrant's fixtures to the end and reads the withdrawal from the round
     * it states), and a first round that was recorded after a
     * participant of it withdrew. Where a withdrawal carries no round and
     * neither the round nor a later one names the entrant, a tie that is
     * missing from the round because the entrant has withdrawn is not
     * counted as a difference, so a round drawn by hand can go
     * unreported. And an adjustment made while
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
