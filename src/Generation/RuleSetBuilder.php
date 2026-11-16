<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;

/**
 * One operation's request body and `query` parameters, as one Laravel rule set.
 *
 * **Every constraint maps or reports**, and the second half is what makes this safe to ship before the first is
 * finished. A keyword this class does not yet translate leaves a finding naming it and the field it was written on, so
 * a rule set that is incomplete says so in the file a reader opens. What is never emitted is a looser rule that almost
 * matches: accepting a payload the contract refuses is a wrong answer where a report is a missing one, and only the
 * second is recoverable. Nor is a rule emitted that is stricter than the contract, refusing what it accepts, unless
 * matching it exactly would be far too complex in Laravel, and then a finding says so.
 *
 * **What it translates is the whole table** in request-validation.md: types, nullability and presence, the bounds on
 * strings, numbers and arrays, the `format` values Laravel has a rule of the same meaning for, enumerations, patterns
 * inside the ECMA-262 / PCRE boundary, nested objects and array elements under dotted keys, `allOf` merged,
 * `dependentRequired`, an optional body's "all or none", and the file parts of a multipart body, narrowed by their
 * `encoding`. What it does not — a pattern across the boundary, a `format` Laravel would read differently, a recursive
 * schema's infinite depth — leaves a finding.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class RuleSetBuilder
{
    /**
     * RFC 3339's `date-time` grammar, as a pattern: a fraction of any length, `T` and `Z` in either case, an offset
     * with hours below 24.
     *
     * **A pattern rather than `date_format`**, because `date_format` passes only on an exact round trip through one of
     * PHP's format strings, and PHP has no variable-width fraction: six patterns covered 0, 3 and 6 digits and refused
     * the 7 .NET writes and the 9 Go does. ASCII classes and no `u`, since every character here is ASCII, and `D` so a
     * trailing newline is refused. Laravel's `date` beside it is what refuses a February 30th, which a grammar cannot
     * see. Measured on the validator: fractions of 1, 3, 5, 7 and 9 digits pass, as do lower case and `-00:00`;
     * `02-30`, `24:00:00`, `+24:00`, a missing offset and a trailing newline are refused.
     *
     * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
     */
    private const DATE_TIME_PATTERN = 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])[Tt]'
        .'([01][0-9]|2[0-3]):[0-5][0-9]:([0-5][0-9]|60)(\.[0-9]+)?([Zz]|[+-]([01][0-9]|2[0-3]):[0-5][0-9])$/D';

    /**
     * The `format` values Laravel has a rule of the same meaning for, and the rule. Anything else is reported: `uri`
     * most visibly, since Laravel's `url` turns away the non-hierarchical URIs (`urn:…`) JSON Schema allows.
     */
    private const FORMAT_RULES = [
        'date' => ['date_format:Y-m-d'],
        'date-time' => [self::DATE_TIME_PATTERN, 'date'],
        'email' => ['email'],
        'uuid' => ['uuid'],
        'ipv4' => ['ipv4'],
        'ipv6' => ['ipv6'],
    ];

    /**
     * The `format` values that are an annotation for a reader or a form and assert nothing, so no rule is missing for
     * them. `password` hides what is typed.
     */
    private const ANNOTATION_FORMATS = ['password'];

    /**
     * The range `format: int32` states, which `integer` does not carry: PHP's integers are 64-bit.
     */
    private const INT32_MINIMUM = -2147483648;

    private const INT32_MAXIMUM = 2147483647;

    /**
     * The one body media type a part can be a file in, so the one a file part is read as one for.
     */
    private const MULTIPART = 'multipart/form-data';

    /**
     * What a body required and read as a form says when nothing in it refuses an empty form: written by
     * {@see self::rootFindings()} before the walk, and taken out again when the walk finds a required key.
     */
    private const EMPTY_FORM_FINDING = 'The body is required and read as a form, so a form with a `Content-Type` and no field '
        .'(an empty `application/x-www-form-urlencoded` body, a `multipart/form-data` with no parts) is not '
        ."read as an absent body: a form's fields need not show in the raw content, so the request cannot "
        .'tell it from a body whose every field is optional, and accepts it unless a required property refuses it.';

    /**
     * The media types a generated rule set covers.
     *
     * All three arrive through `all()`, so one rule set covers them and the order here is only the order a tie is
     * broken in. What changes between them is whether a part can be a file, which is `uploads.md`'s subject.
     *
     * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
     */
    public const READ_MEDIA_TYPES = [
        'application/json',
        self::MULTIPART,
        'application/x-www-form-urlencoded',
    ];

    /**
     * Every keyword a rule could come from, by the property that carries it.
     *
     * The list is closed and checked per field against what the walk actually acted on, which is what makes a finding a
     * measurement rather than a reminder: a keyword stated on a field and absent from that field's handled set is
     * named, whatever the reason it was not translated. A keyword added to {@see Schema} and not here is a keyword
     * nothing reports, and that is the silence this mechanism exists against.
     *
     * `writeOnly` and `examples` are absent on purpose: the support matrix ignores them as rules rather than deferring
     * them, so there is nothing coming for a finding to promise. `readOnly` is read, and absent for a different reason:
     * it changes the presence of a required property rather than adding a rule, and says so in its own finding.
     */
    private const RULE_KEYWORDS = [
        'format',
        'enum',
        'pattern',
        'minLength',
        'maxLength',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minItems',
        'maxItems',
        'uniqueItems',
        'items',
        'properties',
        'required',
        'dependentRequired',
        'allOf',
        'additionalProperties',
        'isFilePart',
        'contentMediaType',
        'recursesTo',
    ];

    /**
     * How many times a payload may re-enter a recursive schema when the schema states no `x-max-depth`.
     *
     * @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"
     */
    public const int DEFAULT_MAX_DEPTH = 3;

    /**
     * How many rule keys unrolling recursion may write for one operation. When the depths the contract states need
     * more, they are lowered until they fit, the deepest schema first, and every branch of a schema is cut at its
     * depth.
     *
     * A schema that recurses `k` times per level grows as `k` to the power of the depth, and a request evaluates every
     * key it holds. The default depth of a search filter (`and`, `or`, `not`) is 315 keys and a binary tree at depth 6
     * is 381, so a plain contract is far under; a binary tree at depth 20 is over three million keys, which exhausts a
     * gigabyte before the build ends. A walk that passes the ceiling stops expanding at once, so a failed attempt costs
     * about the ceiling and not the tree.
     *
     * @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"
     */
    public const int MAX_UNROLLED_KEYS = 1000;

    /**
     * @throws ConflictingInputException the body and a query parameter naming one field, two media types over two
     *                                   schemas, or an `allOf` whose branches disagree
     */
    public static function for(Operation $operation): RuleSet
    {
        $walk = new RuleWalk($operation->label());
        $set = self::build($operation, $walk);

        if (! $walk->exceeded) {
            return $set;
        }

        // The depths the contract states do not fit the ceiling, and the walk lowers them in two steps. The keys a tree
        // needs only grow with the depth, so the first step finds the deepest cap shared by every schema that fits, by
        // halving: a failed attempt stops at the ceiling and costs no more than a success, and a cap of zero unrolls
        // nothing and always fits.
        $best = null;
        $bestWalk = null;
        $bestCap = 0;
        $low = 0;
        $high = $walk->deepest - 1;

        while ($low <= $high) {
            $cap = intdiv($low + $high, 2);
            $attempt = new RuleWalk($operation->label());
            $attempt->depthCap = $cap;
            $built = self::build($operation, $attempt);

            if ($attempt->exceeded) {
                $high = $cap - 1;

                continue;
            }

            [$best, $bestWalk, $bestCap] = [$built, $attempt, $cap];
            $low = $cap + 1;
        }

        if ($best === null || $bestWalk === null) {
            $none = new RuleWalk($operation->label());
            $none->depthCap = 0;

            return self::build($operation, $none);
        }

        // One level deeper than that cap does not fit, but it might fit with some of the schemas that sit on it held
        // back. Lowering the deepest schema first, one level at a time, comes to the same state this reaches directly:
        // every schema at the shared cap plus one, and then the ones tied on it lowered one by one, the one with the
        // most unrolled keys first, until the rules fit.
        $tied = array_keys(array_filter($bestWalk->requested, static fn (int $depth): bool => $depth > $bestCap));
        usort($tied, static fn (string $a, string $b): int => ($bestWalk->keysBy[$b] ?? 0) <=> ($bestWalk->keysBy[$a] ?? 0));
        $lowered = [];

        foreach ($tied as $position) {
            $lowered[$position] = $bestCap;
            $attempt = new RuleWalk($operation->label());
            $attempt->depthCap = $bestCap + 1;
            $attempt->caps = $lowered;
            $built = self::build($operation, $attempt);

            if (! $attempt->exceeded) {
                return $built;
            }
        }

        return $best;
    }

    /**
     * One walk over an operation's input, into the rule set it gives and the walk that holds how it went.
     *
     * @throws ConflictingInputException
     */
    private static function build(Operation $operation, RuleWalk $walk): RuleSet
    {
        $body = self::bodySchema($operation, $walk->findings);
        $walk->multipart = $operation->requestBody !== null
            && array_values(array_intersect($operation->requestBody->mediaTypes(), self::READ_MEDIA_TYPES)) === [self::MULTIPART];
        $closedKeys = null;

        $read = $operation->requestBody === null
            ? []
            : array_values(array_intersect($operation->requestBody->mediaTypes(), self::READ_MEDIA_TYPES));
        $walk->textSource = $read !== [] && ! in_array('application/json', $read, true) ? 'form body' : null;
        $walk->mixedBody = count($read) > 1 && in_array('application/json', $read, true);
        $walk->formBody = array_diff($read, ['application/json']) !== [];
        $walk->jsonBody = in_array('application/json', $read, true);

        if ($body !== null) {
            $closedKeys = self::bodyRules($operation, $body, $walk);
        }

        // A query string carries no file, so the parameters below are never read
        // as one, whatever the body is.
        $multipart = $walk->multipart;
        $walk->multipart = false;
        $walk->textSource = 'query string';
        $walk->mixedBody = false;
        $walk->formBody = false;
        $queryKeys = [];

        foreach ($operation->queryParameters as $parameter) {
            $unescapable = self::unreachableQueryName($parameter->name) ?? self::unescapableKey($parameter->name);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;
                self::leaveFieldUnenforced($walk, '`'.$parameter->name.'`', $parameter->schema, $parameter->required);

                continue;
            }

            $key = self::ruleKey($parameter->name);

            if (isset($walk->formHeads[$parameter->name])) {
                $bodyName = $walk->formHeads[$parameter->name][0];

                throw ConflictingInputException::deliveredFieldDeclaredTwice($operation->label(), $bodyName, $parameter->name);
            }

            if (isset($walk->rules[$key])) {
                throw ConflictingInputException::fieldDeclaredTwice($operation->label(), $parameter->name);
            }

            $queryKeys[] = $parameter->name;

            $presence = $parameter->required ? self::requiredRule($parameter->schema) : 'sometimes';
            $merged = AllOfMerger::merge($parameter->schema, $operation->label(), '`'.$parameter->name.'`');

            // A query string is not JSON. OpenAPI's default serialization of
            // an array is `?tag=a&tag=b`, of which PHP keeps only the last, and
            // `explode: false` sends `?tag=a,b` as one string. `style` and
            // `explode` are not read, so the value reaching the validator is
            // not the shape the schema describes, and every rule about that
            // shape would refuse a valid request. An array's own name is always
            // sent, so its presence is enforced and the rest reported.
            //
            // An object's is not: the default `form` style with `explode` sends
            // one key per property — `?status=a` — and the parameter's name
            // never appears, so even presence would refuse a valid request.
            // It is `sometimes` whatever its `required` flag, and said so.
            if ($merged->soleType() === SchemaType::Array) {
                $walk->rules[$key] = [$presence];
                $walk->findings[] = sprintf(
                    '`%s` is a `query` parameter typed `array`. Its `style` and `explode` serialization is '
                        .'not read, and PHP does not parse it into that shape, so only its presence is enforced.',
                    $parameter->name,
                );

                continue;
            }

            if ($merged->soleType() === SchemaType::Object) {
                $walk->rules[$key] = ['sometimes'];
                $walk->findings[] = sprintf(
                    '`%s` is a `query` parameter typed `object`. Its default serialization sends one key per '
                        .'property and never its own name, and `style` and `explode` are not read, so nothing '
                        .'about it is enforced, not even its presence.',
                    $parameter->name,
                );

                continue;
            }

            self::field($walk, $key, '`'.$parameter->name.'`', $parameter->schema, $presence);
        }

        $walk->exceeded = $walk->exceeded || $walk->unrolledKeys() > self::MAX_UNROLLED_KEYS;

        self::reportCuts($walk);

        return new RuleSet($walk->rules, $walk->findings, $closedKeys, $body, $multipart, $queryKeys, $walk->jsonRoot, $walk->nullableRoot, $walk->formPaths, $walk->unchecked, $walk->requiredRoot, $walk->rootKey, $walk->unenforced, $walk->faults, $walk->stricter, $walk->requiredOnceSent);
    }

    /**
     * The one schema the rule set is built from, or null when the body states none this package reads.
     *
     * @param  list<string>  $findings
     *
     * @throws ConflictingInputException
     */
    private static function bodySchema(Operation $operation, array &$findings): ?Schema
    {
        $body = $operation->requestBody;

        if ($body === null) {
            return null;
        }

        $unread = array_values(array_diff($body->mediaTypes(), self::READ_MEDIA_TYPES));

        if ($unread !== []) {
            // Reported rather than refused: a body declaring `application/xml`
            // is a contract this package does not serve, and refusing the whole
            // build over it would turn away an operation whose other half is
            // perfectly readable.
            $findings[] = sprintf(
                'The body declares %s, which this package does not read. Nothing in the rule set '
                    .'comes from it.',
                self::list($unread),
            );
        }

        $read = array_filter(
            $body->content,
            static fn (string $mediaType): bool => in_array($mediaType, self::READ_MEDIA_TYPES, true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($read === []) {
            return null;
        }

        self::assertOneSchema($operation, $read);

        if (count($read) > 1) {
            $findings[] = sprintf(
                'The body declares %s over one schema, so one rule set covers them all.',
                self::list(array_keys($read)),
            ).(isset($read[self::MULTIPART]) && self::hasFilePart(reset($read))
                ? ' A part the schema marks as a file is then read as a string, because a JSON or form-encoded '
                    .'body cannot carry one and no rule serves both.'
                : '');
        }

        return reset($read);
    }

    /**
     * Whether a file part is written anywhere in a schema, a nested one and an `allOf` branch included. A recursion
     * marker has nothing under it, so it ends the walk.
     */
    private static function hasFilePart(Schema $schema): bool
    {
        if ($schema->isFilePart) {
            return true;
        }

        foreach ([...array_values($schema->properties), ...$schema->allOf, ...($schema->items === null ? [] : [$schema->items])] as $child) {
            if (self::hasFilePart($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuse two media types describing two different shapes.
     *
     * Compared by value rather than by identity, because two media types pointing at one `$ref` are not guaranteed to
     * reach here as one object, and two identical inline schemas are not a conflict either: what the document said is
     * the same thing twice.
     *
     * @param  array<string, Schema>  $read
     *
     * @throws ConflictingInputException
     */
    private static function assertOneSchema(Operation $operation, array $read): void
    {
        $first = null;
        $firstMediaType = null;

        foreach ($read as $mediaType => $schema) {
            if ($first === null) {
                $first = $schema;
                $firstMediaType = $mediaType;

                continue;
            }

            if (! $schema->sameShapeAs($first)) {
                throw ConflictingInputException::twoMediaTypeSchemas(
                    $operation->label(),
                    (string) $firstMediaType,
                    $mediaType,
                );
            }
        }
    }

    /**
     * Every rule the body's own schema produces, and the top-level keys it closes the payload to, or null when it does
     * not.
     *
     * @return list<string>|null
     *
     * @throws ConflictingInputException
     */
    private static function bodyRules(Operation $operation, Schema $written, RuleWalk $walk): ?array
    {
        $body = $operation->requestBody;
        assert($body instanceof RequestBody);

        $schema = AllOfMerger::merge($written, $operation->label(), 'the body');

        foreach (self::rootFindings($operation, $schema, $body, $walk) as $finding) {
            $walk->findings[] = $finding;
        }

        if (! self::isObjectBody($schema)) {
            return null;
        }

        // Laravel reads a JSON body as `(array) json_decode(...)`, so `"Ada"` and `[1,2]`
        // reach the rules as a list no rule has a key for. The request checks the root itself.
        $walk->jsonRoot = $walk->jsonBody;
        $walk->nullableRoot = $schema->isNullable();
        $walk->requiredRoot = $body->required;
        $walk->rootKey = self::rootKey($schema, $operation);

        // The root goes through the same sweep a field does, minus what the
        // object walk below acts on. Without it a keyword written on the
        // body's own schema — an `enum` is the one a real contract writes there
        // — is read and enforced by nothing, and an empty findings list makes
        // the generated file claim the opposite.
        self::reportUnhandled(
            $walk,
            'The body itself',
            $schema,
            ['type', 'properties', 'required', 'dependentRequired', 'additionalProperties', 'allOf'],
        );

        self::planFormNames($schema, $walk);
        $walk->requiredOnceSent = self::requiredOnceSent($operation, $schema, $body, $walk);

        $outside = self::enter($walk, $written, $schema, 'the body');

        self::objectChildren(
            $walk,
            '',
            $schema,
            self::rootPresence($operation, $schema, $body, $walk),
            self::encodedParts($body, $schema, $walk),
        );

        self::leave($walk, $outside);

        // Why: the finding is written before the walk, which is the only thing that knows a required key refuses an
        // empty form. Taking it out afterwards leaves every other finding where it was.
        if ($walk->rootAsksPresence) {
            $walk->findings = array_values(array_filter(
                $walk->findings,
                static fn (string $finding): bool => $finding !== self::EMPTY_FORM_FINDING,
            ));
        }

        // The payload's root has no field for `array:` to sit on, so a closed
        // root becomes a closure comparing its top-level keys. Only the top
        // level: every nested object that closes itself already carries
        // `array:` on its own key, so checking deeper here would only repeat
        // it — and would refuse the extras of a nested object that allows them.
        if ($schema->additionalProperties !== false) {
            return null;
        }

        // A form delivers a name PHP rewrote, so a closed root names the name it
        // receives. A body that is JSON too keeps the one the contract writes beside it.
        $closed = [];
        $steps = [];
        $accepted = [];
        $declared = self::declaredNames($schema);

        foreach (array_keys($schema->properties) as $name) {
            $name = (string) $name;

            if (! $walk->formBody || ! self::phpRewrites($name)) {
                $closed[] = $name;

                continue;
            }

            $delivered = self::deliveredName($name);

            if ($walk->mixedBody) {
                $closed[] = $name;
            }

            if ($delivered === null) {
                continue;
            }

            $closed[] = $delivered;

            // A name that collided has its own finding, and the key its value arrives under is a declared name
            // too, so the contract does not refuse it.
            if (isset($walk->withheld[$name]) && in_array($delivered, $declared, true)) {
                continue;
            }

            // A bracketed name closes every level above its leaf: each level is an array whose keys are the
            // ones the contract declares below it. A name PHP only renamed (`user.name` as `user_name`) has no
            // level to close, and the key check accepts what it delivers.
            $path = $walk->formPaths[$name] ?? null;

            if ($path === null || count($path) < 2) {
                $accepted[$name] = $delivered;

                continue;
            }

            for ($level = 1; $level < count($path); $level++) {
                $steps[self::pathKey(array_slice($path, 0, $level))][$name] = $path[$level];
            }
        }

        // A form-only body closes each level to the keys the contract declares below it. A key `array:` cannot
        // name (a comma in it) leaves that level open, and the finding below names what it then accepts.
        foreach ($steps as $level => $below) {
            $level = (string) $level;
            $keys = array_values(array_unique(array_values($below)));

            if (array_filter($keys, static fn (string $key): bool => str_contains($key, ',')) === []) {
                $walk->rules[$level] = ['sometimes', 'array:'.implode(',', $keys)];

                continue;
            }

            foreach (array_keys($below) as $name) {
                $accepted[(string) $name] = $level;
            }
        }

        if ($accepted !== []) {
            $walk->findings[] = sprintf(
                'The body is closed to its declared names, but a form delivers %s, so the key check accepts %s, which '
                    .'a client can also send as a key the contract does not declare, and nothing tells the two apart.',
                self::enumerate(array_map(
                    static fn (string $name, string $delivered): string => '`'.$name.'` as `'.$delivered.'`',
                    array_keys($accepted),
                    $accepted,
                )),
                self::list(array_values(array_unique($accepted))),
            );
        }

        return array_values(array_unique($closed));
    }

    /**
     * The `contentType` the document wrote for each top-level part, by the name it writes the part under.
     *
     * **Only the media types `encoding` applies to** (`multipart/form-data` and `application/x-www-form-urlencoded`,
     * which OpenAPI names), and only those the body declares: `encoding` beside `application/json` is ignored, as the
     * specification says. A part is read as a file only when the body is multipart alone, and then only multipart's
     * `encoding` is read. When the body declares both, the part is not a file and what is written is only reported: a
     * part written under both is read as the two lists joined (multipart's first), so the report names both and says
     * nothing only when every type written is the part's default.
     *
     * **A part the schema does not declare is a fault in the document**, not a limit of this package, so it is said in
     * the generated file and reported by the doctor as a document fault (never as `unenforced`, which is a limit of
     * this package). So is a `contentType` that is not a string (a YAML list, a number): the specification says one
     * comma-separated string, so nothing is read from it, and the part keeps what `contentMediaType` says. A string
     * written under the other media type is still read.
     *
     * @return array<string, string> a part name => the `contentType` as written
     */
    private static function encodedParts(RequestBody $body, Schema $schema, RuleWalk $walk): array
    {
        $written = [];
        $mistyped = [];

        foreach ([self::MULTIPART, 'application/x-www-form-urlencoded'] as $mediaType) {
            if (! isset($body->content[$mediaType])) {
                continue;
            }

            foreach ($body->encoding[$mediaType] ?? [] as $part => $contentType) {
                $part = (string) $part;

                if (! is_string($contentType)) {
                    $mistyped[$part] = true;

                    continue;
                }

                if (! in_array($contentType, $written[$part] ?? [], true)) {
                    $written[$part][] = $contentType;
                }
            }
        }

        $undeclared = array_values(array_filter(
            array_map('strval', array_keys($written + $mistyped)),
            static fn (string $part): bool => ! isset($schema->properties[$part]),
        ));

        if ($undeclared !== []) {
            $walk->fault(sprintf(
                '`encoding` names %s, which the body does not declare under `properties`: that is a fault in the '
                    .'document, and nothing is read from it.',
                self::list($undeclared),
            ));

            $written = array_diff_key($written, array_flip($undeclared));
            $mistyped = array_diff_key($mistyped, array_flip($undeclared));
        }

        foreach (array_keys($mistyped) as $part) {
            $walk->fault(sprintf(
                '`encoding` writes a `contentType` for `%s` that is not a string: that is a fault in the document, '
                    .'and nothing is read from it.',
                $part,
            ));
        }

        return array_map(static fn (array $lists): string => implode(', ', $lists), $written);
    }

    /**
     * The key the request's own failures about the body itself are answered on: `body`, unless the contract has a
     * top-level name that is that, or a `query` parameter (which is merged into the same data), in which case the
     * client could not tell which of the two is wrong, and the same key with `_` in front until none is.
     */
    private static function rootKey(Schema $schema, Operation $operation): string
    {
        $declared = [
            ...self::declaredNames($schema),
            ...array_map(static fn (QueryParameter $parameter): string => $parameter->name, $operation->queryParameters),
        ];

        $key = 'body';

        while (in_array($key, $declared, true)) {
            $key = '_'.$key;
        }

        return $key;
    }

    /**
     * Every top-level name the body's schema writes: its properties, its required list and both sides of a
     * `dependentRequired`.
     *
     * @return list<string>
     */
    private static function declaredNames(Schema $schema): array
    {
        $declared = [...array_map('strval', array_keys($schema->properties)), ...$schema->required];

        foreach ($schema->dependentRequired as $trigger => $names) {
            array_push($declared, (string) $trigger, ...array_map('strval', $names));
        }

        return $declared;
    }

    /**
     * Whether the body's schema is read as an object: it says so, or it names `properties` and says no type.
     *
     * JSON Schema applies `properties` to objects only, and a body that names some is an object whether or not it
     * spells `type: object`, which generators that omit the type are common enough to matter. Only the body's root: a
     * nested field with the same shape would be refused a string the contract accepts, so it keeps no type rule.
     */
    public static function isObjectBody(Schema $schema): bool
    {
        return $schema->soleType() === SchemaType::Object
            || ($schema->types === [] && $schema->properties !== []);
    }

    /**
     * How each of the root's properties is asked for, which is where the method and the body's own `required` flag
     * meet.
     *
     * - A `PATCH` is a partial update, so the schema's list describes a representation the client is not sending all
     *   of: every property is `sometimes`.
     * - An optional body may be absent entirely, and a plain presence rule would then refuse a request the contract
     *   allows. What the contract means is "a body or none", and `*_with` naming every other key the body may carry
     *   says exactly that: nothing sent, nothing required; any key sent — a required one or an optional one — and the
     *   required ones follow. Naming only the required siblings would accept a body made of optional keys alone, which
     *   the contract refuses.
     * - Otherwise the schema's list is read as written.
     *
     * @return callable(string, Schema): string
     */
    private static function rootPresence(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): callable
    {
        if ($operation->method === HttpMethod::Patch) {
            return static fn (string $name, Schema $property): string => 'sometimes';
        }

        if (! $body->required) {
            return static function (string $name, Schema $property) use ($schema, $walk): string {
                if (! in_array($name, $schema->required, true)) {
                    return 'sometimes';
                }

                $siblings = array_values(array_filter(
                    array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required]),
                    static fn (string $other): bool => $other !== $name && self::unescapableKey($other) === null,
                ));

                // The key each sibling answers to, which is not always the name the contract writes.
                $keys = array_values(array_unique(array_merge(
                    ...array_map(static fn (string $other): array => self::triggerKeys($other, $walk, ''), $siblings),
                )));

                return $keys === [] ? 'sometimes' : self::withRule($keys);
            };
        }

        return static fn (string $name, Schema $property): string => in_array($name, $schema->required, true)
            ? self::requiredRule($property)
            : 'sometimes';
    }

    /**
     * The keys a JSON object sent as an optional body must carry, by the rule their failure carries.
     *
     * **A rule cannot see `{}`**: every key is out of the data, so `present_with` asks for nothing. The request reads
     * the raw content instead, and this is what it reads: each required name, with the rule it would get in a required
     * body (`present` for a name `properties` does not declare). Only a body read as JSON gets one, and not a `PATCH`,
     * which asks for nothing. Left out are the names the rules leave out for their own reason, whose findings stay
     * true: a `*` name, a `readOnly` property, and a name a form client sends under another key (withheld, or
     * unchecked, which a JSON client would otherwise be refused for).
     *
     * @return array<string, string>
     *
     * @throws ConflictingInputException
     */
    private static function requiredOnceSent(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): array
    {
        if ($body->required || ! $walk->jsonBody || $operation->method === HttpMethod::Patch) {
            return [];
        }

        $keys = [];

        foreach ($schema->required as $name) {
            if (self::unescapableKey($name) !== null || isset($walk->withheld[$name]) || isset($walk->unchecked[$name])) {
                continue;
            }

            if (! isset($schema->properties[$name])) {
                $keys[$name] = 'present';

                continue;
            }

            $property = $schema->properties[$name];

            if (! self::isReadOnly($property, $walk, '`'.$name.'`')) {
                $keys[$name] = self::requiredRule($property);
            }
        }

        return $keys;
    }

    /**
     * Every property of one object, keyed under its parent, with the presence a caller decides for the required ones.
     *
     * @param  callable(string, Schema): string  $presence  how a named property
     *                                                      is asked for, before
     *                                                      `dependentRequired`
     * @param  array<string, string>  $contentTypes  the `encoding` of the body's top-level parts, by name: only the
     *                                               root passes one, so a nested property is never narrowed by it
     *
     * @throws ConflictingInputException
     */
    private static function objectChildren(
        RuleWalk $walk,
        string $prefix,
        Schema $schema,
        callable $presence,
        array $contentTypes = [],
    ): void {
        $dependents = self::dependents($schema, $walk, $prefix);

        foreach ($schema->properties as $name => $property) {
            $name = (string) $name;
            $unescapable = self::unescapableKey($name);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;
                self::leaveFieldUnenforced(
                    $walk,
                    '`'.$prefix.$name.'`',
                    $property,
                    in_array($name, $schema->required, true),
                    isset($dependents[$name]),
                );

                continue;
            }

            // Its finding was written when the names were planned.
            if ($prefix === '' && isset($walk->withheld[$name])) {
                continue;
            }

            $asked = $presence($name, $property);

            if ($asked === 'sometimes' && isset($dependents[$name])) {
                $asked = self::withRule($dependents[$name]);
            }

            // One rule serves a JSON client and a form client, and a presence rule keyed by the
            // JSON name would refuse every form client, which sends it under another name.
            if ($prefix === '' && isset($walk->unchecked[$name])) {
                $asked = 'sometimes';
            }

            // A request never has to carry what only a response does: OpenAPI
            // reads `required` on a `readOnly` property as a promise about
            // responses. Its other rules still judge it when it is sent.
            if ($asked !== 'sometimes' && self::isReadOnly($property, $walk, '`'.$prefix.$name.'`')) {
                $walk->findings[] = self::readOnlyFinding('`'.$prefix.$name.'`');
                $asked = 'sometimes';
            }

            if ($prefix === '') {
                $walk->rootAsksPresence = $walk->rootAsksPresence || self::refusesAbsence($asked);
            }

            self::field($walk, self::fieldKey($name, $walk, $prefix), '`'.$prefix.$name.'`', $property, $asked, $contentTypes[$name] ?? null);
        }

        // A name the contract requires, or makes required by
        // `dependentRequired`, that `properties` does not declare is legal
        // OpenAPI and the ordinary way to say "this key must be present, any
        // value". Presence is the whole of what the contract states about it,
        // so presence is the whole rule — and `present` rather than
        // `required`, because Laravel's refuses `null`, `""` and `[]`.
        $undeclared = [];

        foreach ([...$schema->required, ...array_keys($dependents)] as $name) {
            if (isset($schema->properties[$name]) || in_array($name, $undeclared, true)) {
                continue;
            }

            $unescapable = self::unescapableKey($name);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;
                self::leaveFieldUnenforced(
                    $walk,
                    '`'.$prefix.$name.'`',
                    new Schema,
                    in_array($name, $schema->required, true),
                    isset($dependents[$name]),
                );

                continue;
            }

            // Written when the names were planned: no rule for it, and for a name a form client sends
            // under another one, a rule keyed by the JSON name would refuse that client.
            if ($prefix === '' && isset($walk->unchecked[$name])) {
                continue;
            }

            $asked = $presence($name, new Schema);

            if ($asked === 'sometimes') {
                if (! isset($dependents[$name])) {
                    continue;
                }

                $asked = self::withRule($dependents[$name]);
            }

            if ($prefix === '') {
                $walk->rootAsksPresence = $walk->rootAsksPresence || self::refusesAbsence($asked);
            }

            $walk->rules[self::fieldKey($name, $walk, $prefix)] = [$asked];
            $undeclared[] = $name;
        }

        if ($undeclared !== []) {
            $walk->findings[] = sprintf(
                '%s requires %s without declaring %s under `properties`, so the rule set asks for the '
                    .'key and says nothing about the value.',
                $prefix === '' ? 'The body' : '`'.rtrim($prefix, '.').'`',
                self::list($undeclared),
                count($undeclared) === 1 ? 'it' : 'them',
            );
        }
    }

    /**
     * `dependentRequired`, read the other way round: for each property it names, the keys whose presence makes it
     * required.
     *
     * @return array<string, list<string>> a dependent name => trigger rule keys
     */
    private static function dependents(Schema $schema, RuleWalk $walk, string $prefix): array
    {
        $dependents = [];

        foreach ($schema->dependentRequired as $trigger => $names) {
            $trigger = (string) $trigger;
            $unescapable = self::unescapableKey($trigger);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;

                continue;
            }

            $keys = self::triggerKeys($trigger, $walk, $prefix);

            if ($keys === []) {
                // The name itself was reported when the names were planned: this says what it costs.
                $walk->findings[] = sprintf(
                    '`%s` would make %s required, but it has no rule, so %s not asked for through it.',
                    $trigger,
                    self::list(array_map('strval', $names)),
                    count($names) === 1 ? 'that is' : 'those are',
                );

                continue;
            }

            foreach ($names as $name) {
                foreach ($keys as $key) {
                    $dependents[$name][] = $prefix.$key;
                }
            }
        }

        return $dependents;
    }

    /**
     * One field, every rule it produces, and a finding for everything it states that none of them carries.
     *
     * @param  string  $presence  how the key itself is asked for, or the empty string for an array element, which
     *                            exists by being in the array
     * @param  string|null  $contentType  the `encoding.<part>.contentType` the document wrote for this field, which
     *                                    only a top-level part of the body, or an item of one, is ever given. A file
     *                                    part narrows its types from it, and a field that is not one reports it
     *
     * @throws ConflictingInputException
     */
    private static function field(
        RuleWalk $walk,
        string $key,
        string $label,
        Schema $written,
        string $presence,
        ?string $contentType = null,
    ): void {
        $schema = AllOfMerger::merge($written, $walk->identity, $label);
        $rules = $presence === '' ? [] : [$presence];

        if ($contentType !== null && ! self::readsEncoding($schema, $walk, $label)) {
            self::reportUnreadEncoding($walk, $label, $schema, $contentType);
            $contentType = null;
        }

        if ($schema->recursesTo !== null) {
            self::recursion($walk, $key, $label, $schema, $presence);

            return;
        }

        $outside = self::enter($walk, $written, $schema, $label);

        $handled = ['allOf', 'type'];
        // A value has to satisfy the type list and the enumeration at once,
        // so `null` is allowed only when both allow it: `type: [string, null]`
        // with `enum: [a]` refuses it, and so does `type: string` with
        // `enum: [a, null]`. A field stating neither constrains nothing.
        if (self::statesNullable($schema)) {
            $rules[] = 'nullable';
        }

        $children = null;

        $type = $schema->soleType();

        // A file part is a part of a multipart body the schema says is one,
        // and a string the moment the body can also be JSON. A schema that
        // says both "file" and some other type is not read as a file: the
        // other type is what the rules would answer to.
        $isFile = self::isFile($schema, $walk->multipart);

        if ($isFile) {
            // `required` rather than `present`: Laravel's `required` is the
            // rule that means "a file was sent", and an empty upload slot is
            // not one. A nullable part keeps `present`.
            if ($rules !== [] && $rules[0] === 'present' && ! $schema->isNullable()) {
                $rules[0] = 'required';
            }

            self::fileRules($schema, $label, $rules, $handled, $walk, $contentType);
        } else {
            switch ($type) {
                case SchemaType::String:
                    $rules[] = 'string';
                    self::stringRules($schema, $label, $rules, $handled, $walk);
                    break;
                case SchemaType::Integer:
                    // Why: Laravel's `min` and `max` find `Integer` and `Numeric` by the rule's name whatever its
                    // parameters, so a strict rule still compares as a number.
                    $rules[] = $walk->strictJson() ? 'integer:strict' : 'integer';
                    self::numberRules($schema, $rules, $handled);

                    if ($walk->strictJson() && ! $walk->integralFloatNamed) {
                        $walk->integralFloatNamed = true;
                        $walk->stricter(
                            'An `integer` in this JSON body is `integer:strict`, which refuses a whole number written '
                                .'with a fraction or an exponent (`1.0`, `1e2`). JSON Schema 2020-12 reads those as '
                                .'integers, so the request is stricter than the contract for them. The same rule is '
                                .'what refuses `"42"` and `true`.',
                        );
                    }

                    if ($walk->mixedBody) {
                        $walk->findings[] = self::mixedBodyFinding($label, 'an integer', 'integer', '`"42"` and `true` get through, though the contract refuses them.');
                    }
                    break;
                case SchemaType::Number:
                    $rules[] = $walk->strictJson() ? 'numeric:strict' : 'numeric';
                    self::numberRules($schema, $rules, $handled);

                    if ($walk->mixedBody) {
                        $walk->findings[] = self::mixedBodyFinding($label, 'a number', 'numeric', '`"1.5"` gets through, though the contract refuses it.');
                    }
                    break;
                case SchemaType::Boolean:
                    if ($walk->textSource !== null) {
                        $rules[] = self::textBoolean($schema, $label, $walk, $handled);
                    } else {
                        $rules[] = $walk->strictJson() ? 'boolean:strict' : 'boolean';

                        if ($walk->mixedBody) {
                            $walk->findings[] = self::mixedBodyFinding(
                                $label,
                                'a boolean',
                                'boolean',
                                '`"1"` and `1` get through, though the contract refuses them, and a form\'s `"true"` is refused, though the contract accepts it.',
                            );
                        }
                    }
                    break;
                case SchemaType::Array:
                    // `list` beside `array`, because Laravel's `array` passes for
                    // an associative one, and a JSON array is a list.
                    array_push($rules, 'array', 'list');
                    self::bound($schema->minItems, 'min', $rules);
                    self::bound($schema->maxItems, 'max', $rules);
                    array_push($handled, 'minItems', 'maxItems', 'items');
                    $children = 'items';
                    break;
                case SchemaType::Object:
                    // A JSON object arrives as a PHP array, and `array:` with the
                    // declared keys is how a nested object refuses the ones it did
                    // not declare.
                    $declared = array_map('strval', array_keys($schema->properties));
                    $structural = array_values(array_filter($declared, self::breaksKeyList(...)));

                    if ($schema->additionalProperties === false && $structural !== []) {
                        // `array:` names keys as a comma list compared against
                        // keys Laravel has already split on dots, so a name with
                        // either would refuse every valid payload.
                        $rules[] = 'array';
                        $walk->findings[] = sprintf(
                            '%s forbids undeclared keys, but declares %s, which Laravel cannot name in `array:`, '
                                .'so undeclared keys are not refused.',
                            $label,
                            self::list($structural),
                        );
                    } else {
                        $rules[] = $schema->additionalProperties === false ? 'array:'.implode(',', $declared) : 'array';
                    }

                    // JSON Schema's `required` asks for keys, and so does
                    // `required_array_keys`, on the object itself. Putting it on
                    // the parent rather than a `present_with` on each child is what
                    // lets a nullable object be `null`: a child's rule would fire
                    // on the parent's key being present whatever its value, while
                    // `nullable` on the parent skips this one.
                    $requiredKeys = [];

                    foreach ($schema->required as $name) {
                        if (self::breaksKeyList($name)) {
                            // The rule's parameters are comma-separated and the
                            // data's keys are split on dots before it runs, so
                            // either character makes this name match nothing.
                            $walk->findings[] = sprintf(
                                '%s requires `%s`, which Laravel cannot name in `required_array_keys`, so its '
                                    .'presence is not enforced.',
                                $label,
                                $name,
                            );

                            continue;
                        }

                        if (isset($schema->properties[$name]) && self::isReadOnly($schema->properties[$name], $walk, $label)) {
                            $walk->findings[] = self::readOnlyFinding(rtrim($label, '`').'.'.$name.'`');

                            continue;
                        }

                        if (self::unescapableKey($name) === null) {
                            $requiredKeys[] = $name;
                        }
                    }

                    if ($requiredKeys !== []) {
                        $rules[] = 'required_array_keys:'.implode(',', $requiredKeys);
                    }
                    array_push($handled, 'properties', 'required', 'dependentRequired', 'additionalProperties');
                    $children = 'properties';
                    break;
                default:
                    $unmapped = self::isStringEnumeration($schema) ? null : self::unmappedType($schema);

                    if ($unmapped !== null) {
                        // Without a type rule Laravel reads `min` and `max` as a string
                        // length, so a bound on an untyped field is reported rather than
                        // half-translated.
                        $walk->findings[] = sprintf('%s %s, so no type rule is emitted for it.', $label, $unmapped);
                    }
            }
        }

        if ($schema->enum !== null && ! in_array('enum', $handled, true)) {
            $values = array_values(array_filter($schema->enum, static fn (mixed $value): bool => $value !== null));

            // `Rule::in` compares as strings, so an untyped enumeration of
            // strings would accept the integer `1` for `"1"`. Every allowed
            // value being a string is the contract saying the field is one.
            if (self::isStringEnumeration($schema)) {
                $rules[] = 'string';
            }

            // An enumeration of `null` alone allows nothing but `null`, which
            // no Laravel rule says exactly, so it is left for the sweep below
            // to report rather than read as "anything".
            if ($values !== [] && self::allScalar($values)) {
                $rules[] = new InRule($values);
                $handled[] = 'enum';
            }
        }

        $walk->rules[$key] = $rules;

        if ($children === 'items') {
            if ($schema->items !== null) {
                self::field($walk, $key.'.*', $label.'[]', $schema->items, '', $contentType);
            }

            if ($schema->uniqueItems) {
                $uniqueness = self::uniquenessRule($key, $schema, $walk, $label);

                if ($uniqueness !== null) {
                    $walk->rules[$key.'.*'] = [...$walk->rules[$key.'.*'] ?? [], $uniqueness];
                    $handled[] = 'uniqueItems';
                }
            }
        }

        if ($children === 'properties') {
            // The required keys are the parent's `required_array_keys`, so a
            // child's own presence rule is `sometimes` either way: its value is
            // judged when it is sent, and its key when its object is.
            self::objectChildren(
                $walk,
                $key.'.',
                $schema,
                static fn (string $name, Schema $property): string => 'sometimes',
            );
        }

        self::leave($walk, $outside);

        self::reportUnhandled($walk, $label, $schema, $handled);
    }

    /**
     * Put the definitions of the named schemas a field is written as on the stack a recursion marker looks one up in.
     *
     * **The definition, never the merged site.** A field written as `allOf: [Category, {required: [name]}]` merges into
     * one schema that takes `Category`'s name, and expanding that at a marker would re-impose the `required` at every
     * level, where only the wrapper wrote it. So a named schema is pushed as its own definition (its own `allOf` merged
     * in), and a wrapper with no name of its own pushes its named branches, each one merged on its own.
     *
     * @param  Schema  $written  the schema as the contract writes the field
     * @param  Schema  $merged  the same, with its `allOf` merged: pushed as it is when `$written` is named
     * @return int how many definitions were on the stack before, which {@see self::leave()} takes back to
     *
     * @throws ConflictingInputException
     */
    private static function enter(RuleWalk $walk, Schema $written, Schema $merged, string $label): int
    {
        $before = count($walk->ancestors);
        array_push($walk->ancestors, ...self::definitions($written, $merged, $walk->identity, $label));

        return $before;
    }

    private static function leave(RuleWalk $walk, int $before): void
    {
        $walk->ancestors = array_slice($walk->ancestors, 0, $before);
    }

    /**
     * The definitions of the named schemas a field is written as, which a recursion marker below it can point back at.
     *
     * @param  Schema|null  $merged  `$written` merged, when the caller already has it
     * @return list<Schema>
     *
     * @throws ConflictingInputException
     *
     * @internal Public so the input DTO keeps the stack the rules keep, and finds the same ancestor for a marker.
     */
    public static function definitions(Schema $written, ?Schema $merged, string $identity, string $label): array
    {
        $definitions = $written->source === null
            ? []
            : [$merged ?? AllOfMerger::merge($written, $identity, $label)];

        foreach ($written->allOf as $branch) {
            array_push($definitions, ...self::definitions($branch, null, $identity, $label));
        }

        return $definitions;
    }

    /**
     * A recursion marker: the ancestor it points back at, expanded once more, down to the depth that ancestor states.
     *
     * **A level is one crossing of a marker**, and the limit at a marker is the `x-max-depth` of the schema it points
     * to (three when it states none), so the two sides of a recursive pair never have to agree. At the limit the field
     * is cut, and what it is cut to depends on the schema: `missing` (the key must be absent) when the schema is not
     * nullable, and {@see NullOrAbsent} (absent, or `null`) when it is. The finding that says so is written once the
     * walk is over ({@see self::reportCuts()}). The rules unroll here and the contract's normal form does not change,
     * since a rule set is the one reader that needs a finite tree.
     *
     * **What is expanded is the ancestor with what the marker's site wrote beside the marker**, as an `allOf`: a
     * constraint written next to the marker holds at every level the marker is crossed at, and a constraint an outer
     * wrapper wrote is not in the ancestor at all, so it stays at the wrapper's own level.
     *
     * **The limit is the stated depth, or the cap the ceiling lowered it to** ({@see self::MAX_UNROLLED_KEYS}): one cap
     * for the operation, applied to every recursive schema deeper than it, so every branch is cut at the same level and
     * none is left unvalidated. A walk that passes the ceiling stops expanding and is thrown away, which is how
     * {@see self::for()} knows to try a lower cap.
     *
     * {@see self::unrolls()} says which markers this expands. A marker whose ancestor the walk does not hold has no
     * depth to read: an inline schema has no name to be found by. It keeps presence and a finding, as it always did,
     * and so does a field that is two markers at once (two branches of an `allOf` writing a marker to two different
     * components under one name): the walk follows one ancestor, and the other would be dropped without a word.
     *
     * @param  Schema  $site  the field the marker is written as, merged: the marker and whatever is written beside it
     * @param  string  $presence  how the field is asked for. Beside `missing` it stays when the contract asks for the
     *                            key, so a payload that reaches the cut is refused instead of passing looser than the
     *                            contract; `sometimes` and an array element's empty presence say nothing and are
     *                            dropped. Beside {@see NullOrAbsent} it always stays
     *
     * @throws ConflictingInputException
     */
    private static function recursion(RuleWalk $walk, string $key, string $label, Schema $site, string $presence): void
    {
        if ($walk->exceeded) {
            return;
        }

        $position = (string) $site->recursesTo;
        $ancestor = $walk->ancestor($position);
        $rules = $presence === '' ? [] : [$presence];

        if ($ancestor === null || ! self::unrolls($site, $ancestor)) {
            // A self-referential schema is a supported contract and an
            // infinite tree, and no rule set reaches an infinite depth.
            $walk->rules[$key] = $rules;
            $walk->findings[] = sprintf(
                '%s points back at `%s`, so nothing below it is validated: no rule set reaches an '
                    .'infinite depth.',
                $label,
                implode('` and `', [$position, ...array_map(
                    static fn (Schema $branch): string => (string) $branch->recursesTo,
                    $site->allOf,
                )]),
            );

            return;
        }

        $requested = $ancestor->maxDepth ?? self::DEFAULT_MAX_DEPTH;
        $walk->deepest = max($walk->deepest, $requested);
        $walk->requested[$position] = $requested;
        $depth = min($requested, $walk->depthCap ?? $requested, $walk->caps[$position] ?? $requested);
        $crossed = $walk->crossings[$position] ?? 0;
        $nullable = self::statesNullable($ancestor);

        if ($crossed >= $depth) {
            // A nullable schema allows `null` at the cut and the request still validates nothing below it, so the field
            // takes the one value the contract allows there. No rule Laravel ships says "null or absent": `missing`
            // refuses `null`, `prohibited` lets `""` and `[]` through, and `nullable` does not skip an implicit rule.
            // The presence the contract asks of the key stays beside it. It stays beside `missing` too, which makes a
            // required key at the cut unsatisfiable, as the contract's own infinite tree is: below the root, `sometimes`
            // is dropped, and the parent's `required_array_keys` is what asks.
            $asked = in_array($presence, ['', 'sometimes'], true) ? [] : [$presence];
            $walk->rules[$key] = $nullable ? [...$rules, new NullOrAbsent] : [...$asked, 'missing'];
            $walk->cuts[$position] ??= [
                'name' => $ancestor->name ?? $position,
                'depth' => $depth,
                'requested' => $requested,
                'stated' => $ancestor->maxDepth !== null,
                'nullable' => $nullable,
                'keys' => [],
            ];
            $walk->cuts[$position]['keys'][] = $key;

            return;
        }

        if ($walk->unrolledKeys() > self::MAX_UNROLLED_KEYS) {
            $walk->exceeded = true;

            return;
        }

        $beside = AllOfMerger::besideMarker($site);
        $outermost = $walk->expansionStart === null;

        if ($outermost) {
            $walk->expansionStart = count($walk->rules);
        }

        $before = count($walk->rules);
        $attributed = $walk->attributed;

        $walk->crossings[$position] = $crossed + 1;
        self::field($walk, $key, $label, $beside === null ? $ancestor : new Schema(allOf: [$ancestor, $beside]), $presence);
        $walk->crossings[$position] = $crossed;

        // The keys this expansion wrote itself, which is what the schema weighs when two are tied on a depth.
        $own = count($walk->rules) - $before - ($walk->attributed - $attributed);
        $walk->keysBy[$position] = ($walk->keysBy[$position] ?? 0) + $own;
        $walk->attributed += $own;

        if ($outermost) {
            $walk->unrolled = $walk->unrolledKeys();
            $walk->expansionStart = null;
        }
    }

    /**
     * Whether the request unrolls a recursion marker, so that a schema below it is validated down to a depth.
     *
     * One answer for both sides, since the input DTO types a marker as its own class only where the request validated
     * what the class is built from. **The ancestor the walk holds decides**, never what the merged site is called: a
     * site takes the name and source of the first named schema merged into it (`allOf: [Mixin, Node]`), and it still
     * points back at `Node`. A marker with no ancestor (an inline schema has no name to be found by) and a field that
     * is two markers at once (an `allOf` left holding a marker branch: the walk follows one ancestor, and the other
     * would be dropped without a word) are not unrolled. They keep presence and a finding.
     *
     * @param  Schema  $site  the field the marker is written as, merged
     * @param  Schema|null  $ancestor  the definition a walk holds at the position the marker points back at
     *
     * @internal Public so the input DTO asks the question the rules did.
     */
    public static function unrolls(Schema $site, ?Schema $ancestor): bool
    {
        return $site->recursesTo !== null && $ancestor !== null && $site->allOf === [];
    }

    /**
     * One finding for each recursive schema the walk cut.
     *
     * **A cut is stricter than the contract, and said so**, which is the one way the request may be. A payload deeper
     * than the depth is valid against a schema that states no limit, so it answers 422, and `x-max-depth` is the lever.
     * What happens to the value at the cut is two rules and the finding says which:
     *
     * - **`missing`**, for a schema that is not nullable: the key must be absent, so `null`, `""` and `[]` are refused
     *   too. Laravel's `prohibited` would have let those through, and a typed DTO built from them would be a second
     *   answer to the same question.
     * - **`NullOrAbsent`**, for a nullable schema: absent or `null` passes, and `""` and `[]` do not.
     *
     * Where the cut is a list's elements, an empty list passes in both cases: it carries no data, and no element is
     * there to refuse. When the ceiling lowered the depth, the finding says what the contract stated, what applies and
     * why, so a depth the author wrote is never reported as one it did not.
     */
    private static function reportCuts(RuleWalk $walk): void
    {
        foreach ($walk->cuts as $cut) {
            $lists = array_filter($cut['keys'], static fn (string $key): bool => str_ends_with($key, '.*')) !== [];
            $fields = count(array_filter($cut['keys'], static fn (string $key): bool => ! str_ends_with($key, '.*'))) > 0;

            $walk->stricter(sprintf(
                '`%1$s` recurses, and %2$s. %3$s %6$s %4$s below that: a payload deeper than that answers 422, though '
                    .'the contract allows it. %5$s `x-max-depth` on `%1$s` sets the depth.',
                $cut['name'],
                self::depthClause($cut['stated'], $cut['requested'], $cut['depth']),
                self::keysClause($cut['keys']),
                $cut['nullable'] ? '`NullOrAbsent` (the field must be absent, or `null`)' : '`missing` (the field must be absent)',
                implode(' ', array_filter([
                    $cut['nullable']
                        ? ($fields ? sprintf('`null` passes because `%s` is nullable; any other value, `""` and `[]` among them, answers 422.', $cut['name']) : sprintf('`null` passes because `%s` is nullable.', $cut['name']))
                        : sprintf(
                            '`null` passes only where the schema is nullable, and `%s` is not%s.',
                            $cut['name'],
                            $fields ? ', so `null`, `""` and `[]` answer 422 there too' : '',
                        ),
                    $lists ? sprintf('An empty list of `%s` passes: it carries no data, and it is its elements that are cut.', $cut['name']) : null,
                ])),
                count($cut['keys']) === 1 ? 'is' : 'are',
            ));
        }
    }

    /**
     * What a finding says about the depth of a recursive schema: whether the document stated it, how deep that is, and
     * how deep the request validates it when the ceiling lowered it.
     */
    private static function depthClause(bool $stated, int $requested, int $applied): string
    {
        $levels = sprintf('%d %s deep', $applied, $applied === 1 ? 'level' : 'levels');

        if ($applied === $requested) {
            return $stated
                ? sprintf('it states `x-max-depth: %d`, so the request validates it %s', $requested, $levels)
                : sprintf('it states no `x-max-depth`, so the request validates it %s, the package\'s default', $levels);
        }

        return sprintf(
            '%s, but that is more than the ceiling of %d rules a request unrolls for one operation, so the request '
                .'validates it %s on every branch',
            $stated
                ? sprintf('it states `x-max-depth: %d`', $requested)
                : sprintf('it states no `x-max-depth`, so the package\'s default of %d applies', $requested),
            self::MAX_UNROLLED_KEYS,
            $levels,
        );
    }

    /**
     * The first rule key of a list and how many more there are, for a finding.
     *
     * @param  list<string>  $keys
     */
    private static function keysClause(array $keys): string
    {
        $more = count($keys) - 1;

        return sprintf('`%s`%s', $keys[0], $more > 0 ? sprintf(' and %d more', $more) : '');
    }

    /**
     * Whether a property is `readOnly`, once its `allOf` is folded.
     *
     * @throws ConflictingInputException
     */
    private static function isReadOnly(Schema $property, RuleWalk $walk, string $label): bool
    {
        return AllOfMerger::merge($property, $walk->identity, $label)->readOnly;
    }

    private static function readOnlyFinding(string $label): string
    {
        return sprintf(
            '%s is `readOnly` and required. `required` on a `readOnly` property applies to responses '
                .'only: 3.0 states it, and a 3.1 document is read the same way (3.1 defers to JSON Schema, '
                .'where `readOnly` is an annotation). So a request does not have to carry it and no '
                .'presence rule is emitted. Its other rules judge it when it is sent.',
            $label,
        );
    }

    /**
     * What a body that is JSON and a form says of a scalar field: it keeps the loose rule, and the sentence names what
     * a JSON client gets through. A gap rather than a stricter rule, so it counts in the build warning, and it leaves
     * nothing unenforced for the doctor to report.
     */
    private static function mixedBodyFinding(string $label, string $noun, string $rule, string $effect): string
    {
        return sprintf(
            '%s is %s in a body that is JSON and also a form. One rule set cannot read the text a form carries and '
                .'still refuse a string in JSON, so it keeps `%s`: a JSON client\'s %s',
            $label,
            $noun,
            $rule,
            $effect,
        );
    }

    /**
     * The rule for a boolean whose value arrives as text.
     *
     * **Laravel's `boolean` refuses `"true"` and `"false"`**, and those are how OpenAPI's default `form` style writes a
     * boolean in a query string (`?notify=true`), and how a form or multipart body carries one. Refusing them answers
     * 422 to every generated client for a contract it follows, so `true`, `false`, `1` and `0` are accepted, as text.
     * `false` is not in the list as a PHP value: `Rule::in` compares `(string) false`, which is the empty string, and
     * that would accept the `null` an empty value becomes. `validated()` holds the string as sent, which the finding
     * says.
     *
     * An enumeration of booleans is read as every spelling of its values, since `Rule::in([true])` would accept `1` and
     * refuse `true`.
     *
     * @param  list<string>  $handled
     */
    private static function textBoolean(Schema $schema, string $label, RuleWalk $walk, array &$handled): InRule
    {
        $spellings = ['true', 'false', '1', '0'];

        if ($schema->enum !== null && array_filter($schema->enum, is_bool(...)) !== []) {
            $spellings = [];

            foreach ($schema->enum as $value) {
                if ($value === true) {
                    array_push($spellings, 'true', '1');
                } elseif ($value === false) {
                    array_push($spellings, 'false', '0');
                }
            }

            $handled[] = 'enum';
        }

        // The contract writes `true` and `false`. `1` and `0` are the looser half of the
        // rule, which the maintainer kept, so the finding names them.
        $quote = static fn (array $list): string => implode(' and ', array_map(
            static fn (string $spelling): string => '`"'.$spelling.'"`',
            array_values($list),
        ));
        $words = array_intersect(['true', 'false'], $spellings);
        $digits = array_intersect(['1', '0'], $spellings);
        $tooMany = $digits === []
            ? ''
            : sprintf(' %s %s accepted too, which the contract does not write for a boolean.', $quote($digits), count($digits) === 1 ? 'is' : 'are');

        $walk->findings[] = $walk->textSource === 'query string'
            ? sprintf(
                '%1$s is a boolean read from a query string, where `?name=true` is how OpenAPI writes `true` and '
                    .'Laravel\'s `boolean` refuses it. It accepts %4$s.%2$s '
                    .'`validated(\'%3$s\')` holds the string as sent, and `"false"` is a truthy string in PHP: '
                    .'read it with `$request->query->has(\'%3$s\') ? filter_var($request->validated(\'%3$s\'), '
                    .'FILTER_VALIDATE_BOOL) : null`, which is `null` when it was not sent.',
                $label,
                $tooMany,
                trim($label, '`'),
                $quote($words),
            )
            : sprintf(
                '%1$s is a boolean read from a form body, where every value is text and Laravel\'s `boolean` '
                    .'refuses `"true"`. It accepts %3$s.%2$s `validated()` holds the '
                    .'string as sent, which `dto()` reads as a `bool`.',
                $label,
                $tooMany,
                $quote($words),
            );

        return new InRule($spellings);
    }

    /**
     * Whether a schema is read as a file: the schema says so, the body is `multipart/form-data` alone, and the schema
     * does not also say it is some other type, since the other type is what its rules would answer to.
     *
     * @internal Public so the input DTO asks the question the rules did.
     */
    public static function isFile(Schema $schema, bool $multipart): bool
    {
        return $multipart
            && $schema->isFilePart
            && ($schema->soleType() === SchemaType::String || $schema->types === []);
    }

    /**
     * What a file part becomes: `file`, the types it names, and a size in kilobytes.
     *
     * **`file` instead of `string`**, because `string` refuses an upload, which is worse than leaving the rule out.
     *
     * **The types come from `encoding.<part>.contentType` when the contract writes one, and from `contentMediaType`
     * otherwise.** `encoding` wins even when it is looser, with no finding: it is the one the contract puts nearest the
     * upload (`uploads.md`). A list is split on commas, each entry cut at its parameters and lower-cased, and the
     * result is `mimetypes:a,b`; `image/*` stays, since Laravel matches wildcards. A `contentType` that is blank after
     * that says nothing, so `contentMediaType` applies.
     *
     * **A text type is not one to match either, and a finding says so.** See {@see self::isSniffedAsAnotherType()}:
     * `mimetypes:text/csv` refuses every real CSV. One text type in a list drops the whole rule (a rule for the others
     * would refuse the CSV the contract accepts), and `encoding` is recorded as unenforced on the field.
     *
     * **`application/octet-stream` and the any-type wildcard are not types to match.** They are how a contract says
     * "any bytes", and `mimetypes` inspects the file's real type, so it would turn away every upload that is not
     * literally an octet stream: a rule stricter than the contract, in the direction of refusing valid payloads. One of
     * them in a list does the same, since the rest of the list adds nothing the contract refuses.
     *
     * **`maxLength` is bytes and `max` is kilobytes, and the ceiling is exact.** Laravel's `max` takes a decimal, so
     * 2500 bytes is `max:2.44140625`.
     *
     * @param  list<string|InRule|NullOrAbsent>  $rules
     * @param  list<string>  $handled
     * @param  string|null  $contentType  the `encoding` the document wrote for this part, as written
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    private static function fileRules(Schema $schema, string $label, array &$rules, array &$handled, RuleWalk $walk, ?string $contentType = null): void
    {
        $rules[] = 'file';
        array_push($handled, 'isFilePart', 'contentMediaType', 'maxLength');

        $types = $contentType === null ? [] : self::contentTypes($contentType);
        $encoded = $types !== [];

        if (! $encoded) {
            // Parameters (`; charset=...`) are not part of the type Laravel reads.
            $types = $schema->contentMediaType === null ? [] : self::contentTypes($schema->contentMediaType, false);
        }

        $sniffed = array_values(array_filter($types, self::isSniffedAsAnotherType(...)));

        if (array_intersect($types, ['application/octet-stream', '*/*']) !== []) {
            // Any bytes: nothing to match.
        } elseif ($sniffed !== []) {
            $walk->findings[] = $encoded
                ? sprintf(
                    'The `encoding` of %s declares `contentType: %s`, and no `mimetypes` rule is emitted for it: Laravel '
                        .'reads the type libmagic sniffs from the content, which is never `%s` for a text file, so a rule '
                        .'listing it would refuse every real file of that type, and the list is left out whole rather '
                        .'than refuse them.',
                    $label,
                    trim((string) $contentType),
                    $sniffed[0],
                )
                : sprintf(
                    '%s declares `contentMediaType: %s`, and no `mimetypes` rule is emitted for it: Laravel reads the '
                        .'type libmagic sniffs from the content, which is never that one for a text file, so the rule '
                        .'would refuse every real upload.',
                    $label,
                    $sniffed[0],
                );
            $walk->leaveUnenforced($encoded ? 'encoding' : 'contentMediaType', $label);
        } elseif ($types !== []) {
            $rules[] = 'mimetypes:'.implode(',', $types);
            // The one stricter-than-the-contract case here, and the exception the principle
            // allows: Laravel's `mimetypes` refuses a file whose name ends in a PHP extension
            // whatever its type, and nothing short of overriding that security default
            // from a generated class matches the contract exactly.
            if (! $walk->phpUploadNamed) {
                $walk->phpUploadNamed = true;
                $walk->stricter('A `mimetypes` rule in this request is stricter than the contract in one case: '
                    .'Laravel refuses a file whose name ends in a PHP extension (`php`, `phtml`, `phar` and the '
                    .'like) whatever its type, a security default no rule turns off, and matching the contract '
                    .'there would mean overriding it from a generated class.');
            }
        }

        // A decimal, because Laravel's `max` compares with a `BigNumber` and takes
        // one: `max:2.44140625` is 2500 bytes exactly. A byte count over 1024 always
        // has a finite decimal expansion, so the ceiling is exact to the byte.
        if ($schema->maxLength !== null) {
            $rules[] = 'max:'.self::number($schema->maxLength / 1024);
        }
    }

    /**
     * The media types a `contentType` (or a `contentMediaType`) names: split on commas, each cut at its parameters,
     * trimmed and lower-cased, a type written twice kept once, a blank entry dropped.
     *
     * @param  bool  $list  whether commas separate several types: a `contentMediaType` is one
     * @return list<string>
     */
    private static function contentTypes(string $written, bool $list = true): array
    {
        $types = [];

        foreach ($list ? explode(',', $written) : [$written] as $entry) {
            $type = strtolower(trim(explode(';', $entry)[0]));

            if ($type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Whether `encoding.<part>.contentType` is something a field acts on: a file part, or an array whose items are one.
     * `encoding` never makes a part a file ({@see self::isFile()} does not read it), so it is read only where the
     * schema and the body already say file.
     *
     * @throws ConflictingInputException
     */
    private static function readsEncoding(Schema $schema, RuleWalk $walk, string $label): bool
    {
        if (self::isFile($schema, $walk->multipart)) {
            return true;
        }

        return $schema->soleType() === SchemaType::Array
            && $schema->items !== null
            && self::isFile(AllOfMerger::merge($schema->items, $walk->identity, $label.'[]'), $walk->multipart);
    }

    /**
     * Say that a `contentType` on a part this request does not read as a file is not enforced, unless every type it
     * lists is one OpenAPI gives that part when it writes none ({@see self::defaultPartTypes()}): a contract that
     * spells out its default has said nothing new. A list with nothing in it says nothing either.
     *
     * @throws ConflictingInputException
     */
    private static function reportUnreadEncoding(RuleWalk $walk, string $label, Schema $schema, string $contentType): void
    {
        $types = self::contentTypes($contentType);

        if (array_diff($types, self::defaultPartTypes($schema, $walk, $label)) === []) {
            return;
        }

        $walk->findings[] = sprintf(
            'The `encoding` of %s declares `contentType: %s`, and no rule is emitted for it: only a file part of a '
                .'multipart body is narrowed by it, and %s.',
            $label,
            trim($contentType),
            // What is true of this part: a schema that is no file is said not to be one, whatever the body, and only a
            // file part is a string because of the body it sits in.
            match (true) {
                $schema->soleType() === SchemaType::Array && ! ($schema->items !== null && $schema->items->isFilePart) => 'its items are not a file part',
                ! $schema->isFilePart && $schema->soleType() !== SchemaType::Array => 'it is not a file part',
                $walk->multipart => 'it is not read as a file part',
                $walk->jsonBody => 'the body is also JSON, which reads a file part as a string',
                default => 'a form-encoded body is read as text',
            },
        );
        $walk->leaveUnenforced('encoding', $label);
    }

    /**
     * The `contentType`s OpenAPI gives a part that writes none, so that spelling one out says nothing new.
     *
     * **The versions disagree, and a contract that spells out a default is not asked which one it meant.** 3.0.x gives
     * `application/octet-stream` to a binary string, `text/plain` to any other primitive, `application/json` to an
     * object and the items' type to an array. 3.1.0 gives `application/json` to an object, the items' type to an array
     * and `application/octet-stream` to all other cases, primitives included. 3.1.1 gives `text/plain` to a string with
     * no `contentEncoding` and to a number, an integer or a boolean, `application/octet-stream` to a part with no
     * `type` or a string with a `contentEncoding`, and the same for an object and an array. This takes the union where
     * they differ (a primitive: both `text/plain` and `application/octet-stream`; a binary part: both too, since 3.1.1
     * reads a `string` with `contentMediaType` as `text/plain`), which reports less and never more. The rule set knows
     * no version, and a default is no constraint, so a union costs a missed finding on a type nothing enforces anyway.
     *
     * @return list<string>
     *
     * @throws ConflictingInputException
     */
    private static function defaultPartTypes(Schema $schema, RuleWalk $walk, string $label): array
    {
        if ($schema->isFilePart) {
            return ['application/octet-stream', 'text/plain'];
        }

        if (self::isObjectBody($schema)) {
            return ['application/json'];
        }

        return match ($schema->soleType()) {
            SchemaType::String, SchemaType::Integer, SchemaType::Number, SchemaType::Boolean => ['text/plain', 'application/octet-stream'],
            SchemaType::Array => $schema->items === null
                ? ['application/octet-stream']
                : self::defaultPartTypes(AllOfMerger::merge($schema->items, $walk->identity, $label.'[]'), $walk, $label.'[]'),
            default => ['application/octet-stream'],
        };
    }

    /**
     * Whether a media type is one libmagic never reports for a file of that type, so that `mimetypes` would refuse
     * every real one.
     *
     * Measured on libmagic 5.41, with real files (a faked upload reports the type it is given and hides this): a CSV, a
     * Markdown file and a YAML file sniff as `text/plain`, an XML file as `text/xml` where the contract says
     * `application/xml`, and a JSON file as `application/json` on this version and `text/plain` on older ones. Text
     * types are the class (`text/*` itself apart, which Laravel matches by its first half), and the textual
     * `application/*` types are the same class: libmagic reads their content, and a content with a name of its own
     * (`application/ld+json`) still comes back as the generic one. `image/svg+xml`, `application/pdf` and the binary
     * types sniff as themselves and keep their rule.
     */
    private static function isSniffedAsAnotherType(string $mediaType): bool
    {
        // `text/*` is the one text entry a rule can hold: Laravel matches a wildcard on the first half of the sniffed type
        // (`text/plain`, `text/xml`), so it holds for every real text file, where a named type does not.
        return ($mediaType !== 'text/*' && str_starts_with($mediaType, 'text/'))
            || in_array($mediaType, ['application/json', 'application/xml', 'application/yaml', 'application/x-yaml'], true)
            || (
                $mediaType !== 'image/svg+xml'
                && (str_ends_with($mediaType, '+json') || str_ends_with($mediaType, '+xml'))
            );
    }

    /**
     * What a string's own keywords become.
     *
     * @param  list<string|InRule|NullOrAbsent>  $rules
     * @param  list<string>  $handled
     */
    private static function stringRules(Schema $schema, string $label, array &$rules, array &$handled, RuleWalk $walk): void
    {
        // Beside `string`, `min` and `max` count characters, which is what
        // `minLength` and `maxLength` count.
        self::bound($schema->minLength, 'min', $rules);
        self::bound($schema->maxLength, 'max', $rules);
        array_push($handled, 'minLength', 'maxLength');

        if ($schema->format !== null) {
            $format = self::FORMAT_RULES[$schema->format] ?? null;

            if ($format !== null) {
                array_push($rules, ...$format);
            } elseif (! in_array($schema->format, self::ANNOTATION_FORMATS, true)) {
                $walk->findings[] = sprintf(
                    '%s declares `format: %s`, which no Laravel rule means the same as, so it is not enforced.',
                    $label,
                    $schema->format,
                );
                $walk->leaveUnenforced('format', $label);
            }

            $handled[] = 'format';
        }

        if ($schema->pattern !== null) {
            $translation = PatternTranslation::of($schema->pattern);

            if ($translation->rule !== null) {
                $rules[] = $translation->rule;
            } else {
                $walk->findings[] = sprintf(
                    '%s declares a `pattern` that %s, so it is not enforced rather than enforced differently.',
                    $label,
                    $translation->reason,
                );
                $walk->leaveUnenforced('pattern', $label);
            }

            $handled[] = 'pattern';
        }
    }

    /**
     * What an integer's or a number's own keywords become.
     *
     * Beside `integer` or `numeric`, `min` and `max` compare the value, and `gt` and `lt` take a number as readily as a
     * field name.
     *
     * @param  list<string|InRule|NullOrAbsent>  $rules
     * @param  list<string>  $handled
     */
    private static function numberRules(Schema $schema, array &$rules, array &$handled): void
    {
        $minimum = $schema->minimum;
        $maximum = $schema->maximum;

        // `int64` is what `integer` already refuses anything outside of, `double` is any JSON number, and `int32` is
        // the one range of the three the type rule does not carry: it is the two bounds, the tighter of each with
        // whatever the contract states beside it. `float` is a 32-bit range with a precision no rule checks, so it is
        // left to be reported with the rest.
        $type = $schema->soleType();

        if ($schema->format === 'int32' && $type === SchemaType::Integer) {
            $minimum = $minimum === null ? self::INT32_MINIMUM : max($minimum, self::INT32_MINIMUM);
            $maximum = $maximum === null ? self::INT32_MAXIMUM : min($maximum, self::INT32_MAXIMUM);
            $handled[] = 'format';
        } elseif (
            ($schema->format === 'int64' && $type === SchemaType::Integer)
            || ($schema->format === 'double' && $type === SchemaType::Number)
            || in_array($schema->format, self::ANNOTATION_FORMATS, true)
        ) {
            $handled[] = 'format';
        }

        self::bound($minimum, 'min', $rules);
        self::bound($maximum, 'max', $rules);
        self::bound($schema->exclusiveMinimum, 'gt', $rules);
        self::bound($schema->exclusiveMaximum, 'lt', $rules);
        self::bound($schema->multipleOf, 'multiple_of', $rules);
        array_push($handled, 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf');
    }

    /**
     * @param  list<string|InRule|NullOrAbsent>  $rules
     */
    private static function bound(int|float|null $value, string $rule, array &$rules): void
    {
        if ($value !== null) {
            $rules[] = $rule.':'.self::number($value);
        }
    }

    /**
     * A number as a rule parameter: `1` rather than `1.0`, and a fraction as PHP prints one.
     */
    private static function number(int|float $value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    /**
     * `distinct:strict`, or null where it would not mean `uniqueItems`.
     *
     * Two shapes break it, both measured on Laravel's validator. Under a wildcard, `distinct` turns every `*` into a
     * pattern and compares across every element of the outer list, so `[{tags: [a]}, {tags: [a]}]` is refused although
     * each list is unique. And on elements that are objects or arrays it flattens them with dots and never compares the
     * elements themselves, so `[{a: 1}, {a: 1}]` passes. It also lets two nulls through. A null leaves the keyword to
     * the sweep, which reports it.
     */
    private static function uniquenessRule(string $key, Schema $schema, RuleWalk $walk, string $label): ?string
    {
        if (str_contains($key, '*') || $schema->items === null) {
            return null;
        }

        // Merged first: `items: {allOf: [...]}` is the 3.0 way of wrapping a
        // `$ref`, and its type is only known once the branches are folded.
        $items = AllOfMerger::merge($schema->items, $walk->identity, $label.'[]');

        // Only a typed scalar that may not be null. An untyped element may be
        // an object `distinct` cannot compare, and `distinct` lets two nulls
        // through, so both are reported rather than half-enforced.
        $scalar = in_array($items->soleType(), [SchemaType::String, SchemaType::Integer, SchemaType::Number, SchemaType::Boolean], true);

        return $scalar && ! $items->isNullable() ? 'distinct:strict' : null;
    }

    /**
     * Whether a key name cannot appear in a rule's list of key names: a comma splits the list, and a dot is read as
     * nesting before the rule sees the data.
     *
     * @internal Public so the input DTO asks the question the rules did, and the two cannot drift apart.
     */
    public static function breaksKeyList(string $name): bool
    {
        return str_contains($name, ',') || str_contains($name, '.');
    }

    /**
     * Whether the schema states something and `null` satisfies it: the `nullable` rule's condition. A field stating
     * neither a type nor an enumeration constrains nothing, so there is nothing for `null` to be an exception to.
     */
    private static function statesNullable(Schema $schema): bool
    {
        return self::allowsNull($schema) && ($schema->types !== [] || $schema->enum !== null);
    }

    /**
     * Whether `null` satisfies both the type list and the enumeration.
     */
    private static function allowsNull(Schema $schema): bool
    {
        return ($schema->types === [] || $schema->isNullable())
            && ($schema->enum === null || in_array(null, $schema->enum, true));
    }

    /**
     * Whether the field states no type and every allowed value is a string, which is the contract saying the field is
     * one.
     */
    private static function isStringEnumeration(Schema $schema): bool
    {
        if ($schema->types !== [] || $schema->enum === null) {
            return false;
        }

        $values = array_values(array_filter($schema->enum, static fn (mixed $value): bool => $value !== null));

        return $values !== [] && array_filter($values, is_string(...)) === $values;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function allScalar(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `present_with`, naming the keys whose presence makes this one required.
     *
     * @param  list<string>  $triggers  rule keys
     */
    private static function withRule(array $triggers): string
    {
        // Always `present_with`, even for the three types `required` is kept
        // for. The difference is in the trigger, not the dependent:
        // `required_with` fires only when the trigger is non-blank, so a body
        // sent as `{"note": null}` would not require anything, while the
        // contract says any key sent makes the rest required.
        return 'present_with:'.implode(',', $triggers);
    }

    /**
     * The rule a key the contract requires gets, which is not always Laravel's `required`.
     *
     * **JSON Schema's `required` asks for the key; Laravel's asks for a non-empty value.** It refuses `null`, `""`,
     * `[]` and `{}` whatever else the field declares, so a required string the contract lets be empty, a required array
     * it lets hold nothing, or any field that may be null would answer 422 to a payload the contract accepts. `present`
     * asks for the key and nothing else, and the type rule beside it judges any value that is not blank.
     *
     * `required` is kept for the three types whose own rule refuses every empty value anyway — an integer, a number, a
     * boolean cannot be `""` or `[]` — because there the two say the same thing and `required` is what a reader expects
     * to see.
     *
     * @see docs/guide/code-generation/request-validation.md — "PATCH empties the required list"
     */
    private static function requiredRule(Schema $schema): string
    {
        if (self::allowsNull($schema) && $schema->types !== []) {
            return 'present';
        }

        return in_array($schema->soleType(), [SchemaType::Integer, SchemaType::Number, SchemaType::Boolean], true)
            ? 'required'
            : 'present';
    }

    /**
     * What one field states that none of its rules carries, as a finding and as the keywords it names.
     *
     * @param  list<string>  $handled  the keywords the walk acted on for this field, whether by a rule or by a finding
     *                                 of its own
     */
    private static function reportUnhandled(RuleWalk $walk, string $label, Schema $schema, array $handled): void
    {
        $keywords = self::statedKeywords($schema, $handled);

        if ($keywords === []) {
            return;
        }

        $walk->findings[] = sprintf('%s states %s, which nothing in this rule set enforces.', $label, self::list($keywords));

        foreach ($keywords as $keyword) {
            $walk->leaveUnenforced($keyword, $label);
        }
    }

    /**
     * The keywords of a field the rule set drops whole, as data for the doctor.
     *
     * A field with no rule key (a name PHP rewrites, a `*` Laravel reads as a wildcard) gets a finding of its own that
     * says so, and none of what the contract states about it is enforced: not its presence, and not any keyword a rule
     * could have come from. Those are the same unenforced keywords as any other, so they are named the same way.
     *
     * @param  bool  $required  whether the contract asks for the field
     * @param  bool  $dependent  whether `dependentRequired` asks for it
     */
    private static function leaveFieldUnenforced(
        RuleWalk $walk,
        string $label,
        Schema $schema,
        bool $required,
        bool $dependent = false,
    ): void {
        if ($required) {
            $walk->leaveUnenforced('required', $label);
        }

        if ($dependent) {
            $walk->leaveUnenforced('dependentRequired', $label);
        }

        foreach (self::statedKeywords($schema, []) as $keyword) {
            $walk->leaveUnenforced($keyword, $label);
        }
    }

    /**
     * The keywords a field states that none of its rules carries.
     *
     * @param  list<string>  $handled  the keywords the walk acted on for this field
     * @return list<string>
     */
    private static function statedKeywords(Schema $schema, array $handled): array
    {
        $stated = array_filter(
            self::RULE_KEYWORDS,
            static fn (string $keyword): bool => ! in_array($keyword, $handled, true) && self::states($schema, $keyword),
        );

        // Why: `isFilePart` is the normal form's one flag for two spellings, and no contract has the word. A finding
        // naming it sends the reader looking for a keyword that is not in their file, so it says what they wrote.
        $named = array_map(
            static fn (string $keyword): string => $keyword === 'isFilePart'
                ? ($schema->contentMediaType === null ? 'format' : 'contentMediaType')
                : $keyword,
            $stated,
        );

        return array_values(array_unique($named));
    }

    /**
     * Why a field got no type rule, or null when the reason is reported elsewhere.
     *
     * Two different silences, told apart because the fix differs: a union no single rule expresses, and a document that
     * stated no type at all.
     */
    private static function unmappedType(Schema $schema): ?string
    {
        $stated = array_values(array_filter(
            $schema->types,
            static fn (SchemaType $type): bool => $type !== SchemaType::Null,
        ));

        if (count($stated) > 1) {
            return 'declares more than one type, which no single Laravel rule expresses';
        }

        return $stated === [] ? 'states no type' : null;
    }

    /**
     * Whether a presence rule refuses a payload that leaves the key out, which `present_with:` does not do on its own.
     */
    private static function refusesAbsence(string $presence): bool
    {
        return $presence === 'present' || $presence === 'required';
    }

    /**
     * What the body's own root says that a rule set cannot act on, or that a reader would not guess from the rules.
     *
     * @return list<string>
     */
    private static function rootFindings(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): array
    {
        $findings = [];

        if (! self::isObjectBody($schema)) {
            $findings[] = 'The body\'s own schema is not an object, so no rule is derived from it. '
                .'A rule key is a field name, and a body that is a bare value has none.';

            return $findings;
        }

        // Why: the one place a request is stricter than the contract by design. Matching
        // it would take a check that holds for an object or for anything else, at a root
        // that has no field for a rule to sit on. A form cannot send a non-object, so only
        // a body read as JSON is stricter.
        if ($schema->types === [] && $walk->jsonBody) {
            $findings[] = 'The body names `properties` and no `type`, so it is read as an object, and a JSON '
                .'body that is not one (the string `"Ada"` is valid against this schema) is refused. That is '
                .'stricter than the contract, since the request cannot accept an object or anything else at '
                .'the root. Add `type: object` to the schema to make the reading the contract\'s own.';
            $walk->stricter[] = $findings[array_key_last($findings)];
        }

        // The request refuses an empty content for a required body when it is JSON or has no
        // `Content-Type`. A form is the one it cannot read that way.
        if ($body->required && $walk->formBody) {
            $findings[] = self::EMPTY_FORM_FINDING;
        }

        if ($operation->method === HttpMethod::Patch && $schema->required !== []) {
            $findings[] = sprintf(
                'A `PATCH` is a partial update, so the schema\'s required list (%s) is read as '
                    .'empty here. Every property is `sometimes`.',
                self::list($schema->required),
            );
        } elseif (! $body->required && $schema->required !== [] && $walk->formBody) {
            // Why: a JSON body is not named here, since the request reads its raw content and refuses `{}` itself.
            // A form is the one it cannot read that way: its fields need not show in the content.
            $alone = count(array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required])) === 1;

            $findings[] = sprintf(
                'The body may be absent entirely while its schema requires %s%s. A rule cannot tell a body that is not '
                    .'sent from a form that sends none of the body\'s keys, since both leave every key out of the data, '
                    .'so the form is accepted, which the contract refuses.',
                self::list($schema->required),
                $alone
                    ? ' and declares nothing else. There is no other key to name, so it is `sometimes`'
                    : (count($schema->required) === 1
                        ? ', so it is required only when another key of the body is sent'
                        : ', so each is required only when another key of the body is sent'),
            );
        }

        if ($operation->method === HttpMethod::Put) {
            $optional = array_values(array_diff(array_map('strval', array_keys($schema->properties)), $schema->required));

            if ($optional !== []) {
                $findings[] = sprintf(
                    'A `PUT` whose schema leaves %s optional. Nothing fills an absent field from a '
                        .'schema default, so a field the client omitted keeps its stored value. '
                        .'Override `update()` on your controller for replacement semantics.',
                    self::list($optional),
                );
            }
        }

        return $findings;
    }

    /**
     * Whether the document stated a keyword, by the property that carries it.
     *
     * Two shapes of "nothing was written" read the same way — null and the empty array — and `false` joins them for the
     * flags whose absence is spelled that way, `uniqueItems` and `isFilePart`.
     *
     * **`additionalProperties` is the exception.** `false` there is the statement rather than the silence, and the only
     * value a rule can carry: `true` asks for nothing, and `null` is a document that said nothing.
     */
    private static function states(Schema $schema, string $keyword): bool
    {
        $value = $schema->$keyword;

        if ($keyword === 'additionalProperties') {
            return $value === false;
        }

        return $value !== null && $value !== [] && $value !== false;
    }

    /**
     * A rule key, with the structural character Laravel lets a key escape escaped.
     *
     * `user.name` as a literal property name would otherwise generate rules for a `name` key inside a `user` object the
     * contract never declared, which is a rule set that is wrong rather than incomplete.
     *
     * **`.` is the one Laravel offers an escape for, and not the only one it reads.** `*` is the validator's wildcard
     * in a rule key and has no escape, so a field named with one is reported by {@see self::unescapableKey()} rather
     * than quietly mis-keyed.
     */
    private static function ruleKey(string $field): string
    {
        return str_replace('.', '\\.', $field);
    }

    /**
     * A finding for a field name Laravel would read as structure and offers no way to escape, or null for the ordinary
     * case.
     *
     * Reported rather than refused, on the rule the whole class follows: the rule set is short by a field rather than
     * wrong about one, and a build that turned away a valid contract over a rare spelling would be the worse answer.
     *
     * @internal Public for the reason {@see self::breaksKeyList()} gives.
     */
    public static function unescapableKey(string $field): ?string
    {
        return str_contains($field, '*')
            ? sprintf(
                '`%s` carries a `*`, which Laravel reads as a wildcard in a rule key and offers no '
                    .'escape for. Its rules would apply to keys the contract never declared, so none '
                    .'are emitted for it.',
                $field,
            )
            : null;
    }

    /**
     * The path PHP delivers a name of a form body under, or null when it delivers it under none: `user.name` is
     * `['user_name']`, `a b` is `['a_b']` and `page[size]` is `['page', 'size']`.
     *
     * Asked of PHP itself: `parse_str()` is the parser a form body goes through, and what it keeps is the answer, where
     * a rewrite of its rules written here would be one more thing to keep in step with it. Measured against a real SAPI
     * (`curl -d` and `curl -F` to `php -S`), `$_POST` agrees with it, and a name inside brackets (`address[a.b]`) keeps
     * its dot.
     *
     * @return list<string>|null
     */
    private static function deliveredPath(string $name): ?array
    {
        parse_str(urlencode($name).'=1', $parsed);

        $path = [];
        $node = $parsed;

        while (is_array($node) && $node !== []) {
            $key = array_key_first($node);
            $path[] = (string) $key;
            $node = $node[$key];
        }

        return $path === [] || is_array($node) ? null : $path;
    }

    /**
     * The name PHP gives the top level of a form body's key, or null when it gives none.
     *
     * @internal Public so the closed root names what it receives.
     */
    public static function deliveredName(string $name): ?string
    {
        return self::deliveredPath($name)[0] ?? null;
    }

    private static function phpRewrites(string $name): bool
    {
        return self::deliveredPath($name) !== [$name];
    }

    /**
     * The rule key a path is validated under: each step escaped, since a name inside brackets keeps its dot.
     *
     * @param  list<string>  $path
     */
    private static function pathKey(array $path): string
    {
        return implode('.', array_map(self::ruleKey(...), $path));
    }

    /**
     * Decide, for a body read as a form, which rule key each top-level name answers to.
     *
     * **PHP rewrites a top-level name of a form-encoded or multipart body before Laravel sees it** (`user.name` arrives
     * as `user_name`, `page[size]` as `page => [size => ...]`), so a rule keyed by the name the contract writes never
     * finds the value. A body read only as a form keys its rules by the name PHP delivers, so presence, type,
     * `required_with` and `dependentRequired` judge what was sent, and `dto()` reads the value back under the
     * contract's name.
     *
     * **Two names PHP delivers as one key cannot be told apart in the data**, nor can a rewritten name and a declared
     * one that is equal to what it becomes, or a bracketed name under a declared top-level one. None of them gets a
     * rule, and one finding names them. A body that is JSON as well gets the same, since a rule for the declared name
     * would judge the array a form builds.
     *
     * **A body that is JSON as well keeps the rule keyed by the name the contract writes**, since a JSON client sends
     * exactly that, and the value a form client sends under the other name is not checked: no one rule set holds both
     * keys without refusing one of the clients. A presence rule is left out for the same reason, and a finding says
     * where the form value arrives.
     */
    private static function planFormNames(Schema $schema, RuleWalk $walk): void
    {
        if (! $walk->formBody) {
            return;
        }

        $dependedOn = [];
        $dependent = [];
        $triggers = [];

        foreach ($schema->dependentRequired as $trigger => $names) {
            array_push($dependedOn, (string) $trigger, ...array_map('strval', $names));
            array_push($dependent, ...array_map('strval', $names));

            foreach ($names as $name) {
                $triggers[(string) $name][] = (string) $trigger;
            }
        }

        $names = array_values(array_filter(
            array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required, ...$dependedOn]),
            static fn (string $name): bool => self::unescapableKey($name) === null,
        ));

        $paths = [];

        foreach ($names as $name) {
            $paths[$name] = self::deliveredPath($name);
        }

        foreach ($names as $name) {
            $head = $paths[$name][0] ?? null;

            if ($head !== null && self::phpRewrites($name)) {
                $walk->formHeads[$head][] = $name;
            }
        }

        // The key each name answers to, which is its own for a name PHP leaves alone.
        $effective = [];

        foreach ($names as $name) {
            $effective[$name] = $paths[$name] === null ? null : self::pathKey($paths[$name]);
        }

        $groups = [];
        $overlaps = [];

        foreach ($names as $name) {
            $key = $effective[$name];

            // Beside JSON, a name a form delivers nowhere keeps its JSON rule, and says so below.
            if ($key === null && $walk->mixedBody) {
                continue;
            }

            if ($key === null) {
                $walk->unchecked[$name] = 'PHP delivers nothing under that name';
                $walk->withheld[$name] = true;
                $walk->findings[] = sprintf(
                    '`%s` is a property of a form body that PHP delivers under no name, so no rule is emitted for it, and '
                        .'neither its type nor its presence is enforced.',
                    $name,
                );

                continue;
            }

            $groups[$key][] = $name;

            // A bracketed name delivers below a top-level key, which another name may be.
            $head = self::ruleKey($paths[$name][0] ?? '');

            if (count($paths[$name] ?? []) > 1) {
                foreach ($names as $other) {
                    if ($other !== $name && $effective[$other] === $head) {
                        $overlaps[] = [$name, $other, $key, $head];
                    }
                }
            }
        }

        $collided = [];

        foreach ($groups as $key => $members) {
            $members = array_values(array_unique($members));

            if (count($members) < 2) {
                continue;
            }

            foreach ($members as $member) {
                $collided[$member] = true;
                $walk->unchecked[$member] = 'another name in the body reaches Laravel under the same key';
                $walk->withheld[$member] = true;
            }

            $walk->findings[] = sprintf(
                '%s reach Laravel under one name, `%s`, which a form delivers them as, so no rule can tell them apart. '
                    .'None is emitted for them, and neither their type nor their presence is enforced, nor do they '
                    .'take part in `required_with` or `dependentRequired`.',
                self::list($members),
                $key,
            );
        }

        foreach ($overlaps as [$name, $other, $key, $head]) {
            $walk->findings[] = sprintf(
                '`%s` is delivered by a form as `%s`, below the key `%s` that `%s` uses, so no rule can tell their values '
                    .'apart. None is emitted for either, and neither their type nor their presence is enforced, nor do they '
                    .'take part in `required_with` or `dependentRequired`.',
                $name,
                $key,
                $head,
                $other,
            );

            foreach ([$name, $other] as $member) {
                $collided[$member] = true;
                $walk->unchecked[$member] = 'another name in the body reaches Laravel under the same key';
                $walk->withheld[$member] = true;
            }
        }

        if ($walk->mixedBody) {
            foreach ($names as $name) {
                if (isset($collided[$name]) || ! self::phpRewrites($name)) {
                    continue;
                }

                $path = $paths[$name];
                $required = in_array($name, $schema->required, true);
                $when = $required || ! in_array($name, $dependent, true)
                    ? ''
                    : ' when '.self::enumerate(array_map(static fn (string $trigger): string => '`'.$trigger.'`', array_values(array_unique($triggers[$name])))).' is sent';

                $walk->unchecked[$name] = 'the body is also JSON, and one rule set cannot hold both the name the contract writes and the one PHP gives it';
                $walk->findings[] = sprintf(
                    '`%1$s` is a property of a body that is JSON and a form at once. Its rule keeps the name the contract '
                        .'writes, which a JSON client sends. A form client sends it as `%1$s`, which PHP delivers %2$s, '
                        .'so its value is not checked there.%3$s',
                    $name,
                    $path === null ? 'under no name' : 'as `'.self::pathKey($path).'`',
                    $required || $when !== ''
                        ? ' The contract requires it'.$when.', and a presence rule keyed by the JSON name would refuse every '
                            .'form client, so it is not asked for from a JSON client either: one rule set serves both '
                            .'and `rules()` does not branch on the media type, so a JSON body that '
                            .($when === '' ? 'leaves it out' : 'sends the trigger and leaves it out').' is accepted, which the contract refuses.'
                        : '',
                );

                if ($path !== null) {
                    $walk->deliveredKeys[$name] = self::pathKey($path);
                }
            }

            return;
        }

        foreach ($names as $name) {
            if (isset($collided[$name]) || $paths[$name] === null || ! self::phpRewrites($name)) {
                continue;
            }

            $walk->formPaths[$name] = $paths[$name];
            $walk->deliveredKeys[$name] = self::pathKey($paths[$name]);
        }
    }

    /**
     * The rule key the field of a name is validated under: the key a form delivers it as, for a top-level name of a
     * body read only as a form, and the name as written for the rest.
     */
    private static function fieldKey(string $name, RuleWalk $walk, string $prefix): string
    {
        return $prefix === '' && isset($walk->formPaths[$name])
            ? $walk->deliveredKeys[$name]
            : $prefix.self::ruleKey($name);
    }

    /**
     * The keys whose presence stands for a name being sent, for a sibling or a `dependentRequired` trigger: both of a
     * JSON and form body's, one of a form's, none for a name with no rule.
     *
     * @return list<string>
     */
    private static function triggerKeys(string $name, RuleWalk $walk, string $prefix): array
    {
        if ($prefix !== '' || ! $walk->formBody) {
            return [self::ruleKey($name)];
        }

        if (isset($walk->withheld[$name])) {
            return [];
        }

        if (isset($walk->formPaths[$name])) {
            return [$walk->deliveredKeys[$name]];
        }

        return isset($walk->deliveredKeys[$name])
            ? [self::ruleKey($name), $walk->deliveredKeys[$name]]
            : [self::ruleKey($name)];
    }

    /**
     * A finding for a query name PHP rewrites before Laravel sees the request, or null for the ordinary case.
     *
     * **PHP parses the query string, and it does not keep every name.** A `.` or a space becomes `_` (`user.name`
     * arrives as `user_name`), and `[` opens a nested array (`page[size]` arrives as `page => [size => ...]`). Measured
     * with `parse_str()`, which the request's query bag is built from. No key a rule can name is ever found under the
     * name the contract writes, so a rule for it would never see what was sent: an optional one is never validated, and
     * a required one answers 422 to every valid request. Reported instead, which is what the `*` case does, so the
     * request accepts what the contract accepts.
     */
    private static function unreachableQueryName(string $name): ?string
    {
        return strpbrk($name, ".[ \0") !== false
            ? sprintf(
                '`%s` is a `query` parameter whose name PHP rewrites before Laravel sees it (`.` and a '
                    .'space become `_`, and `[` opens a nested array), so no rule key can find it. None is '
                    .'emitted for it, and neither its type nor its presence is enforced.',
                $name,
            )
            : null;
    }

    /**
     * Phrases already quoted, in a sentence, with the separator English uses.
     *
     * @param  list<string>  $items
     */
    private static function enumerate(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * Names in a sentence, quoted, with the separator English uses.
     *
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $quoted = array_map(static fn (string $item): string => '`'.$item.'`', $items);

        if (count($quoted) < 2) {
            return implode('', $quoted);
        }

        $last = array_pop($quoted);

        return implode(', ', $quoted).' and '.$last;
    }
}
