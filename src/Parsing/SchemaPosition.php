<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

/**
 * Where a schema is actually written: the file holding it, and the JSON Pointer
 * to it inside that file.
 *
 * Two fields rather than one string, because the two are resolved differently.
 * A pointer is compared segment by segment against a decoded document; a file
 * is resolved against the directory of the document that referenced it. Joining
 * them into `other.yaml#/components/schemas/Pet` is a way of *displaying* a
 * position, and {@see DocumentWalk::pointer()} is the one place that happens.
 *
 * **The position is computed, never asked of the parser.** `cebe\openapi\`
 * answers `getDocumentPosition()` with the first site that referenced a node,
 * which is the root file even when the schema lives in another one. Reading a
 * name or a diagnostic out of that answer names the wrong file, so this package
 * walks the raw document alongside the resolved one and keeps its own answer.
 *
 * @internal Not public API — a detail of how the extractor tracks where it is.
 *
 * @see docs/guide/openapi-support.md — "Where a schema is reported from"
 */
final readonly class SchemaPosition
{
    /**
     * @param  string  $file  an absolute path, normalized, and the root document's
     *                        own path while the walk has not crossed a file
     * @param  string  $pointer  a JSON Pointer with no leading `#`, empty when the
     *                           position is the whole file
     */
    public function __construct(
        public string $file,
        public string $pointer,
    ) {}

    /**
     * The key this position takes in a set — an alias loop's visited list, or
     * the extractor's set of open ancestors.
     *
     * A byte no path and no pointer can contain separates the two halves, so
     * two different positions cannot collapse into one key.
     */
    public function key(): string
    {
        return $this->file."\0".$this->pointer;
    }
}
