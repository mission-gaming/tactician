<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * An argument or a piece of serialized data the library was given is not
 * acceptable: a value outside its allowed range passed to a constructor, an
 * array handed to `fromArray()` with a missing or malformed field, a result
 * that names a participant the event does not have.
 *
 * It extends `\InvalidArgumentException`, which is what these sites threw
 * before this class existed, so `catch (\InvalidArgumentException)` and
 * `catch (\LogicException)` still match. The message says what was wrong.
 *
 * It is distinct from {@see InvalidConfigurationException}, which reports a
 * scheduler or stage configuration that cannot work and carries a diagnostic
 * report.
 */
final class InvalidInputException extends \InvalidArgumentException implements TacticianException {}
