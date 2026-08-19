<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategy;

/**
 * A specification file that has cleared every check which must happen *before*
 * the OpenAPI parser sees it — and nothing beyond that.
 *
 * The name is the whole guarantee: this may be parsed. It does not say the
 * document is correct, and `$raw` says exactly what it is not.
 *
 * @see SpecDocumentReader for the checks it cleared, in the order they ran
 *
 * @internal Not public API. `$raw` in particular is a whole decoded document
 *           leaving Parsing\, which is the shape this package says it does not
 *           want to export — it survives only until Contract\ types replace it,
 *           and this tag is what keeps that replacement a non-breaking change.
 */
final readonly class ParsableSpecDocument
{
    /**
     * @param  string  $path  the file this was read from
     * @param  SpecVersion  $version  declared by the document, and implemented by this package
     * @param  VersionStrategy  $strategy  the implementation that interprets `$version`
     * @param  array<string, mixed>  $raw  the decoded document, exactly as written. Three things it
     *                                     is not, and assuming any of them is the mistake this class
     *                                     is named to prevent:
     *                                     **not validated** against the OpenAPI schema, so an
     *                                     operation may carry no responses and a parameter may be
     *                                     malformed — reporting that is `spec:doctor`'s work, which
     *                                     is a different job from refusing to load;
     *                                     **not resolved**, so every `$ref` is still a `$ref` and no
     *                                     external file has been read;
     *                                     **not normalized**, so the 3.0 and 3.1 spellings of one
     *                                     idea are both still here — `$strategy` is attached but has
     *                                     not interpreted anything yet, and producing the normalized
     *                                     form is the strategy's job from here on.
     *
     * @see docs/guide/openapi-support.md — "Handling 3.0 and 3.1: the version strategy"
     */
    public function __construct(
        public string $path,
        public SpecVersion $version,
        public VersionStrategy $strategy,
        public array $raw,
    ) {}
}
