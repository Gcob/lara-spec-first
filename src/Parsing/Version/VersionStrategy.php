<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

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
 * @see docs/OPENAPI-SUPPORT.md — "Handling 3.0 and 3.1: the version strategy"
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
}
