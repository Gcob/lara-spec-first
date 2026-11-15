<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * An operation whose input cannot become one rule set.
 *
 * Both shapes refused here have the same property: there is no reading under which one half is right, so choosing would
 * mean the build deciding which part of the contract to enforce. It refuses instead, which is the one answer that does
 * not silently serve something the document did not say.
 *
 * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
 */
final class ConflictingInputException extends InvalidArgumentException implements SpecException
{
    /**
     * One name declared by both the body and a query parameter.
     *
     * The reason is in the framework rather than in the contract. `Request::all()` is the input source unioned with the
     * query string and the input source wins, so the two arrive as one value with the query string's copy dropped in
     * silence. One key cannot carry two constraint sets either.
     */
    public static function fieldDeclaredTwice(string $identity, string $field): self
    {
        return new self(sprintf(
            'The operation "%s" declares "%s" both as a property of its request body and as a '.
            '`query` parameter. Laravel unions the query string into the input source and the '.
            'input source wins, so the two would arrive as one value with the query parameter '.
            'dropped in silence, and one rule key cannot carry both sets of constraints. Rename '.
            'one of them.',
            $identity,
            $field
        ));
    }

    /**
     * A body name a form delivers under another key, and a query parameter that is that key.
     *
     * PHP rewrites a top-level name of a form body (`user.name` arrives as `user_name`, `page[size]` below `page`), so
     * the query parameter and the body name are one key by the time Laravel reads them, and the same two reasons as
     * {@see self::fieldDeclaredTwice()} hold.
     */
    public static function deliveredFieldDeclaredTwice(string $identity, string $bodyName, string $queryName): self
    {
        return new self(sprintf(
            'The operation "%s" declares "%s" as a property of its request body, which a form delivers under the key "%s", '.
            'and "%s" as a `query` parameter. PHP rewrites the body name before Laravel sees it, so the two '.
            'arrive under one key, and one rule key cannot carry both sets of constraints. Rename one of them.',
            $identity,
            $bodyName,
            $queryName,
            $queryName
        ));
    }

    /**
     * Two media types this package reads, over two different schemas.
     *
     * Two media types over *one* schema are not a conflict and produce one rule set; two schemas would need two, and
     * [nothing rewrites the rule set per
     * request](../../../docs/guide/code-generation/request-validation.md#nothing-rewrites-the-rule-set).
     */
    public static function twoMediaTypeSchemas(string $identity, string $first, string $second): self
    {
        return new self(sprintf(
            'The operation "%s" declares a different schema for "%s" and for "%s". One `rules()` '.
            'cannot hold two rule sets, and nothing rewrites it per request, so the build will '.
            'not choose between them. Describe both media types with one schema, or split the '.
            'operation.',
            $identity,
            $first,
            $second
        ));
    }

    /**
     * Two `allOf` branches no payload can satisfy at once, or that one rule set cannot express together.
     *
     * The keywords written beside a `$ref` at 3.1 are one more branch of an `allOf`, so a contradiction between them
     * and the schema the `$ref` names ends here too, and the message says so.
     *
     * Refused rather than resolved by picking a branch: either choice would enforce half of what the document wrote and
     * drop the other half in silence.
     */
    public static function contradictoryAllOf(string $identity, string $where, string $what): self
    {
        return new self(sprintf(
            'The operation "%s" writes `allOf`, or keywords beside a `$ref`, under %s whose branches disagree: %s. '.
            'A payload cannot satisfy both, or one rule set cannot say both, so the build will not choose '.
            'between them. Make the branches agree.',
            $identity,
            $where,
            $what
        ));
    }
}
