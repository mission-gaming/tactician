<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

/**
 * Typed per-algorithm scheduling options.
 *
 * Each scheduler accepts exactly one options type (RoundRobinOptions,
 * SwissOptions, ...) so configuration is named and typed rather than an
 * overloaded scalar: legs mean legs, rounds mean rounds, and passing the
 * wrong algorithm's options fails loudly.
 *
 * Every implementation is config-constructible: buildable from plain data
 * (fromArray()) and serializable back to it (toArray()), so config-driven
 * platforms can map stored configuration to library behaviour without
 * writing code per option.
 */
interface SchedulerOptions
{
    /**
     * Build options from plain configuration data.
     *
     * Unknown or invalid values fail loudly; omitted keys use the
     * algorithm's documented defaults.
     *
     * @param array<string, mixed> $config
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public static function fromArray(array $config): static;

    /**
     * Serialize back to the plain-data form fromArray() accepts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
