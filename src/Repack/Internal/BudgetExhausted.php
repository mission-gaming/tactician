<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use RuntimeException;

/**
 * Raised inside the exact packing search when the step budget runs out;
 * always caught by the packer, which falls back to the greedy path and
 * reports what is left. Never escapes the repacker.
 *
 * @internal
 */
final class BudgetExhausted extends RuntimeException {}
