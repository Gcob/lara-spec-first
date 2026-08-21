<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;

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
 * @see docs/guide/openapi-support.md — "Reading a document"
 */
final readonly class ReadOutcome
{
    /**
     * @param  list<Operation>  $operations  every operation that could be extracted despite the other faults
     * @param  list<SpecException>  $faults  every fault this read encountered, blocking or not
     */
    public function __construct(
        public ?ParsableSpecDocument $document,
        public array $operations,
        public array $faults,
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
     */
    public static function read(SpecDocumentReader $reader, string $path, bool $updateRefs = false): self
    {
        $documentResult = $reader->read($path, $updateRefs);

        if ($documentResult->document === null) {
            return new self(null, [], $documentResult->faults);
        }

        // A pure reference cycle is the one document fault the OpenAPI parser
        // cannot survive — it exhausts memory instead of raising, which is why
        // ReferenceCycleDetector runs before anything reaches it. Detecting a
        // cycle only ever *reports* it: unlike a disallowed remote reference,
        // nothing rewrites the document to remove it, so it is never safe to
        // hand to OperationExtractor — which is the one thing in this class
        // that actually calls `cebe\openapi\` — while one is present. The
        // document itself is still returned: its version and raw content are
        // safe to read, only extracting its operations is not.
        if (self::hasCycleFault($documentResult->faults)) {
            return new self($documentResult->document, [], $documentResult->faults);
        }

        $extraction = (new OperationExtractor)->extract($documentResult->document);

        return new self(
            $documentResult->document,
            $extraction->operations,
            [...$documentResult->faults, ...$extraction->faults],
        );
    }

    /**
     * @param  list<SpecException>  $faults
     */
    private static function hasCycleFault(array $faults): bool
    {
        foreach ($faults as $fault) {
            if ($fault instanceof CyclicReferenceException) {
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
