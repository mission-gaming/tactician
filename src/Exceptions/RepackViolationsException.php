<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\Repack\RepackOutcome;
use Override;

/**
 * Thrown by the repacker only when RepackOptions(throwOnViolations: true)
 * opted into it and the outcome carries violations or unplaced events.
 *
 * Carries the full RepackOutcome, so opting into throwing loses nothing:
 * the schedule and the itemised compromises are both on the exception.
 * The default contract returns the outcome instead of throwing — see
 * RepackOutcome for why.
 */
class RepackViolationsException extends SchedulingException
{
    public function __construct(private readonly RepackOutcome $outcome)
    {
        $violations = count($outcome->getViolations());
        $unplaced = count($outcome->getUnplaced());

        parent::__construct(
            "Repacking finished with {$violations} violation(s) and {$unplaced} unplaced event(s)"
        );
    }

    public function getOutcome(): RepackOutcome
    {
        return $this->outcome;
    }

    #[Override]
    public function getDiagnosticReport(): string
    {
        $counts = [];
        foreach ($this->outcome->getViolations() as $violation) {
            $kind = $violation->getKind()->value;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        $lines = ['Repack violations by kind:'];
        foreach ($counts as $kind => $count) {
            $lines[] = "- {$kind}: {$count}";
        }
        $lines[] = 'Unplaced events: ' . count($this->outcome->getUnplaced());
        $lines[] = 'Assigned events: ' . count($this->outcome->getAssignments());
        $lines[] = 'The full outcome is available via getOutcome().';

        return implode("\n", $lines);
    }
}
