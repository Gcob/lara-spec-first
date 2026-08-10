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
 * @see docs/DOCTOR.md — "Two kinds of finding, never mixed"
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
