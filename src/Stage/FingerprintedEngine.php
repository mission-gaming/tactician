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
 * - A patch release keeps the fingerprint of every configuration, because
 *   states are stored with it; like any output, one that was itself wrong
 *   may be corrected, as an "Output change (fix)" of the changelog.
 * - It states the version of the rules that pair its configuration. A
 *   minor release that pairs a configuration differently from the same
 *   recorded state raises that version for that configuration and for no
 *   other, with a migration note in the changelog; the fingerprint of every
 *   other configuration stays as it was, so a change to one engine does not
 *   invalidate the stamps of the others (ADR 0015). In 0.3.0 Swiss and a
 *   re-seeded single elimination bracket are at version 2, a bracket on a
 *   fixed path and a double elimination bracket at 1, the strings 0.2.x
 *   wrote for them.
 * - It describes, it does not rebuild: it is not a description to build an
 *   engine from, and an engine built from the application's own
 *   configuration compares its fingerprint with the stamp. A state built
 *   from an application's rows (StageState::fromHistory()) carries no
 *   stamp; stamp it with the fingerprint the application keeps beside the
 *   stage. What an engine can check of a history itself it reports
 *   (HistoryReadingEngine::getRecordedRounds()) and does not refuse.
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
