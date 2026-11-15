<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;
use RuntimeException;

/**
 * The document declares a version this package implements, but does not have the shape that version requires.
 *
 * Distinct from UnsupportedVersionException on purpose: this is a document fault, not a package limit, and the two are
 * never reported as the same thing.
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
     * Refused rather than defaulted, and refused rather than reported only because the doctor does not exist yet: a
     * refusal can be relaxed into a finding without breaking anybody, and the reverse cannot.
     *
     * @param  non-empty-list<string>  $allowed
     *
     * @see docs/guide/lifecycle.md — "What the doctor enforces"
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
     * Its own message rather than the one above: listing allowed values would answer a question the author did not ask.
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
     * An extension that names a PHP class carries something that could never be one.
     *
     * Refused where the document is read rather than where the class would be generated, because the value is the name
     * itself: nothing this package does to it could make `App\Http\Controllers\User-Controller` into an identifier, so
     * there is nothing later to find out.
     *
     * @see docs/guide/controllers.md — "The contract decides what is customizable"
     */
    public static function extensionNotAClassName(string $extension, string $written, string $endpoint): self
    {
        return new self(sprintf(
            '`%s` on `%s` is "%s", which is not a class name PHP could carry. It reads a '.
            'fully-qualified name, written the way PHP writes one: `App\\Http\\Controllers\\UserController`.',
            $extension,
            $endpoint,
            $written
        ));
    }

    /**
     * `x-max-depth` carries something that is not a count of levels.
     *
     * Refused rather than read as the default, for the reason {@see self::unknownExtensionValue()} gives: a depth
     * nobody could read would leave the contract saying one thing and the generated request enforcing another.
     *
     * @param  string  $pointer  where the schema carrying it is written
     *
     * @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"
     */
    public static function extensionNotADepth(string $extension, mixed $written, string $pointer): self
    {
        return new self(sprintf(
            '`%s` on `%s` is %s, and this package reads it as a whole number of levels, zero or more: how many times '.
            'a payload may re-enter the schema.',
            $extension,
            $pointer,
            is_scalar($written) ? var_export($written, true) : get_debug_type($written)
        ));
    }

    /**
     * The keywords written beside a `$ref` that the parser cannot build a schema from.
     *
     * A fault in the document, for the operation that reaches it only. The keywords used to be dropped with the rest,
     * so a value of the wrong shape never mattered; now they are read, and it does.
     *
     * @param  string  $pointer  where the `$ref` is written
     * @param  string  $detail  what the parser said
     */
    public static function malformedKeywordBesideReference(string $pointer, string $detail): self
    {
        return new self(sprintf(
            'The `$ref` at "%s" has keywords written beside it that do not make a schema: %s.',
            $pointer,
            $detail
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
