<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * A decision made outside the results, where the results leave something
 * level: which participants it is for, and optionally what the application
 * calls it and why it was made.
 *
 * The library records that the decision was made and who it is for. It
 * never knows how: the method belongs to the application, and the label and
 * the reason are opaque to the library, which stores them, hands them back
 * and never reads them.
 *
 * On a result (Result::getDecider()) it decides a knockout tie that the
 * results left level, and its participants are the ones who advance from
 * the tie. They are a list, in the order the decision puts them, and the
 * type assumes no number of sides: a tie between two sends one participant
 * on, so the elimination engines require exactly one there (see
 * Stage\TieDecision), and nothing here says so.
 *
 * Given to a standings table or to a rank selector
 * (Standings\Standings::withDecisions(), and withDecisions() of
 * Stage\RankRangeSelector and Stage\BestOfRankSelector) it settles the
 * order of entries that results leave level, and its participants are
 * every one of those entries, best first. There a decision for one
 * participant orders nothing and is rejected, although the type allows it.
 *
 * A decider holds no score and changes none. The result it is on keeps the
 * scores of the event as it was played, and whatever the application keeps
 * about its own tie-break belongs in the result's metadata, where no score
 * figure of the library reads it.
 *
 * @api
 */
final readonly class Decider
{
    /** @var list<string> */
    private array $participantIds;

    /**
     * @param array<array-key, mixed> $participantIds The IDs of the participants the decision is
     *                                                for, in the order it puts them: at least
     *                                                one, each a non-empty string, none twice.
     *                                                The keys of the array are not kept
     * @param string|null $label A short name the application gives the kind of decision, in its own
     *                           words; null when it gives none
     * @param string|null $reason Why the decision went the way it did, as free text; null when none
     *                            is given
     *
     * @throws InvalidInputException When the list is empty, an entry is not a non-empty string, or
     *                               an ID is listed twice
     */
    public function __construct(
        array $participantIds,
        private ?string $label = null,
        private ?string $reason = null
    ) {
        if ($participantIds === []) {
            throw new InvalidInputException('A decider needs the ID of at least one participant it is for');
        }

        $ids = [];
        foreach ($participantIds as $participantId) {
            if (!is_string($participantId) || $participantId === '') {
                throw new InvalidInputException('Each participant of a decider must be a non-empty participant ID');
            }

            if (in_array($participantId, $ids, true)) {
                throw new InvalidInputException("A decider lists participant {$participantId} twice");
            }

            $ids[] = $participantId;
        }

        $this->participantIds = $ids;
    }

    /**
     * The IDs of the participants the decision is for, in the order given.
     * On a result these are the participants who advance from the tie; in
     * a decision for a table or a rank selection, the level entries, best
     * first.
     *
     * @return list<string>
     */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    /**
     * The label as given, or null.
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * The reason as given, or null.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Convert this decider to a serializable array: all three keys, always,
     * after `format_version`, the version of this form (1). A result writes
     * its decider without the version, as it writes every part it holds.
     *
     * @return array{format_version: int, participants: list<string>, label: string|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participants' => $this->participantIds,
            'label' => $this->label,
            'reason' => $this->reason,
        ];
    }

    /**
     * Recreate a decider from the array form toArray() produces:
     * ['participants' => ['p4'], 'label' => ..., 'reason' => ...].
     *
     * 'participants' is required. A missing 'label' or 'reason' is null.
     * Any other key is refused. That the participants exist is checked
     * where the decider is used: a result refuses one that names a
     * participant who is not in its event.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidInputException When the format version is not one this release reads, a
     *                               key is unknown, the participants are not a list of at
     *                               least one non-empty string without a repeat, or a label
     *                               or reason is neither a string nor null
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields($data, ['participants', 'label', 'reason'], 'decider data');

        $participantIds = $data['participants'] ?? null;
        if (!is_array($participantIds)) {
            throw new InvalidInputException('Decider data requires a list of participant IDs');
        }

        $label = $data['label'] ?? null;
        $reason = $data['reason'] ?? null;
        if (($label !== null && !is_string($label)) || ($reason !== null && !is_string($reason))) {
            throw new InvalidInputException('Decider label and reason must each be a string or null');
        }

        return new self($participantIds, $label, $reason);
    }
}
