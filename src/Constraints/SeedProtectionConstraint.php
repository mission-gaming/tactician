<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents top-seeded participants from meeting each other early in the tournament.
 *
 * The protection window is a fraction of the stage's total rounds, read
 * from the stage plan. When the plan cannot know its total rounds up
 * front (getTotalRounds() returns null), no window can be computed and
 * the constraint is satisfied — protection is effectively off for such
 * stages rather than guessed from a fabricated round count.
 */
readonly class SeedProtectionConstraint implements ConstraintInterface
{
    public function __construct(
        private int $topSeedsToProtect,
        private float $protectionPeriod
    ) {
        if ($topSeedsToProtect < 1) {
            throw new \InvalidArgumentException('Must protect at least 1 seed');
        }
        if ($protectionPeriod < 0.0 || $protectionPeriod > 1.0) {
            throw new \InvalidArgumentException('Protection period must be between 0.0 and 1.0');
        }
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $totalRounds = $context->getPlan()->getTotalRounds();
        if ($totalRounds === null) {
            return true; // No knowable stage length, so no protection window
        }

        $participants = $event->getParticipants();
        $currentRound = $event->getRound()?->getNumber() ?? 0;

        $protectedRound = (int) ($totalRounds * $this->protectionPeriod);

        if ($currentRound > $protectedRound) {
            return true; // Protection period ended
        }

        $topSeeds = $this->getTopSeeds($context->getParticipants());
        $eventTopSeeds = array_filter($participants, fn($p) => in_array($p, $topSeeds, true));

        return count($eventTopSeeds) <= 1; // Max 1 top seed per event during protection
    }

    /**
     * The name states the protection period as a percentage of the stage's
     * rounds: `Seed Protection (top 2, 20% period)` for a period of 0.2.
     *
     * The percentage is the shortest decimal number that identifies the
     * period exactly, with the decimal point moved two places to the right:
     * 0.5 gives `50%`, 0.125 gives `12.5%`, 1.0 gives `100%` and 0.0 gives
     * `0%`. It is written in plain positional notation with `.` as the
     * decimal separator, without an exponent, a thousands separator or
     * trailing zeros. A period that has no short decimal form is written
     * with as many digits as it takes to tell it from every other period,
     * at most 17 significant ones: 1/3 gives `33.33333333333333%`, and
     * 0.1 + 0.2 gives `30.000000000000004%` where 0.3 gives `30%`.
     *
     * Two constraints therefore share a name only when they protect the same
     * number of seeds for the same period. The name is a lookup key (the
     * violation collector and the diagnostics group by it), so it does not
     * depend on the `precision` or `serialize_precision` settings or on the
     * locale.
     */
    #[\Override]
    public function getName(): string
    {
        return "Seed Protection (top {$this->topSeedsToProtect}, {$this->periodAsPercentage()}% period)";
    }

    /**
     * The protection period as a percentage, by the rule stated on getName().
     */
    private function periodAsPercentage(): string
    {
        $period = $this->protectionPeriod;

        if (is_nan($period)) {
            // The constructor's range check lets NAN through, and no decimal
            // number identifies it.
            return 'NAN';
        }

        if ($period === 0.0) {
            return '0';
        }

        // The shortest scientific form that reads back as the same float;
        // seventeen significant digits always do. sprintf() takes its digit
        // count from the format, not from an ini setting, and "%e" writes
        // "." whatever the locale ("%f" would not).
        $scientific = sprintf('%.16e', $period);
        for ($decimals = 0; $decimals < 16; ++$decimals) {
            $candidate = sprintf('%.' . $decimals . 'e', $period);
            if ((float) $candidate === $period) {
                $scientific = $candidate;
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $scientific);
        $digits = str_replace('.', '', $mantissa);

        // Multiplying by 100 is done on the digits, where it is exact: the
        // decimal point moves two places to the right.
        $integerDigits = (int) $exponent + 3;

        if ($integerDigits <= 0) {
            return '0.' . str_repeat('0', -$integerDigits) . $digits;
        }

        if ($integerDigits >= strlen($digits)) {
            return str_pad($digits, $integerDigits, '0');
        }

        return substr($digits, 0, $integerDigits) . '.' . substr($digits, $integerDigits);
    }

    /**
     * Get the top seeds from participants.
     *
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function getTopSeeds(array $participants): array
    {
        // Sort by seed (lower number = better seed)
        $seededParticipants = array_filter($participants, fn($p) => $p->getSeed() !== null);
        usort($seededParticipants, fn($a, $b) => $a->getSeed() <=> $b->getSeed());

        return array_slice($seededParticipants, 0, $this->topSeedsToProtect);
    }
}
