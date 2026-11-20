<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * How a client writes an array inside a `deepObject` query parameter, which OpenAPI leaves undefined: the application
 * declares it in `query.deep_object_arrays`, so the generated request reads it the way its clients write it.
 *
 * @internal Not public API, the value of one configuration key.
 *
 * @see docs/guide/code-generation/request-validation.md — "Arrays inside a deepObject"
 */
enum DeepObjectArrays: string
{
    /**
     * `?filter[ids][]=1&filter[ids][]=2`, or `?filter[ids][0]=1` as PHP's `http_build_query()` writes it: the array is
     * checked as a list, with its items and its bounds.
     */
    case Brackets = 'brackets';

    /**
     * No reading is declared: the array is checked for its presence only, and a finding says so.
     */
    case Any = 'any';
}
