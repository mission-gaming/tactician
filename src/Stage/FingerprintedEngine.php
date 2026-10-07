<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * An engine that can say which stage states are its own: it gives a
 * fingerprint to stamp a state with (StageState::withEngineFingerprint())
 * and refuses a stamped state whose fingerprint is not that one
 * (StageState::requireEngineFingerprint()).
 *
 * The library's three engines implement it. It is separate from
 * StageEngineInterface so that an engine written against that interface
 * alone keeps working; code that holds a StageEngineInterface asks before
 * it stamps:
 *
 *     if ($engine instanceof FingerprintedEngine) {
 *         $state = $state->withEngineFingerprint($engine->getFingerprint());
 *     }
 *
 * The contract of a fingerprint:
 *
 * - It is an opaque, non-empty string. Compare it for equality; do not
 *   parse it, build one by hand, or rely on how it is spelled.
 * - Two engines have the same fingerprint when they are the same format
 *   and agree on every option that shapes which rounds the format has or
 *   how they are paired. Nothing else is part of it.
 * - An option at its default value is left out. That is the rule by
 *   which an option is added within a scheme: an engine that gains an
 *   option keeps the fingerprint of every configuration that does not use
 *   the option, a state stamped before the option existed is still
 *   accepted, and the default of an option that is part of a fingerprint
 *   is not changed.
 * - It follows the rule for experimental API, and nothing more. A patch
 *   release keeps the fingerprint of every configuration, because states
 *   are stored with it; like any output, one that was itself wrong may be
 *   corrected, as an "Output change (fix)" of the changelog. A 0.x minor
 *   release may change the scheme; the changelog then has a migration
 *   note saying what a stored stamp is replaced with. The string states
 *   the version of its scheme (`tactician:v1:`), so that a later scheme
 *   can be told apart.
 *
 * Fingerprints that begin with `tactician:` are the library's. Give an
 * engine of your own a string that does not.
 *
 * @experimental
 */
interface FingerprintedEngine
{
    /**
     * The fingerprint of this engine as it is configured.
     *
     * @return non-empty-string
     */
    public function getFingerprint(): string;
}
