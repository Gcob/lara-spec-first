<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The document is valid OpenAPI and uses something this package refuses to serve.
 *
 * A package limit, not a document fault — the distinction the doctor's report
 * is built on, and the reason the message never suggests the author made a
 * mistake. They did not: we are the ones who cannot serve all of it.
 *
 * @see docs/guide/doctor.md — "Two kinds of finding, never mixed"
 */
final class RejectedConstructException extends RuntimeException implements SpecException
{
    public static function componentPathItem(string $path, string $ref): self
    {
        return new self(sprintf(
            'The path "%s" refers to "%s", and this package cannot follow it. The OpenAPI parser does '.
            'not model `components.pathItems`, the field 3.1 added for reusable path items: it '.
            'resolves the reference to a plain value, keeps no operations, and reports nothing — the '.
            'endpoint would simply cease to exist. Refer to another path (`#/paths/~1health`) or to a '.
            'separate file instead; both resolve correctly.',
            $path,
            $ref
        ));
    }

    public static function traceOperation(string $path): self
    {
        return new self(sprintf(
            'The path "%s" declares a `trace` operation. Laravel has no TRACE verb — its router '.
            'knows GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS — so the operation cannot be '.
            'registered, and loading a contract while silently dropping one of its endpoints is not '.
            'something this package will do.',
            $path
        ));
    }

    /**
     * A schema keyword whose value is a schema, handed back raw, with an
     * unresolved `$ref` inside it.
     *
     * **The most dangerous shape this package reads, and the reason the refusal
     * exists rather than a warning.** The parser models neither the keyword nor
     * the reference inside it, so nothing fails: reading that pointer as though
     * it were a schema produces a value that is wrong rather than missing, and
     * nothing downstream can tell the difference. A keyword carrying data is
     * exempt, because a `$ref` there is a literal.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    public static function unresolvedReferenceInSchemaKeyword(
        string $keyword,
        string $pointer,
        string $target,
    ): self {
        return new self(sprintf(
            'The schema at "%s" writes `%s`, and there is a reference to "%s" inside it. The OpenAPI '.
            'parser does not model that keyword, so it hands the value back as a plain array with '.
            'the reference left unresolved — reading it as a schema would produce a value that is '.
            'wrong rather than one that is missing, and nothing later would fail. Inline the schema '.
            'under `%s`, or move the constraint to a keyword this package honors.',
            $pointer,
            $keyword,
            $target,
            $keyword
        ));
    }

    /**
     * `$id`, which rebases how every relative reference under it resolves.
     *
     * Ignoring it would send a reference to a target the document never named,
     * which is a wrong resolution rather than a missing one. That is why this is
     * `Rejected` rather than the `Ignored` its neighbours carry.
     */
    public static function rebasedSchemaIdentifier(string $pointer): self
    {
        return new self(sprintf(
            'The schema at "%s" declares `$id`. It rebases every relative `$ref` written under it, '.
            'and this package resolves references against the document and the file instead — so '.
            'honoring the document as written and ignoring the keyword would resolve a reference to '.
            'a target nobody named. Remove the `$id` and write each reference relative to the '.
            'document, or to the file holding it.',
            $pointer
        ));
    }

    /**
     * The four dynamic-scope keywords, which are not what a recursive schema is.
     *
     * Named together and refused together, with the message saying which of the
     * two the author probably meant: they are rare outside meta-schemas, and an
     * ordinary self-referential schema — the thing people reach for them by
     * mistake — is supported and needs none of them.
     */
    public static function dynamicReference(string $keyword, string $pointer): self
    {
        return new self(sprintf(
            'The schema at "%s" writes `%s`, one of JSON Schema 2020-12\'s dynamic-scope keywords. '.
            'This package does not resolve dynamic scope, and the keyword is rare outside '.
            'meta-schemas. If what you wanted is a schema referring back to itself — a tree, a '.
            'comment thread, nested categories — an ordinary `$ref` to it does that and is '.
            'supported.',
            $pointer,
            $keyword
        ));
    }

    /**
     * A reference aimed at a position inside a `$defs`.
     *
     * **Verified against the vendored parser, and the symptom is the one this
     * package exists against: the property holding the reference disappears.**
     * `$defs` is not modelled, so its contents are a plain array and never were
     * Schema Objects to point at; the reference resolves to a value the parser
     * cannot instantiate, and the property is dropped without an error. A
     * contract declaring three fields comes back with two.
     *
     * The keyword itself stays `Ignored`, since a definition kept there is
     * merely invisible. Only aiming at it is refused, which is the one direction
     * that turns invisible into wrong.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    public static function referenceIntoDefinitions(string $pointer, string $target): self
    {
        return new self(sprintf(
            'The reference at "%s" points at "%s", which is a position inside a `$defs`. The OpenAPI '.
            'parser does not model that keyword, so what sits there is a plain array rather than a '.
            'schema: the reference resolves to a value it cannot build a schema from, and the '.
            'property holding it is dropped without a word. Move the definition under '.
            '`components.schemas`, where a local reference reaches a modelled object.',
            $pointer,
            $target
        ));
    }
}
