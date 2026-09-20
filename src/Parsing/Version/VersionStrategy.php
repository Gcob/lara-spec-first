<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * One implementation per OpenAPI minor version.
 *
 * Selected once the version is known, and consulted from there on — first to
 * reject a document whose root shape the version forbids, later to normalize
 * what it contains.
 *
 * The seam exists so that 3.0 and 3.1 disagreements stay in one file each,
 * rather than becoming version checks scattered across the parser, the router
 * and the mocker. The test of a correct seam: nothing downstream of a strategy
 * knows which version was loaded.
 *
 * Implementations must never expose the parser's own types. A future version
 * whose needs the current parser cannot meet has to be able to bring its own,
 * behind this same interface, without anything else noticing.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 * @see docs/guide/openapi-support.md — "Handling 3.0 and 3.1"
 */
interface VersionStrategy
{
    /**
     * The version this strategy interprets.
     */
    public function version(): SpecVersion;

    /**
     * Reject a document that cannot be read at this version.
     *
     * Only the root shape, and only what actually differs between versions —
     * 3.0 requires `paths`, while 3.1 accepts a document carrying only
     * `webhooks` or `components`. Everything else a document can get wrong is
     * reported rather than thrown, and belongs to the doctor.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidDocumentException
     */
    public function assertDocumentShape(array $document): void;

    /**
     * One schema node, in the form every generator reads.
     *
     * **The node arrives flattened, and that is what keeps the parser out of
     * this interface.** `$keywords` maps a keyword to its value, with every
     * nested schema already normalized into a {@see Schema} by the caller, so
     * the signature names our own types only — which is the promise the
     * interface's docblock above makes, and the one a future parser depends on.
     * Walking the document, resolving its references and cutting a
     * self-referential schema are the extractor's work; deciding what a keyword
     * *means* at this version is this method's.
     *
     * Four keywords differ between the versions and no more: `type`'s shape,
     * nullability, the two exclusive bounds, and how a file part is named.
     * Everything else is shared, and {@see NormalizesSchemas} holds it.
     *
     * @param  array<string, mixed>  $keywords
     *
     * @see docs/guide/openapi-support.md — "The normal form a schema takes"
     */
    public function normalizeSchema(array $keywords): Schema;
}
