<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

readonly class ScheduleValidationContext
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private string $algorithmName,
        private int $rounds,
        private int $participantsPerEvent = 2,
        private array $parameters = []
    ) {
    }

    public static function forRoundRobin(int $legs, int $rounds = 0, int $participantsPerEvent = 2): self
    {
        return new self(
            'Round Robin',
            $rounds > 0 ? $rounds : $legs,
            $participantsPerEvent,
            ['legs' => $legs]
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function forAlgorithm(
        string $algorithmName,
        int $rounds,
        int $participantsPerEvent = 2,
        array $parameters = []
    ): self {
        return new self($algorithmName, $rounds, $participantsPerEvent, $parameters);
    }

    public function getAlgorithmName(): string
    {
        return $this->algorithmName;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }

    public function getParticipantsPerEvent(): int
    {
        return $this->participantsPerEvent;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function hasParameter(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}
