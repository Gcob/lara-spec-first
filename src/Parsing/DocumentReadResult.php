<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Exceptions\SpecException;

/**
 * What SpecDocumentReader::read() found, whether or not it found everything.
 *
 * `$document` is null exactly when a fault made it impossible to know anything
 * about the document at all — unreadable bytes, an unsupported or undeclared
 * version, a root shape its version forbids. There is nothing to hand an
 * extractor in that case, and `$faults` holds exactly one entry: none of these
 * can be recovered from, so there is nothing left to keep reading for.
 *
 * Otherwise `$document` is set and `$faults` holds every reference cycle and
 * every remote reference this read could not resolve — none of them fatal to
 * the read itself, which is why more than one may appear.
 *
 * @see docs/guide/openapi-support.md — "Reading a document"
 */
final readonly class DocumentReadResult
{
    /**
     * @param  list<SpecException>  $faults
     */
    public function __construct(
        public ?ParsableSpecDocument $document,
        public array $faults,
    ) {}
}
