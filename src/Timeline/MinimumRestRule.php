<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * Requires an absolute duration between each participant's consecutive
 * kickoffs.
 *
 * This is a participant's rest, measured in time from one kickoff to the
 * next (the rule knows no event duration). It is not the time-aware form
 * of MinimumRestPeriodsConstraint, which is about something else: the
 * number of rounds between two meetings of the same pair. The rest is
 * added to the earlier kickoff in UTC, so DST transitions cannot shrink or
 * stretch the guaranteed rest: `P1D` and `PT24H` are both 24 hours here. A
 * rest of months or years still follows the calendar (`P1M` is 28 to 31
 * days). Any positive rest also forbids double-booking (two kickoffs at
 * the same instant violate it by definition).
 *
 * @experimental
 */
final readonly class MinimumRestRule implements TimelineRule
{
    /**
     * The longest rest, in days: some 270 billion years, the most a
     * timeline's round may be from its start
     * (IntervalArithmetic::MAX_DAYS). A longer rest added to a kickoff
     * would pass the end of the times PHP holds from any kickoff after
     * 1970.
     */
    public const int MAX_REST_DAYS = IntervalArithmetic::MAX_DAYS;

    /**
     * @param DateInterval $minimumRest The smallest allowed gap between a
     *                                  participant's consecutive kickoffs
     *
     * A rest reaches at most {@see self::MAX_REST_DAYS} days, each part
     * counted as positive and a month as 31 days. PHP adds a longer rest to
     * a kickoff without complaint and returns a time that has wrapped round
     * to before it, and the rule would then find no kickoff too close.
     *
     * @throws InvalidConfigurationException When the rest duration does not move time forward
     *                                       (reason `NonAdvancingTime`), or reaches more than
     *                                       MAX_REST_DAYS days (`ValueOutOfRange`)
     */
    public function __construct(
        private DateInterval $minimumRest
    ) {
        // Measured by its parts before it is added anywhere: a rest that is
        // too long can wrap round even from 1970
        $reference = new DateTimeImmutable('@0');
        $tooLong = IntervalArithmetic::spanInDays($minimumRest) > self::MAX_REST_DAYS;
        $end = $tooLong ? null : $reference->add($minimumRest);

        if ($end instanceof DateTimeImmutable && $end <= $reference) {
            throw new InvalidConfigurationException(
                'The minimum rest duration must move time forward',
                [],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }

        // And by what it adds to 1970, for a relative date string whose
        // parts PHP does not show
        if ($tooLong || ($end instanceof DateTimeImmutable && $end->getTimestamp() / 86400 > self::MAX_REST_DAYS)) {
            throw new InvalidConfigurationException(
                'The minimum rest is too long to be added to a kickoff: it reaches more than '
                    . number_format(self::MAX_REST_DAYS) . ' days',
                ['rest' => TimelineDefinition::formatInterval($minimumRest), 'limit_days' => self::MAX_REST_DAYS],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }
    }

    /**
     * Build from plain configuration data: ['rest' => 'PT48H']. The rest is
     * an ISO 8601 duration string, read by
     * TimelineDefinition::parseInterval(). Any other key is refused.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), or
     *                                       the duration is missing or malformed, or does not
     *                                       move time forward
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['rest'], 'a minimum rest rule');

        return new self(TimelineDefinition::parseInterval($config['rest'] ?? null, 'rest'));
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts:
     * `format_version`, the version of this form (1), then the rest as the
     * ISO 8601 duration TimelineDefinition::formatInterval() gives.
     *
     * A rest that has no such duration is refused, and never written as
     * text that fromArray() would refuse or read as another rest: one with
     * a fraction of a second, an inverted one or one with a negative part,
     * one with a part of more than 12 digits (PHP reads no more), and one
     * made by `DateInterval::createFromDateString()` from anything
     * but amounts of time (`next weekday`). The rule itself works with such
     * a rest. A rest of amounts of time from `createFromDateString()` (`48
     * hours`) or from `diff()` is written: the rule adds its rest once and
     * in UTC, where it is the same as its duration. (A timeline and a grid
     * refuse to write those too, because they add an interval in a
     * timezone and in steps: TimelineDefinition::writeInterval().)
     *
     * @return array{format_version: int, rest: string}
     *
     * @throws InvalidConfigurationException When the rest has no ISO 8601 duration that reads
     *                                       back as the same rest (reason `NotSerializable`)
     */
    public function toArray(): array
    {
        $written = TimelineDefinition::formatInterval($this->minimumRest);
        $reference = new DateTimeImmutable('@0');

        try {
            $readBack = TimelineDefinition::parseInterval($written, 'rest');
            // Compared as instants, fraction of a second included
            $readsBack = ($reference->add($readBack) <=> $reference->add($this->minimumRest)) === 0;
            $previous = null;
        } catch (InvalidConfigurationException $exception) {
            $readsBack = false;
            $previous = $exception;
        }

        if (!$readsBack) {
            throw new InvalidConfigurationException(
                'rest cannot be written as an ISO 8601 duration that reads back as the same rest',
                ['rest' => $written],
                '',
                0,
                $previous,
                reason: InvalidConfigurationReason::NotSerializable
            );
        }

        return [PlainData::VERSION_KEY => PlainData::VERSION, 'rest' => $written];
    }

    /**
     * `Minimum Rest (D)`, D being the rest as an ISO 8601 duration.
     */
    #[Override]
    public function getName(): string
    {
        return 'Minimum Rest (' . TimelineDefinition::formatInterval($this->minimumRest) . ')';
    }

    /**
     * One description for each pair of a participant's consecutive
     * kickoffs that are closer than the rest, participants in the order
     * they first appear and each one's kickoffs ascending. A kickoff
     * exactly the rest after the previous one is allowed. Participants are
     * told apart by ID. Times in the descriptions are UTC.
     */
    #[Override]
    public function validate(ScheduledSchedule $scheduled): array
    {
        /** @var array<string, array<array{kickoff: DateTimeImmutable, label: string}>> $byParticipant */
        $byParticipant = [];
        foreach ($scheduled->getScheduledEvents() as $scheduledEvent) {
            foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
                $byParticipant[$participant->getId()][] = [
                    'kickoff' => $scheduledEvent->getKickoff(),
                    'label' => $participant->getLabel(),
                ];
            }
        }

        $violations = [];
        foreach ($byParticipant as $entries) {
            usort($entries, fn(array $a, array $b): int => $a['kickoff'] <=> $b['kickoff']);

            for ($i = 1; $i < count($entries); ++$i) {
                $previous = $entries[$i - 1]['kickoff'];
                $earliestAllowed = $previous->add($this->minimumRest);
                $current = $entries[$i]['kickoff'];

                // A rest the constructor allows can still pass the end of
                // the times PHP holds from a kickoff billions of years out,
                // and PHP's sum then wraps round to before it. The rest ends
                // later than any time PHP holds, so every later kickoff is
                // inside it
                $restPassesTheEnd = $earliestAllowed <= $previous;

                if ($restPassesTheEnd || $current < $earliestAllowed) {
                    $violations[] = sprintf(
                        '%s kicks off at %s and again at %s; the minimum rest is %s.',
                        $entries[$i]['label'],
                        $previous->format('Y-m-d H:i \U\T\C'),
                        $current->format('Y-m-d H:i \U\T\C'),
                        TimelineDefinition::formatInterval($this->minimumRest)
                    );
                }
            }
        }

        return $violations;
    }
}
