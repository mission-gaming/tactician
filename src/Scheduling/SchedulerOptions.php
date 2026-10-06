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
 *
 * @experimental
 */
interface SchedulerOptions
{
    /**
     * Build options from plain configuration data.
     *
     * A value of the wrong type or out of range, and an identifier the
     * options do not know, fail loudly; omitted keys use the algorithm's
     * documented defaults. What happens to a key the options do not have is
     * the implementation's to say: PotDrawOptions refuses one, and
     * RoundRobinOptions and SwissOptions ignore it.
     *
     * @param array<string, mixed> $config
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public static function fromArray(array $config): static;

    /**
     * Serialize back to the plain-data form fromArray() accepts: scalar
     * values under string keys, so the array can be stored as JSON.
     *
     * @return array<string, mixed>
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the options hold
     *                                                                            something that has no
     *                                                                            plain-data form
     */
    public function toArray(): array;
}
