<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * A missing pairing the constraints keep out of every round of the
 * generated schedule, in both role orders, as typed data
 * (DiagnosticReport::getBlockedPairings()).
 *
 * Blocked means that the pairing cannot join the schedule that was
 * generated, not that no schedule holds it.
 *
 * @experimental
 */
final readonly class BlockedPairing
{
    /**
     * @param string $firstParticipantId The participant listed earlier
     * @param string $secondParticipantId The participant listed later
     * @param list<string> $blockingConstraints The names of the constraints that each reject
     *                                          the pairing in every round, in the order of
     *                                          the constraint set; empty where no single one
     *                                          does and only their combination blocks it
     */
    public function __construct(
        public string $firstParticipantId,
        public string $secondParticipantId,
        public array $blockingConstraints
    ) {}

    /**
     * Whether no single constraint blocks the pairing everywhere, and only a
     * combination of them does.
     */
    public function isBlockedByCombination(): bool
    {
        return $this->blockingConstraints === [];
    }
}
