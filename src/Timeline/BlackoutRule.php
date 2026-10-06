<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Forbids kickoffs inside blackout windows.
 *
 * Windows are half-open instants [from, to): a kickoff exactly at a
 * window's end is allowed, one exactly at its start is not. Translating
 * policy into windows — international breaks, venue closures, holidays,
 * recurring patterns — is the application's job; the rule just judges
 * the assigned instants.
 *
 * @experimental
 */
final readonly class BlackoutRule implements TimelineRule
{
    /** @var array<array{from: DateTimeImmutable, to: DateTimeImmutable, label: string}> */
    private array $windows;

    /**
     * The bounds may be in any timezone; they are compared as instants. A
     * window without a label is labelled `blackout N`, N being its 1-based
     * place in the list. Windows may overlap; a kickoff inside two of them
     * is reported once for each.
     *
     * @param array<array{from: DateTimeImmutable, to: DateTimeImmutable, label?: string}> $windows
     *
     * @throws InvalidConfigurationException When there is no window, or a window does not end
     *                                       after it starts
     */
    public function __construct(array $windows)
    {
        if ($windows === []) {
            throw new InvalidConfigurationException(
                'A blackout rule needs at least one window',
                [],
                reason: InvalidConfigurationReason::EmptyList
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
                    ],
                    reason: InvalidConfigurationReason::NonAdvancingTime
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
     * Each window declares its timezone explicitly; a timezone or offset
     * written in `from` or `to` that is not the declared one is rejected.
     * `from` and `to` each state their date in full (year, month and day):
     * a relative string (`tomorrow`, `+1 week`), an empty one, a time of
     * day without a date or a date that does not exist is rejected. A date
     * without a time of day (`2026-11-09`) is midnight at the start of that
     * day. "Timeline Assignment" in the usage guide has the full rule.
     * `label` is optional.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the windows are malformed, or a bound does not
     *                                       state an instant by itself
     */
    public static function fromArray(array $config): self
    {
        $windowsData = $config['windows'] ?? null;
        if (!is_array($windowsData) || $windowsData === []) {
            throw new InvalidConfigurationException(
                'Blackout configuration requires a non-empty windows list',
                ['windows' => $windowsData],
                reason: is_array($windowsData) ? InvalidConfigurationReason::EmptyList : InvalidConfigurationReason::WrongValueType
            );
        }

        $windows = [];
        foreach ($windowsData as $windowData) {
            if (!is_array($windowData)) {
                throw new InvalidConfigurationException(
                    'Each blackout window must be an array',
                    ['window' => $windowData],
                    reason: InvalidConfigurationReason::WrongValueType
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
                        ['label' => $label],
                        reason: InvalidConfigurationReason::WrongValueType
                    );
                }
                $window['label'] = $label;
            }

            $windows[] = $window;
        }

        return new self($windows);
    }

    /**
     * Serialize back to plain configuration data that fromArray() reads,
     * windows in their given order. Every bound is written in UTC, to the
     * second, with `timezone` set to `UTC`: the instants are kept, the
     * timezone they were declared in is not. Every window has a label, the
     * default one where none was given.
     *
     * @return array{windows: array<int, array{from: string, to: string, timezone: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'windows' => array_map(fn(array $window) => [
                'from' => $window['from']->format('Y-m-d H:i:s'),
                'to' => $window['to']->format('Y-m-d H:i:s'),
                'timezone' => 'UTC',
                'label' => $window['label'],
            ], $this->windows),
        ];
    }

    /**
     * `Blackout Windows (N)`, N being the number of windows.
     */
    #[Override]
    public function getName(): string
    {
        return 'Blackout Windows (' . count($this->windows) . ')';
    }

    /**
     * One description for each kickoff inside a window, in the order of the
     * scheduled events and, for one event, of the windows. A kickoff at a
     * window's start is inside it; one at its end is not. Times in the
     * descriptions are UTC.
     */
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
                        fn($participant) => $participant->getLabel(),
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
