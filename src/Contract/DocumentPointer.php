<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * A JSON Pointer into the specification, in the one spelling this package
 * uses everywhere it names a position.
 *
 * **One implementation, because a pointer is an identity.** The generated
 * file's [source map](../../docs/guide/code-generation.md#the-source-map),
 * the cycle detector's chains, and every doctor finding that knows where it
 * looked all answer the same question — _where in the document is this_ —
 * and a second escaping of `~` and `/` is a second answer waiting to
 * disagree with the first. Breaking-change detection is keyed by this
 * identity too, so a finding in that comparison and a header in a generated
 * file have to name the same thing.
 *
 * Lives in `Contract\` rather than beside any one caller: `Generation\`,
 * `Parsing\` and `Doctor\` all need it, and `Contract\` is the namespace the
 * other three already consume without any of them owning it.
 *
 * @see docs/guide/code-generation.md — "The source map"
 */
final readonly class DocumentPointer
{
    /**
     * The Operation Object's own position — `#/paths/<path>/<verb>`.
     */
    public static function forOperation(Operation $operation): string
    {
        return self::forPathItem($operation->path->template).'/'.$operation->method->value;
    }

    /**
     * The Path Item Object's position — `#/paths/<path>`.
     */
    public static function forPathItem(string $template): string
    {
        return '#/paths/'.self::escape($template);
    }

    /**
     * One segment, with the two characters RFC 6901 reserves escaped.
     *
     * Order matters and is not interchangeable: `~` is replaced first, so a
     * literal `~1` in a path does not come back out as `/`. `str_replace`
     * with parallel arrays already does exactly that — it walks the search
     * list in order — which is why this is one call rather than two.
     */
    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
