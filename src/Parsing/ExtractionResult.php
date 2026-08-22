<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;

/**
 * What OperationExtractor::extract() found: every operation it could build,
 * and every fault that kept another one from joining that list.
 *
 * An operation whose own construct is refused — an unroutable verb, a
 * malformed extension, an identity already claimed by an earlier one — is
 * skipped rather than aborting every operation after it: the fault it
 * produced is recorded here instead.
 */
final readonly class ExtractionResult implements ReadResult
{
    /**
     * @param  list<Operation>  $operations  in the order the document writes them
     * @param  list<SpecException>  $faults
     */
    public function __construct(
        public array $operations,
        public array $faults,
    ) {}

    public function isClean(): bool
    {
        return $this->faults === [];
    }
}
