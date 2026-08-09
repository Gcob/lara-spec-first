<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;

/**
 * The OpenAPI minor versions this package understands.
 *
 * This is one of our own types on purpose: it is the value everything
 * downstream of the parser dispatches on, so nothing outside Parsing has to
 * touch the specification document or the parser's own representation of it.
 *
 * @see docs/OPENAPI-SUPPORT.md — "Handling 3.0 and 3.1: the version strategy"
 */
enum SpecVersion: string
{
    case V3_0 = '3.0';
    case V3_1 = '3.1';

    /**
     * Read the version out of a decoded specification document.
     *
     * The document is a plain array rather than a parsed object because
     * detection has to happen *before* the parser is handed anything: which
     * version it is decides which strategy interprets it.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws UnsupportedVersionException when the document declares a version
     *                                     this package does not implement, or
     *                                     declares none at all.
     */
    public static function detect(array $document): self
    {
        if (isset($document['swagger'])) {
            throw UnsupportedVersionException::swagger(self::describe($document['swagger']));
        }

        if (! isset($document['openapi'])) {
            throw UnsupportedVersionException::missing();
        }

        if (! is_string($document['openapi'])) {
            throw UnsupportedVersionException::notAString(get_debug_type($document['openapi']));
        }

        $declared = $document['openapi'];

        if (preg_match('/^(\d+)\.(\d+)(?:\.|$)/', $declared, $matches) !== 1) {
            throw UnsupportedVersionException::unreadable($declared);
        }

        return self::tryFrom($matches[1].'.'.$matches[2])
            ?? throw UnsupportedVersionException::unsupported($declared);
    }

    /**
     * Every version this package implements, for use in error messages.
     *
     * @return non-empty-list<string>
     */
    public static function supported(): array
    {
        return array_map(static fn (self $version): string => $version->value, self::cases());
    }

    /**
     * Render an arbitrary decoded value for an error message.
     */
    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
