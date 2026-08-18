<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;
use RuntimeException;

/**
 * The document declares a version this package implements, but does not have
 * the shape that version requires.
 *
 * Distinct from UnsupportedVersionException on purpose: this is a document
 * fault, not a package limit, and the two are never reported as the same thing.
 *
 * @see docs/guide/doctor.md — "Two kinds of finding, never mixed"
 */
final class InvalidDocumentException extends RuntimeException implements SpecException
{
    /**
     * @param  non-empty-list<string>  $expected  the root keys, one of which must be present
     */
    public static function missingRootKey(SpecVersion $version, array $expected): self
    {
        return new self(sprintf(
            'An OpenAPI %s document must declare %s at its root, and this one %s.',
            $version->value,
            self::orList($expected),
            count($expected) === 1 ? 'does not' : 'declares none of them'
        ));
    }

    public static function duplicateEndpoint(string $identity, string $first, string $second): self
    {
        return new self(sprintf(
            'Two operations address the same endpoint: "%s" and "%s" both resolve to `%s`. '.
            'Whatever their parameters are named, one URL cannot reach two operations.',
            $first,
            $second,
            $identity
        ));
    }

    /**
     * An extension this package defines carries a value it does not define.
     *
     * Refused rather than defaulted, and refused rather than reported only
     * because the doctor does not exist yet: a refusal can be relaxed into a
     * finding without breaking anybody, and the reverse cannot.
     *
     * @param  non-empty-list<string>  $allowed
     *
     * @see docs/guide/lifecycle.md — "The doctor rules that follow"
     */
    public static function unknownExtensionValue(string $extension, string $written, string $endpoint, array $allowed): self
    {
        return new self(sprintf(
            '`%s` on `%s` is "%s", which this package does not define. It reads %s. '.
            'An extension nothing validates is worth exactly as much as the care taken writing it.',
            $extension,
            $endpoint,
            $written,
            self::orList($allowed)
        ));
    }

    /**
     * An extension this package defines carries something that is not text.
     *
     * Its own message rather than the one above: listing allowed values would
     * answer a question the author did not ask.
     */
    public static function extensionNotAString(string $extension, string $type, string $endpoint): self
    {
        return new self(sprintf(
            '`%s` on `%s` is %s, and this package reads it as text.',
            $extension,
            $endpoint,
            $type
        ));
    }

    /**
     * @param  non-empty-list<string>  $keys
     */
    private static function orList(array $keys): string
    {
        $quoted = array_map(static fn (string $key): string => '"'.$key.'"', $keys);

        if (count($quoted) === 1) {
            return $quoted[0];
        }

        $last = array_pop($quoted);

        return implode(', ', $quoted).' or '.$last;
    }
}
