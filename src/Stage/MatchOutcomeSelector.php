<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
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
 */
final readonly class MatchOutcomeSelector implements ProgressionSelector
{
    private const MODE_WINNERS = 'winners';
    private const MODE_LOSERS = 'losers';

    /**
     * @param 'winners'|'losers' $mode
     */
    private function __construct(
        private string $mode
    ) {
    }

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
     * Build from plain configuration data: ['mode' => 'winners'].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the mode is unknown
     */
    public static function fromArray(array $config): self
    {
        $mode = $config['mode'] ?? null;
        if (!in_array($mode, [self::MODE_WINNERS, self::MODE_LOSERS], true)) {
            throw new InvalidConfigurationException(
                'Unknown match outcome mode',
                ['mode' => $mode, 'known' => [self::MODE_WINNERS, self::MODE_LOSERS]]
            );
        }

        return new self($mode);
    }

    /**
     * @return array{mode: string}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode];
    }

    #[Override]
    public function select(StageOutcome $outcome): array
    {
        $finalRound = $outcome->getFinalRound();
        if ($finalRound === null) {
            throw new InvalidConfigurationException(
                'Match outcome selection requires an outcome with a final round',
                ['mode' => $this->mode]
            );
        }

        $resultsByEvent = $this->indexResultsByEvent($outcome);

        $selected = [];
        foreach ($this->groupEventsByTie($finalRound->getEvents()) as $tieEvents) {
            [$first, $second] = $tieEvents[0]->getParticipants();

            $legResults = [];
            foreach ($tieEvents as $event) {
                $result = $resultsByEvent[$this->eventKey($event)] ?? null;
                if ($result !== null) {
                    $legResults[] = $result;
                }
            }

            // TieDecision handles both single events (a draw is an error)
            // and two-legged ties (level aggregates need a recorded
            // tie_winner decision); null means legs are missing results.
            $advancer = TieDecision::advancer($legResults, $first, $second, count($tieEvents));
            if ($advancer === null) {
                throw new InvalidConfigurationException(
                    'Final round tie has no complete recorded result',
                    ['participants' => [$first->getId(), $second->getId()]]
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
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $ties[implode('|', $ids)][] = $event;
        }

        return array_values($ties);
    }

    /**
     * @return array<string, \MissionGaming\Tactician\DTO\Result>
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
                ['participants' => array_map(fn (Participant $p) => $p->getId(), $event->getParticipants())]
            );
        }

        $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);

        $leg = $event->getMetadataValue('tie_leg');

        return $round . ':' . implode('|', $ids) . ':' . (is_int($leg) ? $leg : 1);
    }
}
