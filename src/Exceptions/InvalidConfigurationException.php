<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Exception thrown when scheduler configuration is invalid.
 *
 * This covers cases like invalid participant counts, negative leg values,
 * or other configuration errors that prevent scheduling from starting.
 */
class InvalidConfigurationException extends SchedulingException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $configurationIssue,
        private readonly array $context = [],
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf('Invalid scheduler configuration: %s', $this->configurationIssue);
        }

        parent::__construct($message, $code, $previous);
    }

    public function getConfigurationIssue(): string
    {
        return $this->configurationIssue;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Issue: %s', $this->configurationIssue);

        if (!empty($this->context)) {
            $report[] = '';
            $report[] = '=== CONFIGURATION DETAILS ===';
            foreach ($this->context as $key => $value) {
                $report[] = sprintf('• %s: %s', $key, $this->formatValue($value));
            }
        }

        $report[] = '';
        $report[] = '=== REQUIREMENTS ===';
        $report[] = $this->getRequirements();

        return implode("\n", $report);
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return sprintf('[%d items]', count($value));
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    private function getRequirements(): string
    {
        $requirements = [
            '• Participants array must contain at least 2 participants',
            '• Legs must be a positive integer (≥ 1)',
            '• All participants must have unique IDs',
            '• Constraint set must be valid',
            '• Scheduler must support the requested configuration',
        ];

        return implode("\n", $requirements);
    }
}
