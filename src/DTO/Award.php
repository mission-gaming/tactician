<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * The mark of an awarded result: the event was not played, and the result
 * was given to it by a decision of the application.
 *
 * The mark says that and nothing more. Who the result counts for is what the
 * result itself says (its winner, or nobody for an award that counts as a
 * draw), so the mark names no participant and assumes no number of sides.
 * Why the event was awarded is the application's to know: the label and the
 * reason are opaque to the library, which stores them, hands them back and
 * never reads them.
 *
 * @api
 */
readonly class Award
{
    /**
     * @param string|null $label A short name the application gives the kind of award, in its own
     *                           words; null when it gives none
     * @param string|null $reason Why this event was awarded, as free text; null when none is given
     */
    public function __construct(
        private ?string $label = null,
        private ?string $reason = null
    ) {}

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
     * Convert this mark to a serializable array: both keys, always.
     *
     * @return array{label: string|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'reason' => $this->reason,
        ];
    }

    /**
     * Recreate a mark from the array form toArray() produces. A missing
     * 'label' or 'reason' is null, so an empty array is a mark without
     * either. Any other key is refused.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidInputException When a key is unknown, or a label or reason is neither a
     *                               string nor null
     */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['label', 'reason']);
        if ($unknown !== []) {
            throw new InvalidInputException(sprintf(
                "Unknown key in award data: '%s' (the known keys are 'label', 'reason')",
                implode("', '", $unknown)
            ));
        }

        $label = $data['label'] ?? null;
        $reason = $data['reason'] ?? null;
        if (($label !== null && !is_string($label)) || ($reason !== null && !is_string($reason))) {
            throw new InvalidInputException('Award label and reason must each be a string or null');
        }

        return new self($label, $reason);
    }
}
