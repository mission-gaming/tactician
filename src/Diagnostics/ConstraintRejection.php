<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * What the analysis of a failed generation charges one constraint with for
 * one missing pairing, as typed data
 * (DiagnosticReport::getConstraintRejections()): the constraint rejects the
 * pairing, in both role orders, in this many of the rounds probed.
 *
 * It is a finding of the analysis, which probes the schedule that was
 * generated; the rejections recorded during generation are the
 * `ConstraintViolation` objects of the exception's violation collector.
 *
 * @experimental
 */
final readonly class ConstraintRejection
{
    /**
     * @param string $constraintName The name of the constraint (ConstraintInterface::getName())
     * @param string $firstParticipantId The participant of the pairing listed earlier
     * @param string $secondParticipantId The participant of the pairing listed later
     * @param int $roundsRejected In how many of the rounds probed the constraint rejects the
     *                            pairing in both role orders; at least 1
     * @param int $roundsProbed How many rounds were probed: every round of the plan
     */
    public function __construct(
        public string $constraintName,
        public string $firstParticipantId,
        public string $secondParticipantId,
        public int $roundsRejected,
        public int $roundsProbed
    ) {}

    /**
     * Whether the constraint rejects the pairing in every round probed.
     */
    public function rejectsEveryRound(): bool
    {
        return $this->roundsRejected === $this->roundsProbed;
    }
}
