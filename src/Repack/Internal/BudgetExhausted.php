<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Exceptions\TacticianException;
use RuntimeException;

/**
 * Raised inside the exact packing search when the step budget runs out;
 * always caught by the packer, which falls back to the greedy path and
 * reports what is left. Never escapes the repacker.
 *
 * It implements {@see TacticianException} so that no `throw` in the library
 * is outside the marker: if a defect ever let this escape, a caller's
 * `catch (TacticianException)` would still hold.
 *
 * @internal
 */
final class BudgetExhausted extends RuntimeException implements TacticianException {}
