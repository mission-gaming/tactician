<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * Outcome-based progression: reads winners or losers of the final round
 * from the recorded results.
 *
 * This is the knockout hand-off — a round's winners forward, its losers
 * into a repechage — and it reads recorded match outcomes with certainty,
 * never reconstructing them through points arithmetic (deriving winners
 * from points is the one practice this design treats as an error).
 *
 * Winners are emitted in bracket order (the final round's event order,
 * byes appended — a bye is a survivor who did not play), which is what
 * preserves a fixed bracket's path when the next round pairs adjacently.
 * Losers come from played events only.
 *
 * A tie that finished level (one event drawn, or two legs that do not
 * decide) is read through its recorded tie decision: the participant it
 * names is the winner and the other one the loser (see TieDecision).
 *
 * @experimental
 */
final readonly class MatchOutcomeSelector implements ProgressionSelector
{
    private const string MODE_WINNERS = 'winners';
    private const string MODE_LOSERS = 'losers';

    /**
     * @param 'winners'|'losers' $mode
     */
    private function __construct(
        private string $mode
    ) {}

    /**
     * The final round's winners plus its byes, in bracket order.
     */
    public static function winners(): self
    {
        return new self(self::MODE_WINNERS);
    }

    /**
     * The final round's losers, in bracket order — the repechage feed.
     */
    public static function losers(): self
    {
        return new self(self::MODE_LOSERS);
    }

    /**
     * Build from plain configuration data: ['mode' => 'winners'] or
     * ['mode' => 'losers'], the form toArray() gives. Any other key is
     * refused.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), or
     *                                       the mode is missing or unknown (`UnknownIdentifier`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['mode'], 'a match outcome selector');

        $mode = $config['mode'] ?? null;
        if (!in_array($mode, [self::MODE_WINNERS, self::MODE_LOSERS], true)) {
            throw new InvalidConfigurationException(
                'Unknown match outcome mode',
                ['mode' => $mode, 'known' => [self::MODE_WINNERS, self::MODE_LOSERS]],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        return new self($mode);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the mode, 'winners' or 'losers'.
     *
     * @return array{format_version: int, mode: string}
     */
    public function toArray(): array
    {
        return [PlainData::VERSION_KEY => PlainData::VERSION, 'mode' => $this->mode];
    }

    /**
     * The winners, or the losers, of the outcome's final round.
     *
     * One participant per tie, in the order the final round lists its
     * events; in winners mode the round's byes follow, in the order the
     * round lists them. A two-legged tie gives one participant, not one
     * per leg. Losers mode never includes a bye. Run over the last round
     * of a bracket, winners mode gives the one participant who took the
     * title, also where the standings rank another participant first.
     *
     * The last round of a single elimination bracket with a third-place
     * tie holds two ties. The third-place tie places its two entrants and
     * sends neither on, so it is not read in either mode: the selection
     * is the final's, one participant, as after a bracket without the
     * tie, and in whatever order the round lists its events. Places 3 and
     * 4 are a rank selection (RankRangeSelector::overall(3, 3)). The tie
     * is known by its mark and by nothing else: a tie one of whose events
     * carries BracketTie::ThirdPlace under BracketTie::METADATA_KEY is
     * left out, and it need not have a result. A round recorded with
     * events that lost the mark is read as any round is, one participant
     * for each of its two ties in the recorded order.
     *
     * Only the final round is read, from the outcome's results for that
     * round's events; the standings are not consulted. Every tie of the
     * round must be complete, and one that finished level must carry its
     * tie decision (see TieDecision).
     *
     * The selector is pairwise by nature: a winner and a loser are a
     * reading of two participants. Every event of the final round must
     * have exactly two; one with more is refused in both modes, and the
     * round is not read in part.
     *
     * @return array<Participant>
     * @throws InvalidConfigurationException When the outcome has no final round, as a pooled
     *                                       outcome has none, or its final round holds an event of
     *                                       more than two participants (`IncompatibleOutcome`); when a
     *                                       tie of the round lacks a result, or is level with no
     *                                       tie decision (`UndecidedTie`); when a tie decision or a
     *                                       leg's winner names a participant outside the tie
     *                                       (`InvalidResult`); or when an event of the round or of
     *                                       a result has no round number (`EventWithoutRoundNumber`)
     */
    #[Override]
    public function select(StageOutcome $outcome): array
    {
        $finalRound = $outcome->getFinalRound();
        if ($finalRound === null) {
            throw new InvalidConfigurationException(
                'Match outcome selection requires an outcome with a final round',
                ['mode' => $this->mode],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        // A winner and a loser are a reading of two participants. An event
        // of more is refused, never read as if it were a tie of its first two.
        foreach ($finalRound->getEvents() as $event) {
            if (count($event->getParticipants()) !== 2) {
                throw new InvalidConfigurationException(
                    'Match outcome selection reads events of two participants; the final round holds an event of '
                        . count($event->getParticipants()),
                    ['mode' => $this->mode, 'participants' => $this->participantIds($event)],
                    reason: InvalidConfigurationReason::IncompatibleOutcome
                );
            }
        }

        $resultsByEvent = $this->indexResultsByEvent($outcome);

        $selected = [];
        foreach ($this->groupEventsByTie($finalRound->getEvents()) as $tieEvents) {
            // A third-place tie places its two entrants and sends neither
            // on: it is no part of the selection, wherever the round lists it
            if ($this->isThirdPlaceTie($tieEvents)) {
                continue;
            }

            // An event keeps the keys it was given; the sides are its order
            [$first, $second] = array_values($tieEvents[0]->getParticipants());

            $legResults = [];
            foreach ($tieEvents as $event) {
                $result = $resultsByEvent[$this->eventKey($event)] ?? null;
                if ($result !== null) {
                    $legResults[] = $result;
                }
            }

            // TieDecision handles both single events and two-legged ties:
            // a level one needs a recorded tie_winner decision, and null
            // means legs are missing results.
            $advancer = TieDecision::advancer($legResults, $first, $second, count($tieEvents));
            if ($advancer === null) {
                throw new InvalidConfigurationException(
                    'Final round tie has no complete recorded result',
                    ['participants' => [$first->getId(), $second->getId()]],
                    reason: InvalidConfigurationReason::UndecidedTie
                );
            }

            if ($this->mode === self::MODE_WINNERS) {
                $selected[] = $advancer;
                continue;
            }

            $selected[] = $advancer->getId() === $first->getId() ? $second : $first;
        }

        if ($this->mode === self::MODE_WINNERS) {
            foreach ($finalRound->getByes() as $bye) {
                $selected[] = $bye;
            }
        }

        return $selected;
    }

    /**
     * Always null: the count is the number of ties in the final round,
     * plus its byes in winners mode, which only the outcome says.
     */
    #[Override]
    public function getSelectionSize(): ?int
    {
        // Depends on the final round's size
        return null;
    }

    /**
     * Group a round's events into ties: single events stand alone, and
     * two-legged ties (mirrored pairs of the same participants, annotated
     * with tie_leg metadata) group together in bracket order.
     *
     * @param array<Event> $events
     * @return array<array<Event>>
     */
    private function groupEventsByTie(array $events): array
    {
        $ties = [];
        foreach ($events as $event) {
            $ties[PairKey::of(...$this->participantIds($event))][] = $event;
        }

        return array_values($ties);
    }

    /**
     * Whether the tie is marked as the third-place tie of a bracket (see
     * BracketTie): one of its events carries the mark.
     *
     * @param array<Event> $tieEvents
     */
    private function isThirdPlaceTie(array $tieEvents): bool
    {
        foreach ($tieEvents as $event) {
            if (BracketTie::of($event) === BracketTie::ThirdPlace) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, \MissionGaming\Tactician\DTO\Result>
     * @throws InvalidConfigurationException When a result's event has no round number
     */
    private function indexResultsByEvent(StageOutcome $outcome): array
    {
        $index = [];
        foreach ($outcome->getResults() as $result) {
            $index[$this->eventKey($result->getEvent())] = $result;
        }

        return $index;
    }

    /**
     * @throws InvalidConfigurationException When the event has no round number
     */
    private function eventKey(Event $event): string
    {
        $round = $event->getRound()?->getNumber();
        if ($round === null) {
            // Selectors read recorded outcomes with certainty; a round-less
            // event cannot be matched to its result and must not silently
            // collide with other rounds
            throw new InvalidConfigurationException(
                'Match outcome selection requires events with round numbers',
                ['participants' => $this->participantIds($event)],
                reason: InvalidConfigurationReason::EventWithoutRoundNumber
            );
        }

        $leg = $event->getMetadataValue('tie_leg');

        return $round . ':' . PairKey::of(...$this->participantIds($event)) . ':' . (is_int($leg) ? $leg : 1);
    }

    /**
     * The IDs of the event's participants in the order it holds them, as a
     * list: an event keeps the array keys it was given, and a string key
     * would be spread into a call as a named argument.
     *
     * @return list<string>
     */
    private function participantIds(Event $event): array
    {
        return array_values(array_map(fn(Participant $p) => $p->getId(), $event->getParticipants()));
    }
}
