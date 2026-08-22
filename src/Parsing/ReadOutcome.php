<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserUnsafeFault;

/**
 * A specification, read end to end: what could be resolved, and every fault
 * that kept the rest of it from being honored.
 *
 * The one thing every command reads a contract for, whatever it does next.
 * `spec:build` and `spec:make` refuse the moment `$faults` is not empty;
 * `spec:doctor` reports every one of them instead. Neither reads the pipeline
 * that produced this directly — {@see Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract}
 * is the only place that assembles one — which is what keeps the two from
 * ever disagreeing about which construct this package refuses.
 *
 * `$document` is null exactly when nothing could be known about the document
 * at all — see {@see DocumentReadResult}.
 *
 * **`$operations` may describe less than the document does, and `$neutralized`
 * is how a caller knows.** A refused remote reference is removed from the raw
 * document before the parser sees it — see
 * {@see Guards\RemoteReferenceGuard::walk()} for why that is the only safe
 * option — so a request body, a parameter or a response whose schema lived
 * behind that reference is extracted without it. `spec:build` and `spec:make`
 * never meet this: they stop at the first fault and generate nothing. A caller
 * that reports rather than refuses does, and owes its reader the distinction —
 * a routing table printed from a document that was rewritten is not the routing
 * table the specification describes, and printing it beside the faults that
 * rewrote it without saying so is the one way this result can mislead.
 *
 * **Reading a result is not a substitute for a `catch`.** The pipeline no
 * longer throws for a *document* fault, which is a narrower promise than "it
 * does not throw": {@see UnusableSettingException} still comes out of
 * {@see Guards\RemoteReferenceGuard::vendor()} when `remote_references.vendor_path`
 * is unusable, because that is this package being misconfigured rather than a
 * document being wrong, and no report of a document's faults would be the right
 * place to list it. Every caller — the doctor included — needs the outer
 * `catch (SpecException)` its commands already have.
 *
 * @see docs/guide/openapi-support.md — "Reading a document"
 */
final readonly class ReadOutcome implements ReadResult
{
    /**
     * @param  list<Operation>  $operations  every operation that could be extracted despite the other faults
     * @param  list<SpecException>  $faults  every fault this read encountered, blocking or not
     * @param  bool  $neutralized  whether the document handed to the parser had a
     *                             refused reference removed from it, so
     *                             `$operations` may describe less than the
     *                             specification on disk does
     */
    public function __construct(
        public ?ParsableSpecDocument $document,
        public array $operations,
        public array $faults,
        public bool $neutralized = false,
    ) {}

    /**
     * Read a specification end to end: {@see SpecDocumentReader} first, then
     * {@see OperationExtractor} over what it produced, skipped entirely when
     * nothing could be known about the document at all.
     *
     * **The one place this assembly happens.** Every caller that needs a
     * contract read — `Console\Concerns\ReadsTheContract`, shared by every
     * command, and `Scaffolding\ExtensionInsertion`, which reads the edited
     * copy back to verify it — goes through this rather than repeating the
     * two-step call itself, which is what keeps them from ever assembling the
     * two results differently.
     *
     * @param  bool  $updateRefs  see {@see SpecDocumentReader::read()}
     *
     * @throws UnusableSettingException see the class docblock: a misconfigured
     *                                  package is not a document fault
     */
    public static function read(
        SpecDocumentReader $reader,
        string $path,
        bool $updateRefs = false,
        OperationExtractor $extractor = new OperationExtractor,
    ): self {
        $documentResult = $reader->read($path, $updateRefs);

        if ($documentResult->document === null) {
            return new self(null, [], $documentResult->faults);
        }

        // A fault the parser cannot survive — a pure reference cycle today,
        // marked as such rather than named by class, see {@see ParserUnsafeFault}
        // — exhausts memory instead of raising, which is why the guards that
        // detect one run before anything reaches it. Detecting it only ever
        // *reports* it: unlike a disallowed remote reference, nothing rewrites
        // the document to remove it, so it is never safe to hand to
        // OperationExtractor — which is the one thing in this class that
        // actually calls `cebe\openapi\` — while one is present. The document
        // itself is still returned: its version and raw content are safe to
        // read, only extracting its operations is not.
        //
        // The consequence lands on the caller, which is why it is stated here:
        // `$operations` is empty for such a document, and no amount of
        // reporting can make it otherwise, because the parser is the only thing
        // that can build an operation and it is the thing that cannot be run.
        // A caller that shows a routing table has nothing to show for a cyclic
        // document, and that is a fact about the parser rather than a choice
        // this method made.
        if (self::hasParserUnsafeFault($documentResult->faults)) {
            return new self($documentResult->document, [], $documentResult->faults, $documentResult->neutralized);
        }

        // Accepted as a parameter rather than newed up, so the assembly this
        // method calls "the one place it happens" is injectable end to end and
        // a test can observe either half of it.
        $extraction = $extractor->extract($documentResult->document);

        return new self(
            $documentResult->document,
            $extraction->operations,
            [...$documentResult->faults, ...$extraction->faults],
            $documentResult->neutralized,
        );
    }

    /**
     * @param  list<SpecException>  $faults
     */
    private static function hasParserUnsafeFault(array $faults): bool
    {
        foreach ($faults as $fault) {
            if ($fault instanceof ParserUnsafeFault) {
                return true;
            }
        }

        return false;
    }

    public function isClean(): bool
    {
        return $this->faults === [];
    }
}
