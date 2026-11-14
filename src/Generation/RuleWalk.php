<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What one walk over an operation's input has produced so far.
 *
 * A rule set is built by descending nested objects and array elements, and every level adds rules and findings.
 * Threading two arrays by reference through every method would say the same thing with more noise, so they travel
 * together here, for the length of one {@see RuleSetBuilder::for()} call and no longer.
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
     * Each keyword the walk read and enforced nothing for, by the fields it was left unenforced on: a keyword mapped to
     * the labels of those fields, in the order they were met. The data behind the findings that are about a keyword,
     * and none of the ones that are only worth saying.
     *
     * @var array<string, list<string>>
     */
    public array $unenforced = [];

    /**
     * Whether `multipart/form-data` is the only media type the rule set is read from, which is the one body a part can
     * be a file in.
     */
    public bool $multipart = false;

    /**
     * Where the fields being walked come from when every value in it is text (`query string` or `form body`), or null
     * for a JSON body. It is what decides how a boolean is read.
     */
    public ?string $textSource = null;

    /**
     * Whether the body is read from a form (form-encoded or multipart), alone or beside JSON, which is when PHP renames
     * a top-level name before Laravel sees it.
     */
    public bool $formBody = false;

    /**
     * Whether `application/json` is among the media types the body is read from.
     */
    public bool $jsonBody = false;

    /**
     * Whether the generated request has to refuse a JSON body whose root is not an object, and whether a `null` root is
     * the schema's own to allow.
     */
    public bool $jsonRoot = false;

    public bool $nullableRoot = false;

    /**
     * Whether the contract requires the body, which a request with no content at all breaks. No rule says so when every
     * property is optional, so the request checks it itself.
     */
    public bool $requiredRoot = false;

    /**
     * The key the request's failures about the body itself are answered on.
     */
    public string $rootKey = 'body';

    /**
     * Whether the body is read from JSON and from a form at once, which no one boolean rule serves.
     */
    public bool $mixedBody = false;

    /**
     * For a body read only as a form: each top-level contract name PHP rewrites and that has a rule, by the path its
     * value is delivered under (`page[size]` is `['page', 'size']`).
     *
     * @var array<string, list<string>>
     */
    public array $formPaths = [];

    /**
     * Each top-level contract name PHP rewrites, by the rule key its delivered value answers to, for a body read as a
     * form alone or beside JSON.
     *
     * @var array<string, string>
     */
    public array $deliveredKeys = [];

    /**
     * Each top-level contract name PHP rewrites, grouped by the top-level key a form delivers it under (`page[size]`
     * under `page`), whether or not it has a rule. A `query` parameter of that name reaches Laravel as the same key.
     *
     * @var array<string, list<string>>
     */
    public array $formHeads = [];

    /**
     * The top-level names whose value the request cannot check when a form sends it, by the reason, worded to follow
     * "the request cannot check it in a form body: ".
     *
     * @var array<string, string>
     */
    public array $unchecked = [];

    /**
     * The top-level names that have no rule at all, because PHP delivers them under a key another name shares or under
     * none. A subset of {@see self::$unchecked}.
     *
     * @var array<string, true>
     */
    public array $withheld = [];

    /**
     * Whether the finding about `mimetypes` refusing a file named like PHP has been written, since one request says it
     * once however many parts it has.
     */
    public bool $phpUploadNamed = false;

    /**
     * Record that a keyword was read on a field and nothing enforces it, once per field.
     */
    public function leaveUnenforced(string $keyword, string $label): void
    {
        if (! in_array($label, $this->unenforced[$keyword] ?? [], true)) {
            $this->unenforced[$keyword][] = $label;
        }
    }

    /**
     * @param  string  $identity  the operation's label, for a refusal's message
     */
    public function __construct(
        public readonly string $identity,
    ) {}
}
