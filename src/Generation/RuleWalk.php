<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\EncodingDefaults;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Validation\MultipleOf;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Gcob\LaraSpecFirst\Validation\WholeNumber;

/**
 * What one walk over an operation's input has produced so far.
 *
 * A rule set is built by descending nested objects and array elements, and every level adds rules and findings.
 * Threading two arrays by reference through every method would say the same thing with more noise, so they travel
 * together here, for the length of one {@see RuleSetBuilder::for()} call and no longer.
 *
 * **Every finding is written through one of four methods, and each is a kind:** a gap ({@see self::unenforced()}, with
 * its entries, written later by {@see self::flushDeferredGap()} when {@see self::deferGap()} held it), a request
 * stricter than the contract ({@see self::stricter()}), information ({@see self::information()}) or a fault in the
 * document ({@see self::fault()}). The records behind them are private, so a finding cannot be written as none of the
 * four, and the build and the doctor read the same one.
 *
 * @internal Not public API — the accumulator of one build of one rule set.
 */
final class RuleWalk
{
    /**
     * The entry a merge patch's `required` is counted under when the request asks for names the merge patch may leave
     * out, so it enforces more than the contract and a client answers `422` for a valid request. It is a keyword of its
     * own, and not `required`, because the doctor says the opposite about `required`: that the request leaves it out,
     * or holds only part of it.
     */
    public const STRICTER_REQUIRED = 'stricterRequired';

    /**
     * @var array<string, list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>>
     */
    public array $rules = [];

    /**
     * The rule keys a `query` name or a form body's name judges only when its value is not an array, with the path PHP
     * delivers each under: the names PHP nests under them carry the array. Also the key of a closed form level that no
     * rule judges but a name below it (`a[c][d]` below the shared key of `a[c]` and `a[c]x`).
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
     * The keys of {@see self::$whenNotArray} whose array branch holds the keys the contract declares below the name (or
     * below that key), in a closed body: `array:size` instead of `array`, in front of `exclude` when the array is
     * dropped. See {@see RuleSet::$arrayKeys}.
     *
     * @var array<string, list<string>>
     */
    public array $arrayKeys = [];

    /**
     * Every finding the walk wrote, in the order it wrote them, whatever its kind. Written only through
     * {@see self::unenforced()}, {@see self::stricter()}, {@see self::information()} and {@see self::fault()}, so each
     * one is exactly one of the four kinds: a gap with at least one entry, a request stricter than the contract, a
     * piece of information, or a fault in the document. A gap held by {@see self::deferGap()} is written later, as a
     * gap, by {@see self::flushDeferredGap()}.
     *
     * @var list<string>
     */
    private array $findings = [];

    /**
     * The findings that say the request is stricter than the contract, not looser: the same sentences as in
     * {@see self::$findings}, which stays whole. Nothing is unenforced by them, so the build does not count them.
     *
     * @var list<string>
     */
    private array $stricter = [];

    /**
     * The findings that lose no constraint: the same sentences as in {@see self::$findings}, which stays whole. They
     * say what a reader of the file would otherwise not see: a body key a `query` parameter shadows, a name a form
     * rewrites, a `readOnly` field that is required, two media types over one schema, the optional fields of a `PUT`, a
     * required name no `properties` declares, an untyped field that states nothing else, a boolean read as text (a
     * query parameter, a form field, or an item of a boolean list in the query string), an untyped `enum` of numbers or
     * strings read as text, a partial body (a `PATCH`, or a merge patch) whose required list is read as empty, a
     * required form body that accepts an empty form, and an empty bracketed property of an open `deepObject`. Nothing
     * is unenforced by them, so the build does not count them.
     *
     * @var list<string>
     */
    private array $information = [];

    /**
     * The rule keys written under the wildcard of a map that sits beside declared properties, by the names each step of
     * the key must not be for the value rules to apply: see {@see RuleSet::$undeclaredKeys}. A value that is a map
     * itself adds its own step to the entries of its keys.
     *
     * @var array<string, array<int, list<string>>>
     */
    public array $undeclaredKeys = [];

    /**
     * The rule keys of the arrays a client writes as one comma-separated string, in the order they were met: see
     * {@see RuleSet::$commaLists}, which holds them as the steps the request splits.
     *
     * @var list<string>
     */
    public array $commaLists = [];

    /**
     * Whether the body's value schema is judged at the root, under `*`: the build adds the keys the `query` parameters
     * arrive under to the names the root's guard spares, once the parameters are planned.
     */
    public bool $rootJudged = false;

    /**
     * Whether the body is a map or an open object that allows other keys (`additionalProperties` is not `false`): the
     * findings about the keys a client may send are about it alone, since a closed body refuses them all.
     */
    public bool $openMapRoot = false;

