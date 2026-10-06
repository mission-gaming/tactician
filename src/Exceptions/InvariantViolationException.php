<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * The library reached a state its own logic rules out. No input is meant to
 * cause it: it reports a defect in the library, not a mistake by the caller,
 * and is worth a bug report with the input that produced it.
 *
 * It extends `\LogicException`, which is what these sites threw before this
 * class existed, so `catch (\LogicException)` still matches.
 *
 * @api
 */
final class InvariantViolationException extends \LogicException implements TacticianException {}
