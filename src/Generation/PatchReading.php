<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * How a `PATCH` body's required list is read when its media type does not say, which OpenAPI leaves undefined: the
 * application declares it in `requests.patch`. A body sent as `application/merge-patch+json` is partial whatever the
 * key says, since that media type is the contract's own answer.
 *
 * @internal Not public API, the value of one configuration key.
 *
 * @see docs/guide/code-generation/request-validation.md — "A partial update empties the required list"
 */
enum PatchReading: string
{
    /**
     * A `PATCH` is a partial update (RFC 5789): the schema's required list is read as empty, every property is
     * `sometimes`, and `dto()` returns the partial type.
     */
    case Partial = 'partial';

    /**
     * The schema's required list is read as written, as for a `PUT`: a `PATCH` that leaves out a required field answers
     * `422`, and `dto()` returns the full type.
     */
    case AsWritten = 'as_written';
}
