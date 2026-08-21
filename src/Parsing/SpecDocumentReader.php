<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;

/**
 * Turns a specification file into a DocumentReadResult — everything that could
 * be resolved before the OpenAPI parser is handed anything, and every fault
 * that kept the rest of it from being resolved.
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
 * **Steps 1-3 stop the read outright; steps 4-5 collect and continue.** The
 * first three answer "what is this document at all" — without bytes, a
 * version and a legal root shape there is nothing left to check, so the first
 * fault among them is the whole result. The last two run over a document
 * that is already known to exist and to have a legal shape, and a fault in
 * one reference or one cycle says nothing about any other, so every one of
 * them is collected rather than only the first. See {@see DocumentReadResult}.
 *
 * Nothing here throws for a document fault any more — every command that
 * reads a contract decides for itself what a fault means, from the result
 * this returns, rather than the pipeline deciding by raising. `spec:build`
 * and `spec:make` still refuse the moment `$faults` is not empty;
 * `spec:doctor` reports every one of them instead. Neither reads this class
 * directly — {@see Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract} is
 * the only place that assembles a {@see ReadOutcome} from this and from
 * {@see OperationExtractor}, which is what keeps the two from ever
 * disagreeing about which construct this package refuses.
 *
 * @see docs/guide/openapi-support.md — "Reading a document"
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
     */
    public function read(string $path, bool $updateRefs = false): DocumentReadResult
    {
        try {
            $data = DocumentDecoder::decode($path);
            $strategy = $this->strategies->forDocument($data);
            $strategy->assertDocumentShape($data);
        } catch (SpecException $fault) {
            return new DocumentReadResult(null, [$fault]);
        }

        $cycles = $this->cycles->findCycles($data);
        $remote = $this->remote->resolve($data, dirname($path), $updateRefs);

        return new DocumentReadResult(
            new ParsableSpecDocument($path, $strategy->version(), $strategy, $remote->document),
            [...$cycles, ...$remote->faults],
        );
    }
}
