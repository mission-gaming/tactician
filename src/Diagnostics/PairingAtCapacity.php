<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * A missing pairing that the constraints allow only in rounds the generated
 * schedule has already filled, as typed data
 * (DiagnosticReport::getPairingsAtCapacity()): the conflict is the arithmetic
 * of the rounds, not any single constraint.
 *
 * @experimental
 */
final readonly class PairingAtCapacity
{
    /**
     * @param string $firstParticipantId The participant listed earlier
     * @param string $secondParticipantId The participant listed later
     * @param list<int> $allowedRounds The rounds the constraints allow the pairing in,
     *                                 ascending; every one of them is full
     */
    public function __construct(
        public string $firstParticipantId,
        public string $secondParticipantId,
        public array $allowedRounds
    ) {}
}
