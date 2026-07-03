<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Requires an absolute duration between each participant's consecutive
 * kickoffs.
 *
 * This is rest measured in hours rather than rounds — the time-aware
 * counterpart of MinimumRestPeriodsConstraint. It compares UTC instants,
 * so DST transitions cannot shrink or stretch the guaranteed rest. Any
 * positive rest also forbids double-booking (two kickoffs at the same
 * instant violate it by definition).
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
                []
            );
        }
    }

    /**
     * Build from plain configuration data: ['rest' => 'PT48H'].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the duration is missing or malformed
     */
    public static function fromArray(array $config): self
    {
        return new self(TimelineDefinition::parseInterval($config['rest'] ?? null, 'rest'));
    }

    /**
     * @return array{rest: string}
     */
    public function toArray(): array
    {
        return ['rest' => TimelineDefinition::formatInterval($this->minimumRest)];
    }

    #[Override]
    public function getName(): string
    {
        return 'Minimum Rest (' . TimelineDefinition::formatInterval($this->minimumRest) . ')';
    }

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
            usort($entries, fn (array $a, array $b): int => $a['kickoff'] <=> $b['kickoff']);

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
