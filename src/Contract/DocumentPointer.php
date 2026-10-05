<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * A JSON Pointer into the specification, in the one spelling this package
 * uses everywhere it names a position.
 *
 * **One implementation, because a pointer is an identity.** The generated
 * file's [source map](../../docs/guide/code-generation/generated-file-anatomy.md#the-source-map),
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
 * @see docs/guide/code-generation/generated-file-anatomy.md — "The source map"
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
     * A position in a file other than the root document — the one spelling
     * this package uses when a pointer alone would name the wrong file.
     *
     * The root document keeps the bare `#/…` form it has always had, so no
     * existing message changes; a schema written in another file gains the
     * relative path in front of it, and a reader can open what the message
     * names. Splitting a specification across files is the shape that made the
     * old answer wrong, and the walk that computes the new one is Parsing\'s:
     * this class only spells what it found.
     *
     * **A whole file is named by its path alone**, with no `#` after it. A
     * schema that *is* a file has nothing to point at inside it, and a message
     * ending in a dangling `#` reads as a string that got cut off.
     *
     * @see docs/guide/openapi-support.md — "Where a schema is reported from"
     */
    public static function inFile(string $relativePath, string $pointer): string
    {
        return $pointer === '' ? $relativePath : $relativePath.'#'.$pointer;
    }

    /**
     * The decoded node one pointer names, or null when the document has
     * nothing there or has something that is not an object.
     *
     * Deliberately the plainest possible walk: it resolves a pointer against a
     * *decoded array* and resolves nothing else on the way — a `$ref` met
     * mid-path is not followed, because a pointer whose own path runs through
     * a reference is a shape the parser would have to answer for.
     *
     * Here rather than beside either caller: the cycle detector needs it
     * before the parser runs, and the extractor's raw walk needs it during,
     * and a second copy of this loop is a second escaping convention waiting
     * to disagree with {@see self::unescape()} above it.
     *
     * Accepts both spellings of the same pointer, `#/a/b` and `/a/b`, because
     * the first is how this package writes one and the second is how a `$ref`
     * fragment arrives.
     *
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>|null
     */
    public static function nodeAt(string $pointer, array $document): ?array
    {
        $node = $document;

        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $key = self::unescape($segment);

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return is_array($node) ? $node : null;
    }

    /**
     * A `$ref` fragment as the parser reads it, which is percent-decoded.
     *
     * `cebe\openapi\` runs the fragment through `rawurldecode()` before it
     * resolves it, and a bundler such as Redocly or swagger-cli writes
     * `#/paths/~1pets~1%7Bid%7D/...` because `{` may not appear raw in a URI
     * fragment. A walk that read the fragment as written would find nothing at
     * that pointer while the parser found the schema, and the two would give one
     * schema two positions. Here, and nowhere else, so both walkers agree.
     */
    public static function fragment(string $written): string
    {
        return rawurldecode($written);
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

    /**
     * One segment, read back — the inverse of {@see self::escape()}, and the
     * half a reader needs when it has to walk a pointer into the document
     * rather than merely name a position.
     *
     * Order matters here too, and it is the mirror of the one above: `~1` is
     * replaced first, so an escaped `~1` written as `~01` comes back as the
     * literal `~1` rather than as a `/`.
     */
    public static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
