<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * How much of a construct this package honors, mirroring the six levels
 * docs/guide/openapi-support.md defines. Only `Ignored` and `Rejected` ever
 * fail a run on their own; `Partial` fails only once a document leaves the
 * supported subset a `Partial` row states.
 *
 * @see docs/guide/openapi-support.md — "Support levels"
 */
enum SupportLevel: string
{
    case Supported = 'supported';
    case Partial = 'partial';
    case Ignored = 'ignored';
    case Deferred = 'deferred';
    case Rejected = 'rejected';
    case OutOfScope = 'out_of_scope';
}