    /**
     * Each keyword the walk read and enforced nothing for, by the fields it was left unenforced on: a keyword mapped to
     * the labels of those fields, in the order they were met. The record of the gaps: each one written by
     * {@see self::unenforced()} adds at least one, and nothing else is a gap, so the request is left unenforced exactly
     * when this is not empty (or a fault was written).
     *
     * @var array<string, non-empty-list<string>>
     */
    private array $unenforced = [];

    /**
     * What the document writes that is wrong, and the walk read nothing from: the sentences of the findings that are a
     * fault in the document rather than a limit of this package. The doctor reports each one as a document fault, since
     * the rule set is looser than the contract for it and the author is the one who can fix that.
     *
     * @var list<string>
     */
    private array $faults = [];

    /**
     * Where the sentence about the file parts of a body that is multipart and also JSON or a form goes in the findings,
     * what it says, and the keywords the parts state: the entries of that gap are the parts' own, which the walk of the
     * fields records, so the sentence is written once it has, by {@see self::flushDeferredGap()}.
     *
     * @var array{int, string, non-empty-list<string>}|null
     */
    private ?array $deferredGap = null;

    /**
     * Whether `multipart/form-data` is the only media type the rule set is read from, which is the one body a part can
     * be a file in.
     */
    public bool $multipart = false;

    /**
     * The `contentType` each kind of part gets when its `encoding` writes none, as the document's own version gives it.
     * Set from the request body by `build()`, and read only where a body's `encoding` is: an operation with no body has
     * none to read.
     */
    public EncodingDefaults $encodingDefaults;

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
     * Whether the body is an object with no declared property, a map or an open object, whose keys are all undeclared:
     * the DTO carries them whole, and a `query` parameter's key would shadow one of them.
     */
    public bool $mapRoot = false;

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
     * Whether the body is required, is not a partial body, is read as JSON and has a root that allows `null`: the one
     * required body whose required keys follow their siblings, like an optional body's, since `null` reaches the rules
     * as `[]`.
     */
    public bool $nullableRequiredBody = false;

    /**
     * Whether the body is read as a partial update, a `PATCH` or a merge patch: the rules ask for none of its top-level
     * names, so a top-level name the walk drops has no `required` to leave unenforced.
     */
    public bool $partialBody = false;

    /**
     * Whether a JSON merge patch (`application/merge-patch+json`) is among the media types the rule set reads.
     */
    public bool $mergePatch = false;

    /**
     * Whether the merge patch is the only media type the rule set reads: the one body that is partial by what its media
     * type says, whatever the method.
     */
    public bool $mergePatchAlone = false;

    /**
     * How many arrays' items the walk is inside of: an object in one is a whole value, which a merge patch replaces.
     */
    public int $underItems = 0;

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
     * The top-level names the body's schema writes, as written: each property, each `required` name and both sides of a
     * `dependentRequired`. A plain `query` parameter of one of these names is refused, whether or not the name has a
     * rule of its own. Empty for a body that is not read as an object.
     *
     * @var list<string>
     */
    public array $bodyNames = [];

    /**
     * The names of `bodyNames` that are declared under `properties`, which a refusal words differently from a name the
     * body only requires.
     *
     * @var list<string>
     */
    public array $bodyProperties = [];

    /**
     * The top-level names whose value the request cannot check when a form sends it, by the reason, worded to follow
     * "the request cannot check it in a form body: ". A name here has no rule and no presence rule.
     *
     * @var array<string, string>
     */
    public array $unchecked = [];

    /**
     * The top-level names of a body read as a form judged by shape, because a form delivers other names below them
     * (`page` above `page[size]`). Their rules are written, behind a shape check, once every rule key exists. Beside
     * JSON, only a name PHP leaves alone.
     *
     * @var array<string, true>
     */
    public array $shaped = [];

    /**
     * The shaped names the contract requires, by the reason the request cannot promise them, worded to follow "the
     * request cannot check it in a form body: ": the array a form builds for the names below one stands in for it
     * (beside JSON, a JSON client's array too). Apart from {@see self::$unchecked}, since the name keeps its rule and
     * its presence rule, and merged into the rule set's `uncheckedNames`, which is what makes the DTO property
     * `Optional`.
     *
     * @var array<string, string>
     */
    public array $standIn = [];

