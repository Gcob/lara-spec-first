<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;

/**
 * Turns a specification file into a ParsableSpecDocument — a document that has
 * cleared every check which must happen before the OpenAPI parser sees it.
 *
 * The order of the checks is the design, not an implementation detail:
 *
 *   1. decode        — nothing can be decided about bytes
 *   2. detect        — the version is a field inside the file, so dispatch
 *                      cannot happen any earlier
 *   3. shape         — what the version requires at the document root
 *   4. cycles        — before the parser is handed anything, because a pure
 *                      reference cycle exhausts its memory rather than raising
 *   5. remote refs   — also before: an allowed reference is vendored and
 *                      rewritten to its local committed copy here, so the
 *                      parser only ever resolves a path on disk, never a URL
 *
 * Only after all five does anything reach the OpenAPI parser. The guard has to
 * sit here rather than inside a parser wrapper: once cebe has the document, a
 * cyclic one takes the process down and there is no exception left to catch.
 *
 * @see docs/guide/openapi-support.md — "Parser caveats"
 */
final readonly class SpecDocumentReader
{
    public function __construct(
        private VersionStrategyFactory $strategies = new VersionStrategyFactory,
        private ReferenceCycleDetector $cycles = new ReferenceCycleDetector,
        private RemoteReferenceGuard $remote = new RemoteReferenceGuard,
    ) {}

    /**
     * @param  bool  $updateRefs  fetch and vendor an allowed remote reference that
     *                            is missing or already vendored — the one flag that
     *                            lets this method reach the network. See
     *                            docs/guide/remote-references.md.
     *
     * @throws UnreadableDocumentException the file is missing, unreadable or not a mapping
     * @throws Exceptions\UnsupportedVersionException the document declares a version we do not implement
     * @throws Exceptions\InvalidDocumentException the document lacks what its version requires
     * @throws Exceptions\CyclicReferenceException a reference chain never reaches content
     * @throws Exceptions\RemoteReferenceException a `$ref` names a host not on the allowlist
     * @throws Exceptions\MissingVendoredReferenceException an allowed reference has no vendored
     *                                                      copy and `$updateRefs` is false
     * @throws Exceptions\RemoteReferenceFetchException fetching or decoding a reference failed
     * @throws Exceptions\CircularRemoteReferenceException a chain of vendored references closes
     *                                                     back on a URL already being fetched
     */
    public function read(string $path, bool $updateRefs = false): ParsableSpecDocument
    {
        $data = DocumentDecoder::decode($path);
        $strategy = $this->strategies->forDocument($data);

        $strategy->assertDocumentShape($data);
        $this->cycles->assertNoCycles($data);
        $data = $this->remote->resolve($data, dirname($path), $updateRefs);

        return new ParsableSpecDocument($path, $strategy->version(), $strategy, $data);
    }
}
