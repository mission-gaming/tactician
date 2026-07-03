<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Forbids kickoffs inside blackout windows.
 *
 * Windows are half-open instants [from, to): a kickoff exactly at a
 * window's end is allowed, one exactly at its start is not. Translating
 * policy into windows — international breaks, venue closures, holidays,
 * recurring patterns — is the application's job; the rule just judges
 * the assigned instants.
 */
final readonly class BlackoutRule implements TimelineRule
{
    /** @var array<array{from: DateTimeImmutable, to: DateTimeImmutable, label: string}> */
    private array $windows;

    /**
     * @param array<array{from: DateTimeImmutable, to: DateTimeImmutable, label?: string}> $windows
     *
     * @throws InvalidConfigurationException When a window is empty or inverted
     */
    public function __construct(array $windows)
    {
        if ($windows === []) {
            throw new InvalidConfigurationException(
                'A blackout rule needs at least one window',
                []
            );
        }

        $normalized = [];
        foreach ($windows as $index => $window) {
            if ($window['from'] >= $window['to']) {
                throw new InvalidConfigurationException(
                    'Blackout windows must end after they start',
                    [
                        'window' => $index,
                        'from' => $window['from']->format(DATE_ATOM),
                        'to' => $window['to']->format(DATE_ATOM),
                    ]
                );
            }

            $normalized[] = [
                'from' => $window['from']->setTimezone(new DateTimeZone('UTC')),
                'to' => $window['to']->setTimezone(new DateTimeZone('UTC')),
                'label' => $window['label'] ?? 'blackout ' . ($index + 1),
            ];
        }

        $this->windows = $normalized;
    }

    /**
     * Build from plain configuration data:
     * ['windows' => [['from' => '2026-11-09 00:00', 'to' => '2026-11-17 00:00',
     *                 'timezone' => 'Europe/London', 'label' => 'international break']]].
     *
     * Each window declares its timezone explicitly; an embedded zone that
     * contradicts it is rejected (see ZonedTime).
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the windows are malformed
     */
    public static function fromArray(array $config): self
    {
        $windowsData = $config['windows'] ?? null;
        if (!is_array($windowsData) || $windowsData === []) {
            throw new InvalidConfigurationException(
                'Blackout configuration requires a non-empty windows list',
                ['windows' => $windowsData]
            );
        }

        $windows = [];
        foreach ($windowsData as $windowData) {
            if (!is_array($windowData)) {
                throw new InvalidConfigurationException(
                    'Each blackout window must be an array',
                    ['window' => $windowData]
                );
            }

            $timezone = $windowData['timezone'] ?? null;
            $window = [
                'from' => ZonedTime::parse($windowData['from'] ?? null, $timezone, 'from'),
                'to' => ZonedTime::parse($windowData['to'] ?? null, $timezone, 'to'),
            ];

            $label = $windowData['label'] ?? null;
            if ($label !== null) {
                if (!is_string($label)) {
                    throw new InvalidConfigurationException(
                        'Blackout window labels must be strings',
                        ['label' => $label]
                    );
                }
                $window['label'] = $label;
            }

            $windows[] = $window;
        }

        return new self($windows);
    }

    /**
     * Serialize back to plain configuration data, windows in UTC.
     *
     * @return array{windows: array<int, array{from: string, to: string, timezone: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'windows' => array_map(fn (array $window) => [
                'from' => $window['from']->format('Y-m-d H:i:s'),
                'to' => $window['to']->format('Y-m-d H:i:s'),
                'timezone' => 'UTC',
                'label' => $window['label'],
            ], $this->windows),
        ];
    }

    #[Override]
    public function getName(): string
    {
        return 'Blackout Windows (' . count($this->windows) . ')';
    }

    #[Override]
    public function validate(ScheduledSchedule $scheduled): array
    {
        $violations = [];
        foreach ($scheduled->getScheduledEvents() as $scheduledEvent) {
            $kickoff = $scheduledEvent->getKickoff();

            foreach ($this->windows as $window) {
                if ($kickoff >= $window['from'] && $kickoff < $window['to']) {
                    // Events carry at least two participants but may carry
                    // more (nothing forecloses N-participant events)
                    $labels = array_map(
                        fn ($participant) => $participant->getLabel(),
                        $scheduledEvent->getEvent()->getParticipants()
                    );
                    $violations[] = sprintf(
                        '%s kicks off at %s, inside %s (%s to %s).',
                        implode(' vs ', $labels),
                        $kickoff->format('Y-m-d H:i \U\T\C'),
                        $window['label'],
                        $window['from']->format('Y-m-d H:i'),
                        $window['to']->format('Y-m-d H:i \U\T\C')
                    );
                }
            }
        }

        return $violations;
    }
}