    /**
     * The keys of the validated data `dto()` reads only when their value is not an array: see
     * {@see RuleSet::$scalarNames}.
     *
     * @var list<string>
     */
    public array $scalarNames = [];

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
     * The rule keys that are the wildcard of a map (`*`, `meta.*`), which judges the values of an object with no
     * declared property. A cut on one is a map that is empty, where a cut on any other key ending in `.*` is a list.
     *
     * @var array<string, true>
     */
    public array $mapKeys = [];

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
     * Whether the fields being walked are read from a JSON body and from nothing else: the one place a `number` and a
     * `boolean` keep their types, and an `integer` refuses text. A query parameter is text whatever the body is, and a
     * body that is also a form carries text too: its number and boolean keep the loose rules, and its integer takes
     * `WholeNumber(acceptsText: true)`, which judges a JSON number by its value and lets the text of a form through.
     */
    public function strictJson(): bool
    {
        return $this->textSource === null && $this->jsonBody && ! $this->formBody;
    }

    /**
     * Every finding the walk wrote so far, whatever its kind.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * The findings that say the request is stricter than the contract, which are in {@see self::findings()} too.
     *
     * @return list<string>
     */
    public function stricterFindings(): array
    {
        return $this->stricter;
    }

    /**
     * The findings that lose no constraint, which are in {@see self::findings()} too.
     *
     * @return list<string>
     */
    public function informationFindings(): array
    {
        return $this->information;
    }

    /**
     * The findings that are a fault in the document, which are in {@see self::findings()} too.
     *
     * @return list<string>
     */
    public function faultFindings(): array
    {
        return $this->faults;
    }

    /**
     * Each keyword left unenforced, by the labels of the fields it was left unenforced on.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function unenforcedEntries(): array
    {
        return $this->unenforced;
    }

    /**
     * Record a gap: a finding in the generated file that says the request enforces less than the contract, and the
     * keywords it leaves unenforced on the fields it names, which is what the build counts and the doctor reports.
     *
     * **A gap has at least one entry.** A sentence with none would be written in the file and read as nothing by both,
     * so the entries are required, and the one caller that may have none (a field the rule set drops that states
     * nothing) writes information instead.
     *
     * @param  non-empty-array<string, non-empty-list<string>>  $entries  the labels of the fields, by keyword
     */
    public function unenforced(string $finding, array $entries): void
    {
        $this->writeGap($finding, $entries, count($this->findings));
    }

    /**
     * @param  non-empty-array<string, non-empty-list<string>>  $entries
     */
    private function writeGap(string $finding, array $entries, int $at): void
    {
        array_splice($this->findings, $at, 0, [$finding]);

        foreach ($entries as $keyword => $labels) {
            foreach ($labels as $label) {
                $this->leaveUnenforced($keyword, $label);
            }
        }
    }

    /**
     * Hold a gap whose entries the walk of the fields has not recorded yet, at the place its sentence goes in the
     * findings: {@see self::flushDeferredGap()} writes it as a gap once the walk is over.
     *
     * @param  non-empty-list<string>  $keywords  the keywords the gap is about, whose entries are the walk's own
     */
    public function deferGap(string $finding, array $keywords): void
    {
        $this->deferredGap = [count($this->findings), $finding, $keywords];
    }

    /**
     * Write the gap {@see self::deferGap()} held, if one is: with the entries the walk recorded for its keywords, and
     * when it reached none of them, on the body itself, since a gap with no entry would be read as nothing by the build
     * and the doctor.
     */
    public function flushDeferredGap(): void
    {
        if ($this->deferredGap === null) {
            return;
        }

        [$at, $finding, $keywords] = $this->deferredGap;
        $this->deferredGap = null;
        $recorded = array_intersect_key($this->unenforced, array_flip($keywords));

        $this->writeGap($finding, $recorded !== [] ? $recorded : array_fill_keys($keywords, ['The body itself']), $at);
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
     * Record a finding that loses no constraint: in the generated file like any other, and apart so the build does not
     * count it as a constraint left unenforced.
     */
    public function information(string $message): void
    {
        $this->findings[] = $message;
        $this->information[] = $message;
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
     * Take a finding that loses no constraint out of the record, once the walk knows a rule makes it untrue.
     */
    public function withdrawInformation(string $message): void
    {
        $this->findings = array_values(array_filter($this->findings, static fn (string $finding): bool => $finding !== $message));
        $this->information = array_values(array_filter($this->information, static fn (string $finding): bool => $finding !== $message));
    }

    /**
     * Record that a keyword was read on a field and nothing enforces it, once per field: the entry of a field whose
     * sentence is written elsewhere (the second name of two a `query` string delivers under one key).
     */
    public function leaveUnenforced(string $keyword, string $label): void
    {
        if (! in_array($label, $this->unenforced[$keyword] ?? [], true)) {
            $this->unenforced[$keyword][] = $label;
        }
    }

    /**
     * @param  string  $identity  the operation's label, for a refusal's message
     * @param  RuleSetOptions  $options  what the configuration changes about the rules, the same for every walk of one
     *                                   operation
     */
    public function __construct(
        public readonly string $identity,
        public readonly RuleSetOptions $options = new RuleSetOptions,
    ) {}
}
