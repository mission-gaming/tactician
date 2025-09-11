<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

readonly class Participant
{
    public function __construct(
        private string $id,
        private string $label,
        private ?int $seed = null,
        private array $metadata = []
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
