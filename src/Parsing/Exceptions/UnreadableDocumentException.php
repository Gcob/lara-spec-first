<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The specification file could not be turned into a document at all.
 *
 * Missing, unreadable, malformed, or decoding to something that is not a
 * mapping. Everything here happens before any OpenAPI rule applies, so it is
 * deliberately separate from the exceptions that judge a document's contents.
 */
final class UnreadableDocumentException extends RuntimeException implements SpecException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('No specification file at "%s".', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf(
            'The specification file at "%s" exists but could not be read. Check its permissions.',
            $path
        ));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(sprintf('The specification file at "%s" is not valid YAML or JSON: %s', $path, $reason));
    }

    public static function notAMapping(string $path, string $type): self
    {
        return new self(sprintf(
            'The specification file at "%s" decodes to %s, but an OpenAPI document must be a mapping '.
            'of keys at its root.',
            $path,
            $type
        ));
    }
}
