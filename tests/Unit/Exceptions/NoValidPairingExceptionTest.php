<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;

describe('NoValidPairingException', function (): void {
    it('reports the blocked round with actionable suggestions', function (): void {
        $exception = new NoValidPairingException(4, [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
        ]);

        expect($exception->getRoundNumber())->toBe(4);
        expect($exception->getParticipants())->toHaveCount(2);

        $report = $exception->getDiagnosticReport();
        expect($report)->toContain('NO VALID PAIRING DIAGNOSTIC REPORT');
        expect($report)->toContain('Round: 4');
        expect($report)->toContain('Participants: 2');
        expect($report)->toContain('Reduce the number of rounds');
    });
});
