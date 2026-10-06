<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Exception thrown when no valid pairing exists for a Swiss round.
 *
 * Raised when repeat-pairing avoidance and constraints leave no complete
 * set of pairings for the round being generated.
 *
 * @api
 */
class NoValidPairingException extends SchedulingException
{
    /**
     * @param int $roundNumber The 1-based round that could not be paired
     * @param array<Participant> $participants The participants that were to be paired
     * @param string $message The exception message; empty for one built from the round and the
     *                        number of participants
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

    /**
     * The 1-based number of the round that could not be paired.
     */
    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * The participants that were to be paired: from the Swiss engine, the
     * active participants of the stage state, in the state's order.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * The round, the number of participants and general suggestions. It
     * does not say which pairings or constraints are in the way.
     */
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
