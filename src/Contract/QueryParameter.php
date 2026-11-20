<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One `in: query` parameter of an operation.
 *
 * The three other locations are not modelled: a `path` parameter is the router's question and {@see PathTemplate}
 * already carries it, while `header` and `cookie` are an `Ignored` support level rather than a gap. Carrying them here
 * would be offering a value nothing is allowed to read.
 *
 * **`required` lives on the parameter, not in a schema's `required` list.** They are different keywords in different
 * places, and the method never touches this one: `?notify=1` is as required on a `PATCH` as on a `PUT`.
 *
 * **`style` and `explode` say how the value is written in the query string.** The defaults are OpenAPI's for `query`
 * (`form`, with `explode` true), so a parameter built without them keeps its meaning. A document that writes
 * `style: deepObject` alone gets `explode: false` from the parser, and one that writes a value OpenAPI does not define
 * (a style that is not a string or not one of the four a query takes, an `explode` that is not a boolean) is carried as
 * written: judging it is the rule set builder's, which reports it as a fault in the document rather than reading a
 * default the document never wrote.
 *
 * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
 */
final readonly class QueryParameter
{
    public function __construct(
        public string $name,
        public Schema $schema,
        public bool $required = false,
        public mixed $style = 'form',
        public mixed $explode = true,
    ) {}
}
