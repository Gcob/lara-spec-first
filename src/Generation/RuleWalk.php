<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;

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
     * @var array<string, list<string|InRule|NullOrAbsent>>
     */
    public array $rules = [];

    /**
     * The rule keys a `query` name judges only when its value is not an array, with the path PHP delivers each under:
     * the names PHP nests under them carry the array.
     *
     * @var array<string, list<string>>
     */
    public array $whenNotArray = [];

    /**
     * The keys of {@see self::$whenNotArray} with no rule on any name below them: Laravel keeps an `array` key's
     * unchecked content in `validated()` unless a rule sits below it, so the array is dropped with `exclude` instead.
     *
     * @var list<string>
     */
    public array $dropsArray = [];

    /**
     * @var list<string>
     */
    public array $findings = [];

    /**
     * The findings that say the request is stricter than the contract, not looser: the same sentences as in
     * {@see self::$findings}, which stays whole. Nothing is unenforced by them, so the build does not count them.
     *
     * @var list<string>
     */
    public array $stricter = [];

    /**
     * Each keyword the walk read and enforced nothing for, by the fields it was left unenforced on: a keyword mapped to
     * the labels of those fields, in the order they were met. The data behind the findings that are about a keyword,
     * and none of the ones that are only worth saying.
     *
     * @var array<string, list<string>>
     */
    public array $unenforced = [];

    /**
     * What the document writes that is wrong, and the walk read nothing from: the sentences of the findings that are a
     * fault in the document rather than a limit of this package. The doctor reports each one as a document fault, since
     * the rule set is looser than the contract for it and the author is the one who can fix that.
     *
     * @var list<string>
     */
    public array $faults = [];

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
     * Whether the finding that a JSON integer written as a float is refused has been written, since one request says it
     * once however many integers it has.
     */
    public bool $integralFloatNamed = false;

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
     * Whether a top-level key of the body is asked with `present` or `required`, after every downgrade, which is what
     * refuses a form that sends no field. A key that only follows another (`present_with`) does not count.
     */
    public bool $rootAsksPresence = false;

    /**
     * The keys a JSON object sent as the body must carry once it is sent, by the rule their failure carries: what
     * {@see RuleSet::$requiredOnceSent} says. An optional body and a required one that allows `null` have them.
     *
     * @var array<string, string>
     */
    public array $requiredOnceSent = [];

    /**
     * Whether the body is required, is not a `PATCH`, is read as JSON and has a root that allows `null`: the one
     * required body whose required keys follow their siblings, like an optional body's, since `null` reaches the rules
     * as `[]`.
     */
    public bool $nullableRequiredBody = false;

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
     * The top-level keys the body's values arrive under, which a `query` parameter shares the data with: each name the
     * root schema declares, and for a form the name PHP gives it. Empty for a body the walk reads no keys from.
     *
     * @var list<string>
     */
    public array $bodyKeys = [];

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
     * The definitions of the named schemas the walk is inside of, outermost first: the path from the body down to the
     * field in hand. A recursion marker names the ancestor it points back at, and this is where that ancestor is found
     * to be expanded again.
     *
     * **A definition is the component as the document writes it** (its own `allOf` merged in, and nothing of the schema
     * that wraps or includes it), so a marker re-enters what the contract says the component is. The merged schema of a
     * wrapper is the wrong thing to keep here: it carries the wrapper's own constraints, which belong to the wrapper's
     * level alone.
     *
     * @var list<Schema>
     */
    public array $ancestors = [];

    /**
     * How many times the path in hand has re-entered each recursive schema, by its position (`recursesTo`). The limit
     * is the `x-max-depth` of the schema re-entered, so each one counts on its own.
     *
     * @var array<string, int>
     */
    public array $crossings = [];

    /**
     * Each recursive schema the walk had to cut at its depth, by its position: the name it is reported under, the depth
     * it was cut at, the depth it asked for (more than that when the ceiling lowered it), whether the schema stated it,
     * whether it allows `null`, and the rule keys that refuse what is below, in the order they were met. One finding is
     * written for each, once the walk is over, because only then is the count known.
     *
     * @var array<string, array{name: string, depth: int, requested: int, stated: bool, nullable: bool, keys: list<string>}>
     */
    public array $cuts = [];

    /**
     * The depth no recursive schema is validated below in this walk, or null for the depths the contract states. The
     * ceiling lowers it for the whole operation, so a tree is cut at one level on every branch, and a schema whose own
     * depth is under it keeps its own.
     */
    public ?int $depthCap = null;

    /**
     * The depth a single recursive schema is held to beside {@see self::$depthCap}, by its position: what tells two
     * schemas apart that the common cap leaves tied.
     *
     * @var array<string, int>
     */
    public array $caps = [];

    /**
     * The depth each recursive schema asked for, by its position, before any cap: the contract's own, or the default.
     *
     * @var array<string, int>
     */
    public array $requested = [];

    /**
     * How many rule keys each recursive schema wrote by its own expansions, the ones its nested schemas wrote not
     * counted, by its position. What ties are broken by.
     *
     * @var array<string, int>
     */
    public array $keysBy = [];

    /**
     * The sum of {@see self::$keysBy}, which is what lets one expansion tell its own keys from its nested ones.
     */
    public int $attributed = 0;

    /**
     * The deepest limit a recursive schema asked for in this walk, before the cap: where a search for the depth that
     * fits starts from.
     */
    public int $deepest = 0;

    /**
     * Whether the unrolled keys went past the ceiling. A walk that did is not a rule set: it stops expanding at once
     * and its result is thrown away, so the build never holds more than a ceiling's worth of keys plus one level.
     */
    public bool $exceeded = false;

    /**
     * How many rule keys the expansions that are over have written. A rule key an expansion writes is counted once, by
     * the outermost one, so the ceiling is a number of keys and not a number of expansions.
     */
    public int $unrolled = 0;

    /**
     * How many rule keys there were when the expansion in progress started, or null between two expansions. A marker
     * met inside an expansion is part of it, and it is the outermost one that counts what they all wrote.
     */
    public ?int $expansionStart = null;

    /**
     * The rule keys written by unrolling so far, the expansion in progress included.
     */
    public function unrolledKeys(): int
    {
        return $this->unrolled + ($this->expansionStart === null ? 0 : count($this->rules) - $this->expansionStart);
    }

    /**
     * The expanded ancestor a recursion marker points back at, or null when no schema the walk is inside is written at
     * that position (an inline one has no name to be found by).
     */
    public function ancestor(string $position): ?Schema
    {
        foreach (array_reverse($this->ancestors) as $schema) {
            if ($schema->source === $position) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Whether the fields being walked are read from a JSON body and from nothing else: the one place an `integer`, a
     * `number` and a `boolean` keep their types. A query parameter is text whatever the body is, and a body that is
     * also a form carries text too.
     */
    public function strictJson(): bool
    {
        return $this->textSource === null && $this->jsonBody && ! $this->formBody;
    }

    /**
     * Record a finding that says the request is stricter than the contract: in the generated file like any other, and
     * apart so the build does not count it as a constraint left unenforced.
     */
    public function stricter(string $message): void
    {
        $this->findings[] = $message;
        $this->stricter[] = $message;
    }

    /**
     * Record a fault in the document: a finding in the generated file, and a document fault for the doctor.
     */
    public function fault(string $message): void
    {
        $this->findings[] = $message;
        $this->faults[] = $message;
    }

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
