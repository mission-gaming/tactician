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
 * - An option at its default value is left out. An engine that gains an
 *   option therefore keeps the fingerprint of every configuration that
 *   does not use the option, and a state stamped before the option existed
 *   is still accepted. The default of an option that is part of a
 *   fingerprint is never changed afterwards.
 * - For one configuration the string is the same in every later release,
 *   because states are stored with it.
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
