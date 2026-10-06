<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * An object was asked for a value it does not hold: the kickoff of an
 * assignment made on a grid that has no instants, the capacity of an
 * unbounded grid as a number.
 *
 * It reports a mistake in the calling code, not in its data. The object says
 * beforehand whether it holds the value (a `has...()` method the message
 * names), and code that may meet either kind of object asks first. So this is
 * not an exception to catch in the ordinary course: it extends
 * `\LogicException`, and a method that throws it stays free of a checked
 * exception for callers that never meet such an object.
 *
 * It is distinct from {@see InvariantViolationException}, which is also a
 * `\LogicException` and reports a defect in the library, and from
 * {@see InvalidConfigurationException}, which reports a configuration that
 * cannot work: an object without the value is configured correctly.
 *
 * @api
 */
final class UnavailableValueException extends \LogicException implements TacticianException {}
