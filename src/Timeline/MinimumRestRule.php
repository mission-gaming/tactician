<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
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
     * @param DateInterval $minimumRest The smallest allowed gap between a
     *                                  participant's consecutive kickoffs
     *
     * @throws InvalidConfigurationException When the rest duration does not move time forward
     */
    public function __construct(
        private DateInterval $minimumRest
    ) {
        $reference = new DateTimeImmutable('@0');
        if ($reference->add($minimumRest) <= $reference) {
            throw new InvalidConfigurationException(
                'The minimum rest duration must move time forward',
                [],
                reason: InvalidConfigurationReason::NonAdvancingTime
            );
        }
    }

    /**
     * Build from plain configuration data: ['rest' => 'PT48H']. The rest is
     * an ISO 8601 duration string, read by
     * TimelineDefinition::parseInterval().
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the duration is missing or malformed, or does
     *                                       not move time forward
     */
    public static function fromArray(array $config): self
    {
        return new self(TimelineDefinition::parseInterval($config['rest'] ?? null, 'rest'));
    }

    /**
     * Serialize back to the plain-data form fromArray() accepts: the rest
     * as the ISO 8601 duration TimelineDefinition::formatInterval() gives.
     *
     * A rest that has no such duration is refused, and never written as
     * text that fromArray() would refuse or read as another rest: one with
     * a fraction of a second, an inverted one or one with a negative part,
     * and one made by `DateInterval::createFromDateString()` from anything
     * but amounts of time (`next weekday`). The rule itself works with such
     * a rest. A rest of amounts of time from `createFromDateString()` (`48
     * hours`) or from `diff()` is written: the rule adds its rest once and
     * in UTC, where it is the same as its duration. (A timeline and a grid
     * refuse to write those too, because they add an interval in a
     * timezone and in steps: TimelineDefinition::writeInterval().)
     *
     * @return array{rest: string}
     *
     * @throws InvalidConfigurationException When the rest has no ISO 8601 duration that reads
     *                                       back as the same rest (reason `NotSerializable`)
     */
    public function toArray(): array
    {
        $written = TimelineDefinition::formatInterval($this->minimumRest);
        $reference = new DateTimeImmutable('@0');

        try {
            $readsBack = $reference->add(TimelineDefinition::parseInterval($written, 'rest')) == $reference->add($this->minimumRest);
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

        return ['rest' => $written];
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

                if ($current < $earliestAllowed) {
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
