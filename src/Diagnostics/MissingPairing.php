<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * One pairing without a meeting in one leg, as typed data: the IDs of its
 * two participants and the leg (DiagnosticReport::getMissingPairingDetails()).
 *
 * The participants are in the order of the participant list the report was
 * built from; after retries that list is the last rotated ordering tried.
 *
 * @experimental
 */
final readonly class MissingPairing
{
    /**
     * @param string $firstParticipantId The participant listed earlier
     * @param string $secondParticipantId The participant listed later
     * @param int $leg The 1-based leg the meeting is missing from
     */
    public function __construct(
        public string $firstParticipantId,
        public string $secondParticipantId,
        public int $leg
    ) {}
}
