<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What one walk over an operation's input has produced so far.
 *
 * A rule set is built by descending nested objects and array elements, and
 * every level adds rules and findings. Threading two arrays by reference
 * through every method would say the same thing with more noise, so they
 * travel together here, for the length of one {@see RuleSetBuilder::for()}
 * call and no longer.
 *
 * @internal Not public API — the accumulator of one build of one rule set.
 */
final class RuleWalk
{
    /**
     * @var array<string, list<string|InRule>>
     */
    public array $rules = [];

    /**
     * @var list<string>
     */
    public array $findings = [];

    /**
     * Whether `multipart/form-data` is the only media type the rule set is
     * read from, which is the one body a part can be a file in.
     */
    public bool $multipart = false;

    /**
     * Where the fields being walked come from when every value in it is text
     * (`query string` or `form body`), or null for a JSON body. It is what
     * decides how a boolean is read.
     */
    public ?string $textSource = null;

    /**
     * Whether the body is read from JSON and from a form at once, which no one
     * boolean rule serves.
     */
    public bool $mixedBody = false;

    /**
     * Whether the finding about `mimetypes` refusing a file named like PHP has
     * been written, since one request says it once however many parts it has.
     */
    public bool $phpUploadNamed = false;

    /**
     * @param  string  $identity  the operation's label, for a refusal's message
     */
    public function __construct(
        public readonly string $identity,
    ) {}
}
