<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;

/**
 * Interprets OpenAPI 3.0.x.
 */
final class OpenApi30Strategy implements VersionStrategy
{
    use NormalizesSchemas;

    /**
     * Root keys an OpenAPI 3.0 document must declare.
     *
     * `paths` is required at this version, so a 3.0 document that registers no
     * routes still has to say so with an empty `paths` object.
     */
    private const REQUIRED_ROOT_KEYS = ['paths'];

    public function version(): SpecVersion
    {
        return SpecVersion::V3_0;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function assertDocumentShape(array $document): void
    {
        foreach (self::REQUIRED_ROOT_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                return;
            }
        }

        throw InvalidDocumentException::missingRootKey($this->version(), self::REQUIRED_ROOT_KEYS);
    }

    /**
     * `type` is one string at 3.0, and `nullable: true` beside it is how the
     * version spells a value that may also be null.
     *
     * Both reach the same list the 3.1 strategy builds, which is the point:
     * `type: string` with `nullable: true` and `type: [string, "null"]` are one
     * contract written twice.
     *
     * A `nullable: true` with no `type` beside it adds nothing. It claims a
     * value may be null while saying nothing about what else it may be, and a
     * list holding only `null` would read as "this must be null", which is a
     * constraint the document did not write.
     *
     * @param  array<string, mixed>  $keywords
     * @return list<SchemaType>
     */
    protected function types(array $keywords): array
    {
        $types = $this->knownTypes([$keywords['type'] ?? null]);

        // DECISION: a `nullable: true` with no `type` beside it is dropped, and
        // it is the one place a 3.0 document loses something its author wrote.
        // The alternative is a list holding only `null`, which reads as "this
        // must be null" — a constraint nobody wrote, and a wrong value rather
        // than a missing one.
        if ($types !== [] && ($keywords['nullable'] ?? null) === true) {
            $types[] = SchemaType::Null;
        }

        return $types;
    }

    /**
     * At 3.0, `exclusiveMinimum` is a boolean modifying the `minimum` beside it.
     *
     * Normalized to the 3.1 spelling, so the bound moves: `minimum: 0` with
     * `exclusiveMinimum: true` becomes an exclusive bound of 0 and no inclusive
     * one. Written `false`, or written without a bound to modify, it says
     * nothing and the inclusive bound stays where it is.
     *
     * @param  array<string, mixed>  $keywords
     * @param  'Minimum'|'Maximum'  $side
     * @return array{0: float|null, 1: float|null}
     */
    protected function exclusiveBound(array $keywords, string $side): array
    {
        $bound = $this->number($keywords, strtolower($side));
        $exclusive = ($keywords['exclusive'.$side] ?? null) === true;

        return $exclusive && $bound !== null ? [$bound, null] : [null, $bound];
    }

    /**
     * `format: binary` leaves nothing behind once it has been read.
     *
     * It is not a format a generator ever looks up — it is how 3.0 says "this
     * is a file", which {@see self::isFilePart()} has already carried. Leaving
     * it in `format` as well would be one fact in two places, and it would make
     * a 3.0 schema differ from its 3.1 twin over a keyword the newer version
     * does not have.
     *
     * @param  array<string, mixed>  $keywords
     */
    protected function format(array $keywords): ?string
    {
        return $this->isFilePart($keywords) ? null : $this->string($keywords, 'format');
    }

    /**
     * 3.0 names a file part with `format: binary`, the JSON Schema format
     * vocabulary 3.1 dropped.
     *
     * @param  array<string, mixed>  $keywords
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    protected function isFilePart(array $keywords): bool
    {
        return ($keywords['format'] ?? null) === 'binary';
    }
}
