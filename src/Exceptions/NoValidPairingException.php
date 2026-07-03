<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Exception thrown when no valid pairing exists for a Swiss round.
 *
 * Raised when repeat-pairing avoidance and constraints leave no complete
 * set of pairings for the round being generated.
 */
class NoValidPairingException extends SchedulingException
{
    /**
     * @param array<Participant> $participants
     */
    public function __construct(
        private readonly int $roundNumber,
        private readonly array $participants,
        string $message = ''
    ) {
        if ($message === '') {
            $message = sprintf(
                'No valid pairing exists for round %d with %d participants',
                $this->roundNumber,
                count($this->participants)
            );
        }

        parent::__construct($message);
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== NO VALID PAIRING DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Round: %d', $this->roundNumber);
        $report[] = sprintf('Participants: %d', count($this->participants));
        $report[] = '';
        $report[] = 'Every complete set of pairings for this round is blocked by';
        $report[] = 'repeat-pairing avoidance or a configured constraint.';
        $report[] = '';
        $report[] = '=== SUGGESTIONS ===';
        $report[] = '• Reduce the number of rounds (participants may have played everyone already)';
        $report[] = '• Relax or remove constraints blocking the remaining pairings';
        $report[] = '• Increase the number of participants';

        return implode("\n", $report);
    }
}
