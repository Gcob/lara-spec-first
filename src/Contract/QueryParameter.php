<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One `in: query` parameter of an operation.
 *
 * The three other locations are not modelled: a `path` parameter is the
 * router's question and {@see PathTemplate} already carries it, while `header`
 * and `cookie` are an `Ignored` support level rather than a gap. Carrying them
 * here would be offering a value nothing is allowed to read.
 *
 * **`required` lives on the parameter, not in a schema's `required` list.**
 * They are different keywords in different places, and the method never touches
 * this one: `?notify=1` is as required on a `PATCH` as on a `PUT`.
 *
 * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
 */
final readonly class QueryParameter
{
    public function __construct(
        public string $name,
        public Schema $schema,
        public bool $required = false,
    ) {}
}
