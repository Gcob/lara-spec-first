<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\EncodingDefaults;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * One implementation per OpenAPI minor version.
 *
 * Selected once the version is known, and consulted from there on — first to reject a document whose root shape the
 * version forbids, later to normalize what it contains.
 *
 * The seam exists so that 3.0 and 3.1 disagreements stay in one file each, rather than becoming version checks
 * scattered across the parser, the router and the mocker. The test of a correct seam: nothing downstream of a strategy
 * knows which version was loaded.
 *
 * Implementations must never expose the parser's own types. A future version whose needs the current parser cannot meet
 * has to be able to bring its own, behind this same interface, without anything else noticing.
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
     * Only the root shape, and only what actually differs between versions — 3.0 requires `paths`, while 3.1 accepts a
     * document carrying only `webhooks` or `components`. Everything else a document can get wrong is reported rather
     * than thrown, and belongs to the doctor.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidDocumentException
     */
    public function assertDocumentShape(array $document): void;

    /**
     * One schema node, in the form every generator reads.
     *
     * **The node arrives flattened, and that is what keeps the parser out of this interface.** `$keywords` maps a
     * keyword to its value, with every nested schema already normalized into a {@see Schema} by the caller, so the
     * signature names our own types only — which is the promise the interface's docblock above makes, and the one a
     * future parser depends on. Walking the document, resolving its references and cutting a self-referential schema
     * are the extractor's work; deciding what a keyword *means* at this version is this method's.
     *
     * Four keywords differ between the versions and no more: `type`'s shape, nullability, the two exclusive bounds, and
     * how a file part is named. Everything else is shared, and {@see NormalizesSchemas} holds it.
     *
     * **The name is the caller's to supply**, for the same reason: what a schema is called comes from *where* it is
     * written, and only the walk knows that. It is passed through rather than read, so a strategy never has to know one
     * file from another.
     *
     * @param  array<string, mixed>  $keywords
     * @param  string|null  $name  what the document calls this schema, null for one written inline
     * @param  string|null  $source  where that schema is written, set exactly when `$name` is
     * @param  array<string, string>  $ignored  the `Ignored` keywords this node writes, by the position of each
     *
     * @see docs/guide/openapi-support.md — "The normal form a schema takes"
     */
    public function normalizeSchema(array $keywords, ?string $name = null, ?string $source = null, array $ignored = []): Schema;

    /**
     * The keywords a schema node writes beside its `$ref`, which apply together with the schema it points at.
     *
     * JSON Schema 2020-12 reads `{$ref: Code, maxLength: 3}` as `Code` and a ceiling of three, and OpenAPI 3.1 adopted
     * it. 3.0 says the opposite: everything beside a `$ref` is ignored. So the version decides what is read, which is
     * the kind of disagreement this seam is for, and the answer is the keys themselves: the walk builds them as a
     * schema of their own.
     *
     * @param  array<array-key, mixed>  $node  a schema node as the document wrote it, a `$ref` among its keys
     * @return array<array-key, mixed> nothing at 3.0, and every key but `$ref` at 3.1
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    public function referenceSiblings(array $node): array;

    /**
     * The `contentType` a multipart part gets when its `encoding` writes none, for the kinds of part whose default
     * differs between versions.
     *
     * **A minor is read by its latest patch text, and the patch is never read.** The specification says a patch "SHOULD
     * NOT be considered by tooling" and that patches "address errors in, or provide clarifications to" the text, so
     * 3.0.x is read as 3.0.4 and 3.1.x as 3.1.1 (3.1.2 repeats its table): a 3.1.0 document gets 3.1.1's defaults. What
     * is handed down is a table, never the version, so nothing downstream learns which one was loaded.
     *
     * @see docs/guide/openapi-support.md — "The differences the strategy must absorb"
     */
    public function encodingDefaults(): EncodingDefaults;
}
