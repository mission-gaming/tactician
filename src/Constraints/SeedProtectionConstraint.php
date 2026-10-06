<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents top-seeded participants from meeting each other early in the tournament.
 *
 * The protection window is a fraction of the stage's total rounds, read
 * from the stage plan. When the plan cannot know its total rounds up
 * front (getTotalRounds() returns null), no window can be computed and
 * the constraint is satisfied — protection is effectively off for such
 * stages rather than guessed from a fabricated round count.
 *
 * @experimental
 */
readonly class SeedProtectionConstraint implements ConstraintInterface
{
    /**
     * @throws InvalidInputException When no seed is protected or the period is outside 0.0 to 1.0
     */
    public function __construct(
        private int $topSeedsToProtect,
        private float $protectionPeriod
    ) {
        if ($topSeedsToProtect < 1) {
            throw new InvalidInputException('Must protect at least 1 seed');
        }
        if ($protectionPeriod < 0.0 || $protectionPeriod > 1.0) {
            throw new InvalidInputException('Protection period must be between 0.0 and 1.0');
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
     * The percentage is rounded to at most two decimal places, a half going
     * up: 0.5 gives `50%`, 0.125 gives `12.5%`, 1/3 gives `33.33%`, 2/3 gives
     * `66.67%`, 1.0 gives `100%` and 0.0 gives `0%`. It is written in plain
     * positional notation with `.` as the decimal separator, without an
     * exponent, a thousands separator, trailing zeros or a trailing decimal
     * point.
     *
     * What is rounded is the period as a decimal number: the shortest one
     * that identifies the float, which is the number as it is written in
     * code. So 0.29 gives `29%`, 0.1 + 0.2 gives `30%`, and 0.33335 gives
     * `33.34%` although the float nearest to it is slightly below the half.
     *
     * The name is a lookup key (the violation collector and the diagnostics
     * group by it), so it does not depend on the `precision` or
     * `serialize_precision` settings or on the locale. It does not identify
     * the period exactly: two constraints that protect the same number of
     * seeds share a name when their periods round to the same percentage,
     * which periods closer than 0.0001 can do. 1/3 and 0.3333 are both named
     * `33.33% period`, a period of 0.99995 or more is named `100% period`,
     * and one below 0.00005 is named `0% period`.
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
            // number states it.
            return 'NAN';
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
        $exponent = (int) $exponent;

        // The period in positional notation, as the digit before the decimal
        // point and the digits after it. A period is at most 1.0, so the
        // exponent is 0 for 1.0 and for 0.0 and negative for all others.
        $whole = $exponent < 0 ? '0' : $digits[0];
        $fraction = $exponent < 0
            ? str_repeat('0', -$exponent - 1) . $digits
            : substr($digits, 1);

        // The arithmetic is on the digits, where it is exact; on the float it
        // is not (0.29 * 100 is 28.999999999999996, and 0.00015 * 10000 is
        // below 1.5, which would round the wrong way). The first four decimal
        // places are the percentage in hundredths, and the fifth decides the
        // rounding.
        $fraction = str_pad($fraction, 5, '0');
        $hundredths = (int) ($whole . substr($fraction, 0, 4)) + ($fraction[4] >= '5' ? 1 : 0);

        return rtrim(rtrim(sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100), '0'), '.');
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
