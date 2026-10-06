<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * One structured compromise in a repack outcome.
 *
 * Violations are data — participant, session, kind, magnitude — never
 * pre-formatted prose: the caller renders and translates its own
 * messages, and the caller decides whether a given violation is fatal.
 *
 * @api
 */
interface RepackViolation
{
    public function getKind(): ViolationKind;

    /**
     * Serialize to plain data. Every implementation includes a 'kind' key
     * carrying the ViolationKind backing string.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
