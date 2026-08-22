<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

/**
 * What one step of the read pipeline found, and whether anything refused it.
 *
 * Four results have the same shape — {@see DocumentReadResult},
 * {@see ExtractionResult}, {@see Guards\RemoteResolution} and
 * {@see ReadOutcome} are each a payload beside a list of faults — and every
 * caller asks them the same first question. Asking it through this interface
 * rather than writing `->faults !== []` at each call site is what keeps the
 * answer in one place: `spec:build`, `spec:make` and the doctor all program
 * against `isClean()`, so a future result type joining the pipeline is read the
 * same way on the day it is added.
 *
 * The `$faults` property itself stays public and stays a
 * `list<Gcob\LaraSpecFirst\Exceptions\SpecException>` on every implementation —
 * an interface cannot declare a property, and turning it into an accessor for
 * the sake of this one would cost every reader of a result more than it buys.
 */
interface ReadResult
{
    /**
     * Nothing was refused: there are no faults at all.
     *
     * Note what this does *not* say. A clean result is a document this pipeline
     * could read end to end — not a document that is correct, and not one that
     * has been validated against the OpenAPI schema.
     */
    public function isClean(): bool;
}
