<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use Throwable;

/**
 * Marks every exception the library throws on purpose.
 *
 * `catch (TacticianException $e)` catches all of them with one clause: the
 * scheduling failures under {@see SchedulingException}, rejected arguments and
 * malformed data ({@see InvalidInputException}), JSON that cannot be read or
 * written ({@see JsonConversionException}) and a broken internal invariant
 * ({@see InvariantViolationException}).
 *
 * The interface adds no method. Each implementing class keeps the parent type
 * it had before the interface existed (`\Exception`, `\InvalidArgumentException`,
 * `\JsonException`, `\LogicException`), so a catch clause written against one
 * of those types still matches.
 *
 * Not covered, because the library does not report them: an exception raised
 * by a callable or a collaborator the caller supplied (a constraint predicate,
 * the engine of a `Random\Randomizer`), and PHP's own `\Error` family
 * (`\TypeError` for an argument of the wrong type, `\AssertionError`).
 */
interface TacticianException extends Throwable {}
