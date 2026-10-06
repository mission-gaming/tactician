<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * JSON could not be read or written by a `fromJson()` or `toJson()` method:
 * the text is not valid JSON, or a value (in metadata, for example) has no
 * JSON representation.
 *
 * It extends `\JsonException`, which is what PHP raised at these sites before
 * this class existed, so `catch (\JsonException)` still matches. The message
 * and the code (a `JSON_ERROR_*` constant) are those of the PHP exception,
 * which is available from `getPrevious()`.
 *
 * JSON that is valid but does not describe the expected object is reported
 * by {@see InvalidInputException} instead.
 *
 * @api
 */
final class JsonConversionException extends \JsonException implements TacticianException
{
    /**
     * Wrap the exception PHP raised, keeping its message and code.
     */
    public static function from(\JsonException $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
