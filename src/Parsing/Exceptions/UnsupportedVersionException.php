<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;
use RuntimeException;

/**
 * The document does not declare a version this package implements.
 *
 * Every message here names what was found and what to do about it. A rejection
 * a reader cannot act on is a support ticket.
 */
final class UnsupportedVersionException extends RuntimeException implements SpecException
{
    public static function missing(): self
    {
        return new self(
            'The document declares no "openapi" version field. '.self::expected()
        );
    }

    public static function swagger(string $declared): self
    {
        return new self(sprintf(
            'The document declares "swagger: %s", which is OpenAPI 2.x. This package reads 3.x only — '.
            'convert the document before adopting it. %s',
            $declared,
            self::expected()
        ));
    }

    public static function notAString(string $type): self
    {
        return new self(sprintf(
            'The "openapi" field must be a version string, %s given. '.
            'YAML reads an unquoted 3.1 as a number, so quote it: openapi: "3.1.0". %s',
            $type,
            self::expected()
        ));
    }

    public static function unreadable(string $declared): self
    {
        return new self(sprintf(
            'The "openapi" field reads "%s", which is not a version number. %s',
            $declared,
            self::expected()
        ));
    }

    public static function unsupported(string $declared): self
    {
        return new self(sprintf(
            'The document declares OpenAPI %s, which this package does not implement. %s',
            $declared,
            self::expected()
        ));
    }

    private static function expected(): string
    {
        return sprintf('Supported versions: %s.', implode(', ', SpecVersion::supported()));
    }
}
