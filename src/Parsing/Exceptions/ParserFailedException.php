<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;
use Throwable;

/**
 * The OpenAPI parser gave up, and we are saying so in our own words.
 *
 * Every exception `cebe\openapi\` throws extends plain `\Exception` and
 * implements nothing of ours, so left alone it would travel straight through a
 * consuming application's `catch (SpecException)` — the one promise
 * {@see SpecException} makes. An exception type is a type like any other, and
 * the boundary that keeps the parser inside `Parsing\` has to hold for the ones
 * thrown as well as the ones returned.
 *
 * The original is kept as the previous exception, so nothing is hidden from
 * whoever is debugging.
 */
final class ParserFailedException extends RuntimeException implements SpecException
{
    public static function wrap(string $path, Throwable $failure): self
    {
        return new self(
            sprintf(
                'The OpenAPI parser could not read "%s": %s',
                $path,
                $failure->getMessage()
            ),
            0,
            $failure
        );
    }
}
