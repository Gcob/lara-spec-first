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
use Gcob\LaraSpecFirst\Validation\MultipleOf;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Gcob\LaraSpecFirst\Validation\WholeNumber;

/**
 * One operation's request body and `query` parameters, as one Laravel rule set.
 *
 * **Every constraint maps or reports**, and the second half is what makes this safe to ship before the first is
 * finished. A keyword this class does not yet translate leaves a finding naming it and the field it was written on, so
 * a rule set that is incomplete says so in the file a reader opens. What is never emitted is a looser rule that almost
 * matches: accepting a payload the contract refuses is a wrong answer where a report is a missing one, and only the
 * second is recoverable. Nor is a rule emitted that is stricter than the contract, refusing what it accepts, unless
 * matching it exactly would be far too complex in Laravel, and then a finding says so. Where this class falls short of
 * the first rule today, the gap is listed with its card or stated limit in ADR 0024, never taken as an exception.
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
     * {@see self::rootFindings()} before the walk, as information (an empty form is how a form writes `{}`), and taken
     * out again, from the findings and from the information, when the walk finds a required key.
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
     * The styles OpenAPI defines for an `in: query` parameter.
     */
    private const QUERY_STYLES = ['form', 'spaceDelimited', 'pipeDelimited', 'deepObject'];

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
        'additionalPropertiesSchema',
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
     * @param  RuleSetOptions  $options  what the application's configuration says about how its clients write input
     *
     * @throws ConflictingInputException the body and a query parameter naming one field, two media types over two
     *                                   schemas, or an `allOf` whose branches disagree
     */
    public static function for(Operation $operation, RuleSetOptions $options): RuleSet
    {
        $walk = new RuleWalk($operation->label(), $options);
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
            $attempt = new RuleWalk($operation->label(), $options);
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
            $none = new RuleWalk($operation->label(), $options);
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
            $attempt = new RuleWalk($operation->label(), $options);
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
        $body = self::bodySchema($operation, $walk);
        $walk->multipart = $operation->requestBody !== null
            && array_values(array_intersect($operation->requestBody->mediaTypes(), self::READ_MEDIA_TYPES)) === [self::MULTIPART];
        if ($operation->requestBody !== null) {
            $walk->encodingDefaults = $operation->requestBody->encodingDefaults;
        }
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

        $walk->flushDeferredGap();

        // A query string carries no file, so the parameters below are never read
        // as one, whatever the body is.
        $multipart = $walk->multipart;
        $walk->multipart = false;
        $walk->textSource = 'query string';
        $walk->mixedBody = false;
        $walk->formBody = false;
        $queryKeys = [];

        $plans = self::planQueryNames($operation, $walk);

        foreach ($operation->queryParameters as $index => $parameter) {
            $plan = $plans[$index];

            // A name's sentence is information when none of the names it is about states anything, and a gap with
            // the entries of those that do: a sentence about two names is not a gap for one of them alone.
            foreach ($plan['findings'] as $finding) {
                $entries = [];

                foreach ($finding['names'] as $at) {
                    $named = $operation->queryParameters[$at];
                    $entries['`'.$named->name.'`'] = self::droppedKeywords($named->schema, $named->required);
                }

                self::dropFields($walk, $finding['text'], $entries);
            }

            // A plain name is refused where a rewritten one only keeps its finding (the plan above): the name is
            // the contract's own key, so the build would be choosing which half of the contract to enforce. Before
            // the skip below, so that a plain name the overlap leaves without a rule is refused all the same. A name
            // the body writes counts whether or not it has a rule of its own: one that only `required` names, in a body
            // that may be absent or `null`, in a form or in a `PATCH`, is read from the query string all the same.
            if ($plan['plain']) {
                if (isset($walk->formHeads[$parameter->name])) {
                    $bodyName = $walk->formHeads[$parameter->name][0];

                    throw ConflictingInputException::deliveredFieldDeclaredTwice($operation->label(), $bodyName, $parameter->name);
                }

                if (in_array($parameter->name, $walk->bodyNames, true)) {
                    throw ConflictingInputException::fieldDeclaredTwice(
                        $operation->label(),
                        $parameter->name,
                        in_array($parameter->name, $walk->bodyProperties, true),
                    );
                }
            }

            // Before the no-key skip: a fault is about the document, not about the rule the name does or does not get.
            [$style, $explode] = self::querySerialization($walk, $parameter);

            if ($plan['key'] === null) {
                // Its sentence was written above. A name that has none of its own (the second of two names under one
                // key) still leaves what it states unenforced.
                self::leaveDropped($walk, '`'.$parameter->name.'`', self::droppedKeywords($parameter->schema, $parameter->required));

                continue;
            }

            $key = $plan['key'];

            if (! in_array($plan['head'], $queryKeys, true)) {
                $queryKeys[] = $plan['head'];
            }

            $presence = $parameter->required ? self::requiredRule($parameter->schema) : 'sometimes';
            $merged = AllOfMerger::merge($parameter->schema, $operation->label(), '`'.$parameter->name.'`');

            // A query string is not JSON. What reaches the validator is the value PHP parsed, and the parameter's
            // `style` and `explode` say how the client wrote it, so an array or an object is not the shape the schema
            // describes, and every rule about that shape would refuse a valid request. Neither gets a `field()` call.
            //
            // An array's own name is always sent, so its presence is enforced and the rest reported. An object's is
            // not: `form` with `explode: true` sends one key per property (`?status=a`) and never the name, so even
            // presence would refuse a valid request, and it is `sometimes` whatever its `required` flag. Sent as one
            // string (`form` with `explode: false`) its name is there, so it gets its presence like an array. A style
            // the document wrote wrong is read as no style, which is the first of these cases.
            if ($merged->soleType() === SchemaType::Array) {
                // Its presence is enforced, so `required` is not named: its type and what it states are not.
                $keywords = self::droppedKeywords($merged, false);

                if (self::holdsValueSchema($merged)) {
                    $keywords[] = 'additionalProperties';
                }

                $walk->rules[$key] = [$presence];
                $walk->unenforced(sprintf(
                    '`%s` is a `query` parameter typed `array`. %s, so only its presence is enforced.',
                    $parameter->name,
                    self::unreadArray($parameter->name, $style, $explode),
                ), self::entriesOf(['`'.$parameter->name.'`' => $keywords]));

                continue;
            }

            if ($merged->soleType() === SchemaType::Object) {
                // `deepObject` sends one key per property under the parameter's own name (`?page[size]=10`), which is
                // what PHP parses into the nested array `field()` judges, so it is read whatever `explode` says: the
                // parser gives `false` to a document that never wrote it, and OpenAPI 3.2.0 gives it no effect here. A
                // name another parameter nests under (`page` beside `page[size]`) keeps the fallback below, since the
                // two would be judged by one key.
                if ($style === 'deepObject' && $plan['shapedBy'] === []) {
                    // Why: an empty object can't be written in a query string, so a client with nothing to filter leaves
                    // the parameter out. Only a required property it sends gives it something to write, so only then is the name asked for.
                    if ($parameter->required && ! self::requiresSentProperty($merged, $walk, '`'.$parameter->name.'`')) {
                        $presence = 'sometimes';
                        $walk->information(sprintf(
                            '`%s` is a required `deepObject` query parameter that requires no property a client sends, so the request '
                            .'accepts it left out: an empty object cannot be written in a query string, and the schema '
                            .'accepts `{}`.',
                            $parameter->name,
                        ));
                    }

                    self::field($walk, $key, '`'.$parameter->name.'`', $parameter->schema, $presence);

                    continue;
                }

                $sentOwnName = $style === 'form' && ! $explode;

                // Its own name is not sent as separate keys, so the presence of a required one is not enforced either.
                $keywords = self::droppedKeywords($merged, $parameter->required && ! $sentOwnName);

                if (self::holdsValueSchema($merged)) {
                    $keywords[] = 'additionalProperties';
                }

                $walk->rules[$key] = [$sentOwnName ? $presence : 'sometimes'];
                $walk->unenforced(sprintf(
                    '`%s` is a `query` parameter typed `object`. %s',
                    $parameter->name,
                    self::unreadObject($parameter->name, $style, $explode, $plan['shapedBy']),
                ), self::entriesOf(['`'.$parameter->name.'`' => $keywords]));

                continue;
            }

            self::field($walk, $key, '`'.$parameter->name.'`', $parameter->schema, $presence);

            $path = self::deliveredPath($parameter->name);

            if ($plan['shaped'] && $path !== null && isset($walk->rules[$key])) {
                $walk->whenNotArray[$key] = $path;

                if (! $plan['ruledBelow']) {
                    $walk->dropsArray[] = $key;
                }
            }
        }

        // Why: the root's wildcard would judge a `query` parameter's key too, since the validated data holds both. The
        // guard spares the keys the parameters arrive under, as it spares the declared names. The body's rules are built
        // before the parameters are planned, so the names are added here, to every rule key under the root `*`.
        if ($walk->rootJudged && $queryKeys !== []) {
            foreach (array_keys($walk->rules) as $ruleKey) {
                $ruleKey = (string) $ruleKey;

                if ($ruleKey === '*' || str_starts_with($ruleKey, '*.')) {
                    $walk->undeclaredKeys[$ruleKey][0] = array_values(array_unique([...$walk->undeclaredKeys[$ruleKey][0] ?? [], ...$queryKeys]));
                }
            }
        }

        // Why: `validationData()` takes a `query` parameter's key from the query string and drops it from the body, so a
        // body key of that name is not a key of the body. A map has no declared name to refuse it for.
        if ($walk->openMapRoot || $walk->rootJudged) {
            foreach ($queryKeys as $queryKey) {
                $walk->information($walk->mapRoot
                    ? sprintf(
                        'A body key named `%1$s` is not a key of this map: `%1$s` is also a `query` parameter, so it is read from '
                            .'the query string, and a `%1$s` in the body is left out of the validated data and of the DTO.',
                        $queryKey,
                    )
                    : sprintf(
                        'A body key named `%1$s` is not an undeclared key of this body: `%1$s` is also a `query` parameter, so it '
                            .'is read from the query string, and a `%1$s` in the body is left out of the validated data.',
                        $queryKey,
                    ));
            }
        }

        $walk->exceeded = $walk->exceeded || $walk->unrolledKeys() > self::MAX_UNROLLED_KEYS;

        self::reportCuts($walk);

        // In the order of the rules, with the steps in order: an inner map records its step before the map around it.
        $undeclaredKeys = [];

        foreach (array_keys($walk->rules) as $ruleKey) {
            $ruleKey = (string) $ruleKey;

            if (isset($walk->undeclaredKeys[$ruleKey])) {
                $undeclaredKeys[$ruleKey] = $walk->undeclaredKeys[$ruleKey];
                ksort($undeclaredKeys[$ruleKey]);
            }
        }

        return new RuleSet($walk->rules, $walk->findings(), $closedKeys, $body, $multipart, $queryKeys, $walk->jsonRoot, $walk->nullableRoot, $walk->formPaths, [...$walk->unchecked, ...$walk->standIn], $walk->requiredRoot, $walk->rootKey, $walk->unenforcedEntries(), $walk->faultFindings(), $walk->stricterFindings(), $walk->requiredOnceSent, $walk->whenNotArray, $walk->dropsArray, $walk->mapRoot, $walk->informationFindings(), $walk->scalarNames, $walk->arrayKeys, $undeclaredKeys, self::commaLists($walk, $undeclaredKeys));
    }

    /**
     * The arrays a client writes as one comma-separated string, as the steps of their keys and the names each wildcard
     * step spares: the guard of a map's values is what spares a declared name beside them.
     *
     * @param  array<string, array<int, list<string>>>  $undeclaredKeys
     * @return list<array{steps: list<string>, spared?: array<int, list<string>>}>
     */
    private static function commaLists(RuleWalk $walk, array $undeclaredKeys): array
    {
        $lists = [];

        foreach ($walk->commaLists as $ruleKey) {
            // The dots of a rule key that are not the escaped dot of a name.
            $steps = array_map(
                static fn (string $step): string => str_replace('\\.', '.', $step),
                preg_split('/(?<!\\\\)\./', $ruleKey) ?: [$ruleKey],
            );

            $list = ['steps' => $steps];

            if (isset($undeclaredKeys[$ruleKey])) {
                $list['spared'] = $undeclaredKeys[$ruleKey];
            }

            $lists[] = $list;
        }

        return $lists;
    }

    /**
     * The one schema the rule set is built from, or null when the body states none this package reads.
     *
     * @throws ConflictingInputException
     */
    private static function bodySchema(Operation $operation, RuleWalk $walk): ?Schema
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
            $readTypes = array_values(array_intersect($body->mediaTypes(), self::READ_MEDIA_TYPES));

            // Why: the generated request reads the JSON body or the form, and a body in another media type leaves
            // both empty, so the rules of the media types it reads judge it on an empty body.
            $walk->unenforced(sprintf(
                'The body declares %s, which this package does not read. Nothing in the rule set '
                    .'comes from it.%s',
                self::list($unread),
                $readTypes === [] ? '' : sprintf(
                    ' A client that sends it is judged by the rules of %s on an empty body: a field the schema '
                        .'requires answers `422`, though the contract accepts the request.',
                    self::list($readTypes),
                ),
            ), ['content' => array_map(static fn (string $mediaType): string => '`'.$mediaType.'`', $unread)]);
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
            $finding = sprintf(
                'The body declares %s over one schema, so one rule set covers them all.',
                self::list(array_keys($read)),
            );

            $fileKeywords = isset($read[self::MULTIPART]) ? self::filePartKeywords(reset($read)) : [];

            if ($fileKeywords !== []) {
                // A gap: a multipart upload of a file meets `string` and is refused, so it loses a constraint. Its
                // entries are the parts' own, recorded when each is read, so the sentence is written after the walk.
                $walk->deferGap($finding.' A part the schema marks as a file is then read as a string, because a '
                    .'JSON or form-encoded body cannot carry one and no rule serves both.', $fileKeywords);
            } else {
                $walk->information($finding);
            }
        }

        return reset($read);
    }

    /**
     * The keyword the contract wrote for each file part in a schema, a nested one and an `allOf` branch included:
     * `contentMediaType` when the part states one, `format` otherwise. Empty when there is no file part. A recursion
     * marker has nothing under it, so it ends the walk.
     *
     * @return list<'contentMediaType'|'format'>
     */
    private static function filePartKeywords(Schema $schema): array
    {
        $keywords = [];

        if ($schema->isFilePart) {
            $keywords[] = $schema->contentMediaType === null ? 'format' : 'contentMediaType';
        }

        foreach (self::children($schema) as $child) {
            array_push($keywords, ...self::filePartKeywords($child));
        }

        return array_values(array_unique($keywords));
    }

    /**
     * The schema every value of a map is held to, or null when the object is not a map: it declares a property, or it
     * writes no value schema. The request judges the values beside a property too, but a DTO of an object that declares
     * properties carries those properties only, so it has no place for them.
     *
     * @internal Public so the DTOs type the values of the one object they carry whole, a map with no property.
     */
    public static function mapValueSchema(Schema $schema): ?Schema
    {
        return $schema->properties === [] ? $schema->additionalPropertiesSchema : null;
    }

    /**
     * Whether a value schema states nothing, so any value passes: `additionalProperties: {}`, or one that only writes
     * keywords nothing reads, or a `$ref` to a component that is one of those. Read as `additionalProperties: true` is,
     * with no rule and no finding. The name and the position are where the schema was written, not what it states, so a
     * `$ref` to `AnyValue: {}` reads as the same `{}` written inline.
     *
     * @internal Public for the same reason as {@see self::mapValueSchema()}.
     */
    public static function allowsAnyValue(Schema $value): bool
    {
        return (new Schema(...[...get_object_vars($value), 'name' => null, 'source' => null]))->sameShapeAs(new Schema);
    }

    /**
     * The value schema of an object, whether or not it declares properties: what the undeclared keys are held to.
     * {@see self::mapValueSchema()} is the narrower question the DTOs ask, which is about an object with none.
     */
    private static function undeclaredValueSchema(Schema $schema): ?Schema
    {
        return $schema->additionalPropertiesSchema;
    }

    /**
     * The names the guard of a map's wildcard spares, beside declared properties: the keys the declared names arrive
     * under, as strings (a declared `"2024"` is an integer as an array key, and the comparison is strict).
     *
     * **Null when a declared name cannot be told from an undeclared key by what Laravel sees**, and then the values
     * stay reported, with a finding that says why and the doctor's `Partial`:
     * - a name holding `*`, which has no rule and no name Laravel can match;
     * - a name holding `.` that is read as JSON, or sits below the top level of any body (a form keeps the dot of a
     *   name inside brackets): Laravel replaces the dot of a data key with a placeholder it keeps private, so the key
     *   the guard compares is not the name;
     * - at the top of a body read only as a form, a name PHP delivers under two steps or more (`page[size]` as
     *   `page.size`): sparing the head `page` would leave an undeclared scalar `page` unjudged;
     * - at the top of a body that is JSON and a form at once, a name PHP rewrites: no one rule set can spare both
     *   readings.
     *
     * A form-only root's name PHP delivers under one other name is spared under that name, and an undeclared key that
     * arrives under it is judged by the declared name's rules, not by the value schema: stated, as unenforced.
     *
     * @param  string  $subject  how a finding names the object (`The body`, or the field's label)
     * @param  string  $label  what the doctor's `unenforced` names it by
     * @param  bool  $root  whether the object is the body itself
     * @return list<string>|null
     */
    private static function sparedNames(Schema $schema, RuleWalk $walk, string $subject, string $label, bool $root): ?array
    {
        $spared = [];
        $rewritten = [];

        foreach (array_map('strval', array_keys($schema->properties)) as $name) {
            if (
                str_contains($name, '*')
                || (str_contains($name, '.') && (! $root || ! $walk->formBody))
                || ($walk->textSource === 'query string' && self::holdsBracket($name))
            ) {
                return self::cannotSpare($walk, $label, sprintf(
                    '%s declares `%s`, which Laravel cannot tell apart from an undeclared key, so the values of its other '
                        .'keys are not validated.',
                    $subject,
                    $name,
                ));
            }

            if (! $root || ! $walk->formBody) {
                $spared[] = $name;

                continue;
            }

            $path = self::deliveredPath($name);

            // A form never delivers a pathless name, so only a JSON client sends it, and as written: spared, unless it holds a
            // `.`, which Laravel replaces in the key the client sends, as it does for any JSON name.
            if ($walk->mixedBody && ($path === [$name] || ($path === null && ! str_contains($name, '.')))) {
                $spared[] = $name;

                continue;
            }

            if ($walk->mixedBody && $path === null) {
                return self::cannotSpare($walk, $label, sprintf(
                    '%s declares `%s`, which Laravel cannot tell apart from an undeclared key, so the values of its other '
                        .'keys are not validated.',
                    $subject,
                    $name,
                ));
            }

            if ($walk->mixedBody) {
                return self::cannotSpare($walk, $label, sprintf(
                    '`%1$s` arrives as `%1$s` from a JSON client and as `%2$s` from a form client, and one rule set '
                        .'can\'t spare both, so the values of this body\'s other keys are not validated.',
                    $name,
                    self::pathKey($path),
                ));
            }

            if ($path === null) {
                continue;
            }

            if (count($path) > 1) {
                $head = $path[0];

                return self::cannotSpare($walk, $label, in_array($head, self::declaredNames($schema), true)
                    ? sprintf(
                        'A form delivers `%1$s` inside `%2$s`, and the guard can only spare `%2$s` whole, so an undeclared '
                            .'key under it (`%2$s[evil]`) would not be judged, and the values of this body\'s other keys '
                            .'are not validated.',
                        $name,
                        $head,
                    )
                    : sprintf(
                        'A form delivers `%1$s` inside `%2$s`, so an undeclared `%2$s` can\'t be told apart from it, and the '
                            .'values of this body\'s other keys are not validated.',
                        $name,
                        $head,
                    ));
            }

            $spared[] = $path[0];

            if ($path[0] !== $name) {
                $rewritten[$name] = $path[0];
            }
        }

        foreach ($rewritten as $name => $delivered) {
            $walk->unenforced(sprintf(
                'A form delivers `%s` as `%s`, so an undeclared `%2$s` is judged by `%1$s`\'s rules, not by the value schema.',
                $name,
                $delivered,
            ), ['additionalProperties' => [$label]]);
        }

        return array_values(array_unique($spared));
    }

    /**
     * Write the finding of a name the guard cannot spare, which leaves the object's value schema unenforced.
     *
     * @return null always, so a caller returns what it says
     */
    private static function cannotSpare(RuleWalk $walk, string $label, string $finding): null
    {
        $walk->unenforced($finding, ['additionalProperties' => [$label]]);

        return null;
    }

    /**
     * The value rules of a map: one wildcard key below the object, `*` at the root and `<key>.*` nested.
     *
     * A value is present by being in the object, as an element is in a list, so it is asked for with no presence. The
     * label reads like the rule key. **Written after every explicit key of the object**, because Laravel merges the
     * wildcard's rules into a key that came before it and replaces what the wildcard merged into one that came after.
     *
     * **Beside declared properties the wildcard is guarded**: every rule key written under it is recorded with the
     * names its step must not be, so the declared keys keep exactly their own rules, whatever the order of the rule
     * array.
     *
     * @param  string  $prefix  the rule key of the object followed by a dot, or the empty string for the root
     * @param  list<string>  $spared  the declared names, as strings: a declared `"2024"` is an integer as an array key
     * @param  string|null  $parentLabel  how the object is labeled, so a value of a map read from the query string is
     *                                    labeled as a client writes it (`meta[*]`)
     *
     * @throws ConflictingInputException
     */
    private static function mapValues(RuleWalk $walk, string $prefix, Schema $value, array $spared = [], ?string $parentLabel = null): void
    {
        $key = $prefix.'*';
        $walk->mapKeys[$key] = true;

        self::field($walk, $key, self::bracketLabel($walk, $parentLabel, '*') ?? '`'.$key.'`', $value, '');

        if ($spared === []) {
            return;
        }

        // The step of the map's `*`: the dots of the prefix that are not the escaped dot of a name.
        $step = (int) preg_match_all('/(?<!\\\\)\./', $prefix);

        foreach (array_keys($walk->rules) as $ruleKey) {
            $ruleKey = (string) $ruleKey;

            if ($ruleKey === $key || str_starts_with($ruleKey, $key.'.')) {
                $walk->undeclaredKeys[$ruleKey][$step] = $spared;
            }
        }
    }

    /**
     * Whether a schema holds a value schema (one written under `additionalProperties`) anywhere in it. A recursion
     * marker has nothing under it, so it ends the walk.
     *
     * Asked where a whole schema is dropped with no sweep over it, so what it says about its values still reaches the
     * report.
     */
    private static function holdsValueSchema(Schema $schema): bool
    {
        if ($schema->additionalPropertiesSchema !== null) {
            return true;
        }

        foreach (self::children($schema) as $child) {
            if (self::holdsValueSchema($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every schema written directly under this one: its properties, its `allOf` branches, its items and its value
     * schema, in that order.
     *
     * @return list<Schema>
     */
    private static function children(Schema $schema): array
    {
        return [
            ...array_values($schema->properties),
            ...$schema->allOf,
            ...($schema->items === null ? [] : [$schema->items]),
            ...($schema->additionalPropertiesSchema === null ? [] : [$schema->additionalPropertiesSchema]),
        ];
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

        self::rootFindings($operation, $schema, $body, $walk);

        if (! self::isObjectBody($schema)) {
            return null;
        }

        // Laravel reads a JSON body as `(array) json_decode(...)`, so `"Ada"` and `[1,2]`
        // reach the rules as a list no rule has a key for. The request checks the root itself.
        $walk->jsonRoot = $walk->jsonBody;
        $walk->nullableRoot = $schema->isNullable();
        $walk->requiredRoot = $body->required;
        $walk->nullableRequiredBody = $body->required && $walk->jsonRoot && $walk->nullableRoot
            && $operation->method !== HttpMethod::Patch;
        $walk->patchBody = $operation->method === HttpMethod::Patch;
        $walk->rootKey = self::rootKey($schema, $operation);

        $walk->bodyNames = self::declaredNames($schema);
        $walk->bodyProperties = array_map('strval', array_keys($schema->properties));

        foreach ($walk->bodyNames as $name) {
            $walk->bodyKeys[] = $name;

            if ($walk->formBody && ($delivered = self::deliveredName($name)) !== null) {
                $walk->bodyKeys[] = $delivered;
            }
        }

        $value = self::undeclaredValueSchema($schema);
        $open = $value !== null && self::allowsAnyValue($value);
        $judged = $value !== null && ! $open;
        $spared = [];
        $unsparable = false;

        // Beside declared properties the wildcard is guarded by their names, unless one cannot be told from an
        // undeclared key, which keeps the values reported with a finding that says why.
        if ($judged && $schema->properties !== []) {
            $spared = self::sparedNames($schema, $walk, 'The body', 'The body itself', true);
            $unsparable = $spared === null;
            $judged = ! $unsparable;
            $spared ??= [];
        }

        $walk->rootJudged = $judged;

        // The root goes through the same sweep a field does, minus what the
        // object walk below acts on. Without it a keyword written on the
        // body's own schema — an `enum` is the one a real contract writes there
        // — is read and enforced by nothing, and an empty findings list makes
        // the generated file claim the opposite.
        self::reportUnhandled(
            $walk,
            'The body itself',
            $schema,
            [
                'type', 'properties', 'required', 'dependentRequired', 'additionalProperties', 'allOf',
                ...($judged || $open || $unsparable ? ['additionalPropertiesSchema'] : []),
            ],
        );

        self::planFormNames($schema, $walk, $operation->method === HttpMethod::Patch);
        $walk->requiredOnceSent = self::requiredOnceSent($operation, $schema, $body, $walk);

        $outside = self::enter($walk, $written, $schema, 'the body');

        self::objectChildren(
            $walk,
            '',
            $schema,
            self::rootPresence($operation, $schema, $body, $walk),
            self::encodedParts($body, $schema, $walk),
            $judged,
        );

        self::recordShapes($walk);

        if ($judged) {
            self::mapValues($walk, '', $value, $spared);

            if ($schema->properties !== [] && $walk->formBody) {
                $walk->information(
                    'A form delivers an undeclared key PHP rewrites (`a.b` and `a b` as `a_b`) under the rewritten name, so '
                    .'the value schema judges it under that name, and `validated()` holds it there.',
                );
            }
        }

        // Why: the keys of a map or an open object are the data, and `validated()` keeps only a key a rule names. A
        // wildcard that asks nothing of the value keeps them all where no value rule does, after every explicit key so it
        // merges into the ones before it.
        if ($schema->properties === []) {
            $walk->mapRoot = true;
            // A closed body refuses every key, so what a client may send as a key is no question for it.
            $walk->openMapRoot = $schema->additionalProperties !== false;

            if ($walk->openMapRoot && ! $judged) {
                $walk->rules['*'] = ['sometimes'];
            }

            if ($walk->openMapRoot && $walk->formBody) {
                $walk->information(
                    'A form delivers a key PHP rewrites (`a.b` and `a b` as `a_b`) under the rewritten name, and this '
                    .'body declares no key to say which one the client meant, so the request validates and the DTO carries '
                    .'such a key under the name PHP gave it.',
                );
            }
        }

        self::leave($walk, $outside);

        // Why: the finding is written before the walk, which is the only thing that knows a required key refuses an
        // empty form, and the map of a nullable required body, which the request checks as well, is built before it.
        // Taking the finding out afterwards leaves every other finding where it was.
        if ($walk->rootAsksPresence || ($walk->nullableRequiredBody && $walk->requiredOnceSent !== [])) {
            $walk->withdrawInformation(self::EMPTY_FORM_FINDING);
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
        $levelPaths = [];
        $starLevels = [];
        $appended = [];
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

            // A name that collided has its own finding, and a name delivered at a declared top-level key (`user.name` at
            // `user_name`) is that key, which the contract does not refuse. In a closed body its key is left out of the
            // validated data. A bracketed name in a body read only as a form still closes the levels below its head, or
            // the file would say the key check accepts them; a body that is JSON as well never closes a level.
            if (
                isset($walk->withheld[$name])
                && in_array($delivered, $declared, true)
                && ($walk->mixedBody || count(self::deliveredPath($name) ?? []) === 1)
            ) {
                continue;
            }

            // A bracketed name closes every level above its leaf: each level is an array whose keys are the
            // ones the contract declares below it. A name PHP only renamed (`user.name` as `user_name`) has no
            // level to close, and the key check accepts what it delivers.
            // A name that collided has no `formPaths` entry, and a body that is JSON as well never closes a level.
            $path = $walk->formPaths[$name] ?? (isset($walk->withheld[$name]) && ! $walk->mixedBody ? self::deliveredPath($name) : null);

            // Why: a `*` name has no rule, but a form delivers it at a literal key (`page[*]` is `page => ['*' => ...]`) in
            // the level above it, which the key check of that level cannot name: Laravel hides a `*` in a data key behind
            // a placeholder, so `array:size,*` refuses it. A level a name judged by shape holds stays open instead.
            // Only when no step above the leaf is one: a rule key with a `*` in it is a wildcard.
            if ($path === null && ! $walk->mixedBody && self::unescapableKey($name) !== null) {
                $starPath = self::deliveredPath($name);

                if ($starPath !== null && count($starPath) > 1 && ! str_contains(implode('', array_slice($starPath, 0, -1)), '*')) {
                    for ($level = 1; $level < count($starPath); $level++) {
                        $starLevels[self::pathKey(array_slice($starPath, 0, $level))][] = $name;
                    }

                    continue;
                }
            }

            if ($path === null || count($path) < 2) {
                $accepted[$name] = $delivered;

                continue;
            }

            // The level `ids[]` appends at: its key is the index PHP picks, which no declared key names.
            $appendsAt = self::phpAppends($name);

            for ($level = 1; $level < count($path); $level++) {
                $levelKey = self::pathKey(array_slice($path, 0, $level));
                $steps[$levelKey][$name] = $path[$level];
                $levelPaths[$levelKey] = array_slice($path, 0, $level);

                if ($appendsAt !== null && count($appendsAt) === $level) {
                    $appended[$levelKey] = true;
                }
            }
        }

        // A level a name judged by shape holds, and a `*` name delivers below, stays open: the key check cannot name its
        // `*` key, and refusing it would refuse a body the contract accepts. A `*` name in any other level is left
        // out of the key check, as it was, and the finding below names what the check accepts.
        $starHeld = [];

        foreach ($starLevels as $levelKey => $names) {
            foreach ($names as $name) {
                if (isset($walk->whenNotArray[(string) $levelKey])) {
                    $starHeld[(string) $levelKey] = true;
                    $steps[(string) $levelKey][$name] = '*';
                } else {
                    $accepted[$name] = (string) $levelKey;
                }
            }
        }

        // A form-only body closes each level to the keys the contract declares below it. A key `array:` cannot
        // name (a comma in it) leaves that level open, and so does the key a declared name is delivered at, unless
        // the name is judged by shape: its array branch holds the keys below it instead. The finding below names
        // what the key check then accepts.
        $deliveredAt = [];

        foreach ([...array_keys($schema->properties), ...array_keys($walk->shaped)] as $name) {
            $path = self::deliveredPath((string) $name);

            if ($path === null) {
                continue;
            }

            $level = self::pathKey($path);

            if (isset($walk->shaped[(string) $name]) && isset($walk->whenNotArray[$level])) {
                continue;
            }

            $deliveredAt[$level] = true;
        }

        $kept = [];
        $openForValue = [];
        $dropped = [];

        foreach ($steps as $level => $below) {
            $level = (string) $level;

            // Why: the array branch of a level ends in `exclude`, which removes the level from the data before a level
            // below it is judged, so a rule written for that level would never run. The key check accepts what is sent
            // there, and the finding below says so.
            foreach ($dropped as $droppedLevel) {
                if (str_starts_with($level, $droppedLevel.'.')) {
                    foreach (array_keys($below) as $name) {
                        $accepted[(string) $name] = $level;
                    }

                    continue 2;
                }
            }

            $keys = array_values(array_unique(array_values($below)));

            $commas = array_filter($keys, static fn (string $key): bool => str_contains($key, ',')) !== [];

            // Why: a value can sit at the key a declared name is delivered at (`filter[a]=x`), and `array:` would
            // refuse it, so that level stays open and the finding below names what the key check accepts.
            if (isset($deliveredAt[$level]) || isset($starHeld[$level]) || $commas) {
                // Only the value at its key keeps it open: no `*` name below, no comma, no appended key.
                if (! isset($starHeld[$level]) && ! isset($starLevels[$level]) && ! $commas && ! isset($appended[$level])) {
                    $openForValue[$level] = $below;
                }

                foreach (array_keys($below) as $name) {
                    $accepted[(string) $name] = $level;
                }

                continue;
            }

            // The name judged by shape at this level has its own rule, which judges a value that is not an array. The
            // array a form builds for the names below holds the keys declared there, so they go in its array branch.
            // Why: unless a name appends at this level (`ids[]` is read as `ids.0`), where `array:0` would refuse a second
            // value the body accepted before; the level stays open, as an unshaped one with a comma in a key does.
            if (isset($walk->whenNotArray[$level])) {
                if (isset($appended[$level])) {
                    foreach (array_keys($below) as $name) {
                        $accepted[(string) $name] = $level;
                    }

                    continue;
                }

                $walk->arrayKeys[$level] = $keys;

                if (in_array($level, $walk->dropsArray, true)) {
                    $dropped[] = $level;
                } else {
                    $kept[] = $level;
                }

                continue;
            }

            $walk->rules[$level] = ['sometimes', 'array:'.implode(',', $keys)];
            $kept[] = $level;
        }

        // Why: Laravel's `validated()` keeps a whole array under a rule that is not a bare `array`, so a level kept
        // closed (`array:c`) hands back everything under its keys. A key no rule judges is left out after the key
        // check of its level. A key a ruled name sits below is judged by shape instead: a value that is not an array is
        // left out, and the array holds the keys declared below it (which closes the level that key opened).
        for ($i = 0; $i < count($kept); $i++) {
            $level = $kept[$i];

            foreach (array_values(array_unique(array_values($steps[$level]))) as $key) {
                $child = $level.'.'.self::ruleKey($key);

                if (isset($walk->rules[$child])) {
                    continue;
                }

                $walk->rules[$child] = ['exclude'];

                if (array_filter(array_keys($walk->rules), static fn (int|string $ruleKey): bool => str_starts_with((string) $ruleKey, $child.'.')) === []) {
                    continue;
                }

                $walk->whenNotArray[$child] = [...$levelPaths[$level], $key];

                if (! isset($openForValue[$child])) {
                    continue;
                }

                $walk->arrayKeys[$child] = array_values(array_unique(array_values($openForValue[$child])));

                foreach (array_keys($openForValue[$child]) as $name) {
                    if (($accepted[(string) $name] ?? null) === $child) {
                        unset($accepted[(string) $name]);
                    }
                }

                $kept[] = $child;
            }
        }

        if ($accepted !== []) {
            $walk->unenforced(sprintf(
                'The body is closed to its declared names, but a form delivers %s, so the key check accepts %s, which '
                    .'a client can also send as a key the contract does not declare, and nothing tells the two apart.',
                self::enumerate(array_map(
                    static fn (string $name, string $delivered): string => '`'.$name.'` as `'.$delivered.'`',
                    array_keys($accepted),
                    $accepted,
                )),
                self::list(array_values(array_unique($accepted))),
            ), ['additionalProperties' => ['The body itself']]);
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
            ...array_map(static fn (QueryParameter $parameter): string => self::queryKey($parameter->name), $operation->queryParameters),
        ];

        $key = 'body';

        while (in_array($key, $declared, true)) {
            $key = '_'.$key;
        }

        return $key;
    }

    /**
     * The key a `query` parameter's value is validated under: its name, and for one PHP rewrites, the path PHP delivers
     * it under, which is what the body's own failures must not share (`" body"` arrives as `body`, and `body[x]` as
     * `body.x`, a key apart). A name PHP delivers under no path keeps the name as written.
     *
     * @internal Public so the request's finding can name the key of a name PHP rewrites.
     */
    public static function queryKey(string $name): string
    {
        $path = self::deliveredPath($name);

        return $path === null ? $name : self::pathKey($path);
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
     * - An optional body may be absent entirely, and a body whose root allows `null` may be `null`, which reaches the
     *   rules as `[]`: a plain presence rule would refuse either, which the contract allows. What the contract means is
     *   "a body or none" (or "`null` or a body"), and `*_with` naming every other key the body may carry says exactly
     *   that: nothing sent, nothing required; any key sent — a required one or an optional one — and the required ones
     *   follow. Naming only the required siblings would accept a body made of optional keys alone, which the contract
     *   refuses.
     * - Otherwise (a required body that cannot be `null`) the schema's list is read as written.
     *
     * @return callable(string, Schema): string
     */
    private static function rootPresence(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): callable
    {
        if ($operation->method === HttpMethod::Patch) {
            return static fn (string $name, Schema $property): string => 'sometimes';
        }

        if (! $body->required || $walk->nullableRequiredBody) {
            return self::throughSiblings($schema, $walk);
        }

        return static fn (string $name, Schema $property): string => in_array($name, $schema->required, true)
            ? self::requiredRule($property)
            : 'sometimes';
    }

    /**
     * Whether a required top-level name of the body is asked through its siblings and not on its own, because the body
     * is required and may be `null`: the request, not a rule, is what refuses it when it is missing.
     */
    private static function askedBySiblings(RuleWalk $walk, Schema $schema, string $prefix, string $name): bool
    {
        return $prefix === '' && $walk->nullableRequiredBody && ! isset($walk->unchecked[$name])
            && in_array($name, $schema->required, true);
    }

    /**
     * How a body that may be absent, or `null`, asks for each required root property: through the keys of the others.
     *
     * @return callable(string, Schema): string
     */
    private static function throughSiblings(Schema $schema, RuleWalk $walk): callable
    {
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

    /**
     * The keys a JSON object sent as the body must carry once it is sent, by the rule their failure carries: an
     * optional body's, and a required body's whose root allows `null`.
     *
     * **A rule cannot see `{}`**: every key is out of the data, so `present_with` asks for nothing. The request reads
     * the raw content instead, and this is what it reads: each required name, with the rule it would get in a required
     * body that cannot be `null` (`present` for a name `properties` does not declare). Only a body read as JSON gets
     * one, and not a `PATCH`, which asks for nothing, nor a required body that cannot be `null`, whose rules already
     * refuse `{}`. Left out are the names the rules leave out for their own reason, whose findings stay true: a `*`
     * name, a `readOnly` property, and a name a form client sends under another key (withheld, or unchecked, which a
     * JSON client would otherwise be refused for).
     *
     * @return array<string, string>
     *
     * @throws ConflictingInputException
     */
    private static function requiredOnceSent(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): array
    {
        if (($body->required && ! $walk->nullableRequiredBody) || ! $walk->jsonBody || $operation->method === HttpMethod::Patch) {
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
     * @param  bool  $valuesJudged  whether a wildcard below the object judges every key it has: a name it requires
     *                              without declaring then has its value checked, and the finding that says nothing
     *                              about the value is not written
     * @param  string|null  $parentLabel  how the object is labeled, which a property of an object read from the query
     *                                    string takes as its own (`filter[active]`): the name a client writes, not the
     *                                    dotted rule key
     *
     * @throws ConflictingInputException
     */
    private static function objectChildren(
        RuleWalk $walk,
        string $prefix,
        Schema $schema,
        callable $presence,
        array $contentTypes = [],
        bool $valuesJudged = false,
        ?string $parentLabel = null,
    ): void {
        $dependents = self::dependents($schema, $walk, $prefix, $parentLabel);

        // Why: a dependent array that may be empty has no bracketed form either, so a client with nothing to send
        // cannot satisfy `present_with` for it, and the contract accepts that client.
        foreach (array_keys($dependents) as $name) {
            $name = (string) $name;

            if (
                self::unescapableKey($name) === null
                && self::unsentRequiredName($name, $schema, $walk, $parentLabel ?? '`'.rtrim($prefix, '.').'`') === 'emptyList'
            ) {
                $walk->information(self::emptyListFinding(
                    self::bracketLabel($walk, $parentLabel, $name) ?? '`'.$prefix.$name.'`',
                    'required by `dependentRequired`',
                ));
                unset($dependents[$name]);
            }
        }

        $closed = $schema->additionalProperties === false;

        foreach ($schema->properties as $name => $property) {
            $name = (string) $name;
            $childLabel = self::bracketLabel($walk, $parentLabel, $name) ?? '`'.$prefix.$name.'`';
            $unescapable = self::unescapableKey($name);

            if ($unescapable !== null) {
                self::dropFields($walk, $unescapable, [$childLabel => self::droppedKeywords(
                    $property,
                    self::askedWhenDropped($walk, $property, in_array($name, $schema->required, true), $prefix === ''),
                    self::askedByDependency($walk, $property, in_array($name, $schema->required, true), $prefix === '', isset($dependents[$name])),
                )]);

                continue;
            }

            if ($walk->textSource === 'query string' && self::holdsBracket($name)) {
                self::dropFields($walk, self::bracketedNameFinding($childLabel, $closed), [$childLabel => self::droppedKeywords(
                    $property,
                    self::askedWhenDropped($walk, $property, in_array($name, $schema->required, true), $prefix === ''),
                    self::askedByDependency($walk, $property, in_array($name, $schema->required, true), $prefix === '', isset($dependents[$name])),
                )], $closed ? $parentLabel ?? '`'.rtrim($prefix, '.').'`' : null);

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
            // A required body that may be `null` asks through its siblings, so a lone required property is `sometimes`
            // and still gets the finding a required body writes for a `readOnly` one.
            $askedBySiblings = self::askedBySiblings($walk, $schema, $prefix, $name);
            $readOnly = ($asked !== 'sometimes' || $askedBySiblings) && self::isReadOnly($property, $walk, $childLabel);

            if ($readOnly) {
                $walk->information(self::readOnlyFinding($childLabel));
                $asked = 'sometimes';
            }

            if ($prefix === '') {
                $walk->rootAsksPresence = $walk->rootAsksPresence || self::refusesAbsence($asked);
            }

            // Why: `required` refused `""` for an integer, a number or a boolean even where `ConvertEmptyStringsToNull` is
            // gone, because it is implicit and their type rules are not. Asking through the siblings gives that up, so
            // `filled` (implicit too, and silent when the key is absent) takes its place. A nullable property kept
            // `present`, which let `""` through already.
            $filled = $askedBySiblings && ! $readOnly && self::requiredRule($property) === 'required';

            self::field($walk, self::fieldKey($name, $walk, $prefix), $childLabel, $property, $asked, $contentTypes[$name] ?? null, $filled);
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

            $childLabel = self::bracketLabel($walk, $parentLabel, $name) ?? '`'.$prefix.$name.'`';
            $unescapable = self::unescapableKey($name);

            if ($unescapable !== null) {
                self::dropFields($walk, $unescapable, [$childLabel => self::droppedKeywords(
                    new Schema,
                    self::askedWhenDropped($walk, new Schema, in_array($name, $schema->required, true), $prefix === ''),
                    self::askedByDependency($walk, new Schema, in_array($name, $schema->required, true), $prefix === '', isset($dependents[$name])),
                )]);

                continue;
            }

            if ($walk->textSource === 'query string' && self::holdsBracket($name)) {
                self::dropFields($walk, self::bracketedNameFinding($childLabel, $closed), [$childLabel => self::droppedKeywords(
                    new Schema,
                    self::askedWhenDropped($walk, new Schema, in_array($name, $schema->required, true), $prefix === ''),
                    self::askedByDependency($walk, new Schema, in_array($name, $schema->required, true), $prefix === '', isset($dependents[$name])),
                )], $closed ? $parentLabel ?? '`'.rtrim($prefix, '.').'`' : null);

                continue;
            }

            // Written when the names were planned: no rule for it, and for a name a form client sends
            // under another one, a rule keyed by the JSON name would refuse that client.
            if ($prefix === '' && isset($walk->unchecked[$name])) {
                continue;
            }

            $asked = $presence($name, new Schema);

            if ($asked === 'sometimes') {
                // A lone name of a required body that may be `null` has no sibling to follow and so no rule, but the
                // request still asks for it (`REQUIRED_KEYS`), so the finding a required body writes for it stays.
                if (! isset($dependents[$name]) && ! self::askedBySiblings($walk, $schema, $prefix, $name)) {
                    continue;
                }

                if (isset($dependents[$name])) {
                    $asked = self::withRule($dependents[$name]);
                }
            }

            if ($prefix === '') {
                $walk->rootAsksPresence = $walk->rootAsksPresence || self::refusesAbsence($asked);
            }

            if ($asked !== 'sometimes') {
                $walk->rules[self::fieldKey($name, $walk, $prefix)] = [$asked];
            }

            $undeclared[] = $name;
        }

        if ($undeclared !== [] && ! $valuesJudged) {
            $walk->information(sprintf(
                '%s requires %s without declaring %s under `properties`, so the request asks for the '
                    .'key and says nothing about the value.',
                $prefix === '' ? 'The body' : ($walk->textSource === 'query string' && $parentLabel !== null ? $parentLabel : '`'.rtrim($prefix, '.').'`'),
                self::list($undeclared),
                count($undeclared) === 1 ? 'it' : 'them',
            ));
        }
    }

    /**
     * How a property of an object read from the query string is labeled: the name a client writes (`filter[active]`),
     * which is what every finding about it should say, and not the dotted rule key it is validated under. Null outside
     * the query string, where the rule key is the name.
     */
    private static function bracketLabel(RuleWalk $walk, ?string $parentLabel, string $name): ?string
    {
        return $walk->textSource === 'query string' && $parentLabel !== null
            ? rtrim($parentLabel, '`').'['.$name.']`'
            : null;
    }

    /**
     * Whether a property name holds a bracket, which PHP reads as structure in a query string: `a[b]` is delivered as
     * `a[b`, under no key a rule can name.
     */
    private static function holdsBracket(string $name): bool
    {
        return strpbrk($name, '[]') !== false;
    }

    /**
     * The finding for a property of an object read from the query string whose name holds a bracket. It has no rule,
     * and its presence is not asked for, so nothing a client sends is refused for it. Written by
     * {@see self::dropFields()} with what the property states, and, for a closed object, the object's own
     * `additionalProperties`.
     */
    private static function bracketedNameFinding(string $label, bool $closed): string
    {
        return sprintf(
            '%s is a property of a `query` parameter whose name holds a bracket. PHP delivers a name like that under '
                .'another key, so no rule can find it: it gets none, and neither its value nor its presence is '
                .'enforced.%s',
            $label,
            $closed
                ? ' The object writes `additionalProperties: false`, but `array:` would refuse the key PHP delivers it '
                    .'under, so undeclared keys are not refused.'
                : '',
        );
    }

    /**
     * `dependentRequired`, read the other way round: for each property it names, the keys whose presence makes it
     * required.
     *
     * @return array<string, list<string>> a dependent name => trigger rule keys
     */
    private static function dependents(Schema $schema, RuleWalk $walk, string $prefix, ?string $parentLabel = null): array
    {
        $dependents = [];

        foreach ($schema->dependentRequired as $trigger => $names) {
            $trigger = (string) $trigger;
            $unescapable = self::unescapableKey($trigger);
            $keys = $unescapable === null ? self::triggerKeys($trigger, $walk, $prefix) : [];

            if ($keys === []) {
                // What the dependents lose when no rule holds the trigger: the dependency, which nothing asks for.
                $lost = [];

                foreach ($names as $name) {
                    $name = (string) $name;
                    $asked = self::askedByDependency($walk, $schema->properties[$name] ?? new Schema, in_array($name, $schema->required, true), $prefix === '', true);
                    $lost[self::bracketLabel($walk, $parentLabel, $name) ?? '`'.$prefix.$name.'`'] = $asked ? ['dependentRequired'] : [];
                }

                // The name itself was reported when the names were planned, or is reported by the walk of the
                // properties below, which writes the `*` sentence for a name `properties` or `required` declares, or
                // that a trigger with a rule makes required. A `*` trigger nothing declares has no other place to say it.
                if ($unescapable !== null && ! self::declaresName($schema, $trigger, $walk, $prefix)) {
                    self::dropFields($walk, $unescapable, $lost);
                }

                // This says what it costs.
                self::dropFields($walk, sprintf(
                    '`%s` would make %s required, but it has no rule, so %s not asked for through it.',
                    $trigger,
                    self::list(array_map('strval', $names)),
                    count($names) === 1 ? 'that is' : 'those are',
                ), $lost);

                continue;
            }

            // Why: a form that sends only the names nested under a shaped trigger delivers an array under it, which
            // `present_with` counts as the trigger sent, though the contract never sees the trigger.
            if ($prefix === '' && isset($walk->shaped[$trigger])) {
                $walk->stricter(sprintf(
                    '`%1$s` would make %2$s required, and a form that sends only the names nested under `%1$s` delivers an '
                        .'array under it, which `present_with` counts as `%1$s` sent: %2$s asked for although the contract '
                        .'never sees `%1$s`. The request is stricter than the contract there; leaving the dependency out would '
                        .'accept `%1$s` without %2$s, which the contract refuses.',
                    $trigger,
                    self::list(array_map('strval', $names)),
                ));
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
     * Whether the walk of an object's names reaches this one: it is a property, or a name the object requires, or a
     * name a trigger that has a rule makes required, and so gets the finding for a name it drops.
     */
    private static function declaresName(Schema $schema, string $name, RuleWalk $walk, string $prefix): bool
    {
        if (isset($schema->properties[$name]) || in_array($name, $schema->required, true)) {
            return true;
        }

        foreach ($schema->dependentRequired as $trigger => $names) {
            if (
                in_array($name, array_map('strval', $names), true)
                && self::unescapableKey((string) $trigger) === null
                && self::triggerKeys((string) $trigger, $walk, $prefix) !== []
            ) {
                return true;
            }
        }

        return false;
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
        bool $filled = false,
    ): void {
        $schema = AllOfMerger::merge($written, $walk->identity, $label);
        $rules = $presence === '' ? [] : [$presence];

        if ($filled) {
            $rules[] = 'filled';
        }

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
        $value = null;
        $judged = false;
        $spared = [];

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
                    // Why: Laravel's `integer:strict` refuses `1.0`, which JSON Schema 2020-12 reads as an integer, and
                    // its loose `integer` reads a float as text with 14 significant digits, so `2.9999999999999996` passes.
                    // `WholeNumber` compares the float itself. `numeric` goes beside it because Laravel finds the number
                    // `min`, `max`, `gt` and `lt` compare by the name of a rule, and an object has none. A form's text is
                    // a string, never a float, so beside a form `acceptsText` lets that string through, as `integer` does.
                    if ($walk->strictJson()) {
                        array_push($rules, new WholeNumber, 'numeric');
                    } elseif ($walk->mixedBody) {
                        array_push($rules, new WholeNumber(acceptsText: true), 'numeric');
                    } else {
                        $rules[] = 'integer';
                    }

                    self::numberRules($schema, $rules, $handled);

                    if ($walk->mixedBody) {
                        $walk->unenforced(sprintf(
                            '%s is an integer in a body that is JSON and also a form. One rule set cannot read the text a form '
                                .'carries and still refuse a string in JSON, so it accepts an integer written as text: a JSON '
                                .'client\'s `"42"` gets through, though the contract refuses it.',
                            $label,
                        ), ['type' => [$label]]);
                    }
                    break;
                case SchemaType::Number:
                    $rules[] = $walk->strictJson() ? 'numeric:strict' : 'numeric';
                    self::numberRules($schema, $rules, $handled);

                    if ($walk->mixedBody) {
                        $walk->unenforced(self::mixedBodyFinding($label, 'a number', 'numeric', '`"1.5"` gets through, though the contract refuses it.'), ['type' => [$label]]);
                    }
                    break;
                case SchemaType::Boolean:
                    if ($walk->textSource !== null) {
                        $rules[] = self::textBoolean($schema, $label, $walk, $handled);
                    } else {
                        $rules[] = $walk->strictJson() ? 'boolean:strict' : 'boolean';

                        if ($walk->mixedBody) {
                            $walk->unenforced(self::mixedBodyFinding(
                                $label,
                                'a boolean',
                                'boolean',
                                '`"1"` and `1` get through, though the contract refuses them, and a form\'s `"true"` is refused, though the contract accepts it.',
                            ), ['type' => [$label]]);
                        }
                    }
                    break;
                case SchemaType::Array:
                    // An array is only ever reached below a `deepObject` here, and how a client writes one inside it is
                    // not defined (`filter[ids][]=1`, `filter[ids]=1,2`). The application says how its clients do in
                    // `query.deep_object_arrays`, and `brackets` is read by the body path below. With no reading
                    // declared (`any`), any rule about its shape or its items would refuse a request the contract allows:
                    // only its presence is checked, the finding says so, and the doctor names the array and each of
                    // the four keywords it states.
                    if ($walk->textSource === 'query string' && $walk->options->deepObjectArrays === DeepObjectArrays::Any) {
                        array_push($handled, 'minItems', 'maxItems', 'items', 'uniqueItems');
                        $keywords = ['type', ...array_values(array_filter(
                            ['items', 'minItems', 'maxItems', 'uniqueItems'],
                            static fn (string $keyword): bool => self::states($schema, $keyword),
                        ))];
                        $walk->unenforced(sprintf(
                            '%1$s is an array inside a `deepObject` query parameter. OpenAPI does not define how a client writes '
                                .'one (`?%2$s[]=1` and `?%2$s=1,2` are both written), so it is checked only for its presence, when it is required, '
                                .'since `query.deep_object_arrays` is `any`.',
                            $label,
                            trim($label, '`'),
                        ), self::entriesOf([$label => $keywords]));

                        break;
                    }

                    // Why: under `comma` the request splits the string a client wrote into the list the rules below judge, in
                    // `prepareForValidation()`. Only the query string reads it so: a JSON body writes an array, and a form
                    // body's list is not part of this reading.
                    if ($walk->textSource === 'query string' && $walk->options->deepObjectArrays === DeepObjectArrays::Comma) {
                        $walk->commaLists[] = $key;
                    }

                    // Why: the reading is the package's default, so a client that writes the array the other way is refused
                    // by a rule no keyword of the contract explains. An array that is the item of another one is read
                    // with the array it belongs to.
                    if ($walk->textSource === 'query string' && ! str_ends_with($label, '[]`')) {
                        $walk->information(self::arrayReadingFinding($label, $walk->options->deepObjectArrays));
                    }

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
                    $bracketed = $walk->textSource === 'query string' && array_filter($declared, self::holdsBracket(...)) !== [];

                    if ($schema->additionalProperties === false && $structural !== []) {
                        // `array:` names keys as a comma list compared against
                        // keys Laravel has already split on dots, so a name with
                        // either would refuse every valid payload.
                        $rules[] = 'array';
                        $walk->unenforced(sprintf(
                            '%s forbids undeclared keys, but declares %s, which Laravel cannot name in `array:`, '
                                .'so undeclared keys are not refused.',
                            $label,
                            self::list($structural),
                        ), ['additionalProperties' => [$label]]);
                    } else {
                        $rules[] = $schema->additionalProperties === false && ! $bracketed ? 'array:'.implode(',', $declared) : 'array';
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
                            $named = self::bracketLabel($walk, $label, $name) ?? rtrim($label, '`').'.'.$name.'`';
                            $property = $schema->properties[$name] ?? new Schema;

                            self::dropFields($walk, sprintf(
                                '%s requires `%s`, which Laravel cannot name in `required_array_keys`, so its '
                                    .'presence is not enforced.',
                                $label,
                                $name,
                            ), [$named => self::askedWhenDropped($walk, $property, true, false) ? ['required'] : []]);

                            continue;
                        }

                        $unsent = self::unsentRequiredName($name, $schema, $walk, $label);

                        // Its finding is written with its property's, and a key PHP never delivers under its own name would
                        // refuse every request.
                        if ($unsent === 'bracketed') {
                            continue;
                        }

                        if ($unsent === 'readOnly') {
                            $walk->information(self::readOnlyFinding(self::bracketLabel($walk, $label, $name) ?? rtrim($label, '`').'.'.$name.'`'));

                            continue;
                        }

                        if ($unsent === 'emptyList') {
                            $walk->information(self::emptyListFinding(self::bracketLabel($walk, $label, $name) ?? rtrim($label, '`').'.'.$name.'`'));

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

                    $value = self::undeclaredValueSchema($schema);
                    $judged = $value !== null && ! self::allowsAnyValue($value);

                    if ($judged && $schema->properties !== []) {
                        $spared = self::sparedNames($schema, $walk, $label, $label, false);
                        $judged = $spared !== null;
                        $spared ??= [];
                    }

                    if ($value !== null) {
                        $handled[] = 'additionalPropertiesSchema';
                    }

                    $children = 'properties';
                    break;
                default:
                    $unmapped = self::isStringEnumeration($schema) ? null : self::unmappedType($schema);

                    if ($unmapped !== null) {
                        // Without a type rule Laravel reads `min` and `max` as a string
                        // length, so a bound on an untyped field is reported rather than
                        // half-translated.
                        $message = sprintf('%s %s, so no type rule is emitted for it.', $label, $unmapped);

                        // Why: a field that states no type and nothing else accepts any value, in the contract and in the
                        // request. A keyword it writes besides that constrains a value (an `enum`, a `oneOf`) is one the
                        // rule set does not carry. An annotation (`default`, `deprecated`) constrains nothing.
                        $untyped = $schema->types === [];
                        $keywords = $untyped ? self::writtenKeywords($written) : ['type'];

                        if ($untyped && ($keywords === [] || self::isTextEnumeration($schema, $keywords, $walk))) {
                            $walk->information($message);
                        } else {
                            $walk->unenforced($message, self::entriesOf([$label => $keywords]));
                        }
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
                // Why: an item of a list read from the query string is written `filter[ids][]`, with the bracket inside
                // the backticks, as every property below it is labeled (`filter[ids][][a]`).
                $itemLabel = $walk->textSource === 'query string' ? rtrim($label, '`').'[]`' : $label.'[]';

                self::field($walk, $key.'.*', $itemLabel, $schema->items, '', $contentType);
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
                valuesJudged: $judged,
                parentLabel: $label,
            );

            if ($judged) {
                assert($value instanceof Schema);
                self::mapValues($walk, $key.'.', $value, $spared, $label);
            }
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
            $walk->unenforced(sprintf(
                '%s points back at `%s`, so nothing below it is validated: no rule set reaches an '
                    .'infinite depth.',
                $label,
                implode('` and `', [$position, ...array_map(
                    static fn (Schema $branch): string => (string) $branch->recursesTo,
                    $site->allOf,
                )]),
            ), ['$ref' => [$label]]);

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
            $maps = array_filter($cut['keys'], static fn (string $key): bool => isset($walk->mapKeys[$key])) !== [];
            $lists = array_filter($cut['keys'], static fn (string $key): bool => str_ends_with($key, '.*') && ! isset($walk->mapKeys[$key])) !== [];
            $fields = count(array_filter($cut['keys'], static fn (string $key): bool => ! str_ends_with($key, '.*') && ! isset($walk->mapKeys[$key]))) > 0;

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
                    $maps ? sprintf('An empty map of `%s` passes: it carries no data, and it is its values that are cut.', $cut['name']) : null,
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
     * Why a required name is one a client does not send, or null when it does: a `readOnly` property is left out of a
     * request, in a query string PHP never delivers a name with brackets under its own name, and written with brackets
     * an array that may be empty has no form at all, so a client with an empty one sends nothing.
     *
     * The last reason is the query string's and `brackets`' only. A JSON body writes `[]`, and `comma` writes an empty
     * list as `?filter[ids]=`, so there the name is one a client sends.
     *
     * @return 'bracketed'|'readOnly'|'emptyList'|null
     *
     * @throws ConflictingInputException
     */
    private static function unsentRequiredName(string $name, Schema $object, RuleWalk $walk, string $label): ?string
    {
        if ($walk->textSource === 'query string' && self::holdsBracket($name)) {
            return 'bracketed';
        }

        if (isset($object->properties[$name]) && self::isReadOnly($object->properties[$name], $walk, $label)) {
            return 'readOnly';
        }

        if (
            $walk->textSource === 'query string'
            && $walk->options->deepObjectArrays === DeepObjectArrays::Brackets
            && isset($object->properties[$name])
            && self::mayBeEmptyList($object->properties[$name], $walk, $label)
        ) {
            return 'emptyList';
        }

        return null;
    }

    /**
     * Whether a property is an array the schema allows to be empty, once its `allOf` is folded.
     *
     * @throws ConflictingInputException
     */
    private static function mayBeEmptyList(Schema $property, RuleWalk $walk, string $label): bool
    {
        $merged = AllOfMerger::merge($property, $walk->identity, $label);

        return $merged->soleType() === SchemaType::Array && ($merged->minItems ?? 0) === 0;
    }

    private static function emptyListFinding(string $label, string $required = 'required'): string
    {
        return sprintf(
            '%1$s is %2$s and its schema accepts an empty list, so the request accepts it left out. A client writes a '
                .'list with brackets, and an empty one has no form there (PHP\'s `http_build_query()` writes nothing for it, '
                .'and `?%3$s[]=` is one empty item), so asking for the name would refuse a client with nothing to send. When '
                .'it is sent, it is judged as any list is.',
            $label,
            $required,
            trim($label, '`'),
        );
    }

    /**
     * The line that names how an array inside a `deepObject` is read and the key that changes it. Information, so it
     * counts for nothing: it states a reading the application declares, and no constraint is lost.
     */
    private static function arrayReadingFinding(string $label, DeepObjectArrays $reading): string
    {
        // A map's wildcard stands for any key the client writes.
        $query = str_replace('[*]', '[key]', trim($label, '`'));

        return $reading === DeepObjectArrays::Comma
            ? sprintf(
                '%1$s is an array inside a `deepObject` query parameter, read as a comma list (`?%2$s=1,2`) or a list written '
                    .'with brackets, since `query.deep_object_arrays` is `comma`: an item cannot hold a comma. Set it to '
                    .'`brackets` or `any` if your clients write it otherwise.',
                $label,
                $query,
            )
            : sprintf(
                '%1$s is an array inside a `deepObject` query parameter, read as a list written with brackets (`?%2$s[]=1`), '
                    .'since `query.deep_object_arrays` is `brackets`: `?%2$s=1,2` is refused. Set it to `comma` or `any` if '
                    .'your clients write it otherwise.',
                $label,
                $query,
            );
    }

    /**
     * Whether an object requires a property a client sends. `required` names the keys of a request, so a name no client
     * sends gives it nothing to put in one.
     *
     * @throws ConflictingInputException
     */
    private static function requiresSentProperty(Schema $object, RuleWalk $walk, string $label): bool
    {
        foreach ($object->required as $name) {
            if (self::unsentRequiredName((string) $name, $object, $walk, $label) === null) {
                return true;
            }
        }

        return false;
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
     * What a body that is JSON and a form says of a number or a boolean field: it keeps the loose rule, and the
     * sentence names what a JSON client gets through. (An integer says it on its own: it has a rule that judges a JSON
     * number exactly.) A gap rather than a stricter rule, so it counts in the build warning, and the caller records the
     * field's `type` as left unenforced, which the doctor reports.
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
     * **Both sentences are information.** `"1"` and `"0"` are what a client of a query string or a form sends for a
     * boolean, so the request accepts what that client writes and loses no constraint: neither the build's warning nor
     * the doctor counts it. The sentence is for the reader of the file, who finds the string `validated()` holds.
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

        // The code the finding hands over reads the key PHP delivers the name under (`filter.active` for
        // `filter[active]`), asked of the query bag: the request's own `has()` reads the body too. A nested key needs
        // `Arr::has()`, since `query->has()` takes a head only. A step that holds a dot (`filter[a.b]`) is no dotted
        // key at all, so the read goes by the list of steps, out of `validated()`, which holds it nested under that dot.
        $bare = trim($label, '`');

        // An item of a list is no key of its own: `validated('filter.flags.0')` is the first item. A plain list
        // (`filter[flags][]`) is read whole, and anything deeper (an item of an object in a list, a list of lists, a
        // map's list, where `validated('filter.*')` reads nothing) has no single key to read, so each value is.
        if ($walk->textSource === 'query string' && str_contains($bare, '[]')) {
            $list = substr_count($bare, '[]') === 1 && str_ends_with($bare, '[]') && ! str_contains($bare, '[*]')
                ? self::deliveredPath(substr($bare, 0, -2))
                : null;

            $walk->information(self::textBooleanItemFinding($label, $tooMany, $quote($words), $list));

            return new InRule($spellings);
        }

        $delivered = self::deliveredPath($bare) ?? [$bare];
        $key = self::pathKey($delivered);

        if (array_filter($delivered, static fn (string $step): bool => str_contains($step, '.')) !== []) {
            $steps = implode(', ', array_map(static fn (string $step): string => var_export($step, true), $delivered));
            $holds = sprintf('data_get($request->validated(), [%s])', $steps);
            $read = sprintf('($value = %s) === null ? null : filter_var($value, FILTER_VALIDATE_BOOL)', $holds);
        } else {
            $holds = sprintf('validated(\'%s\')', $key);
            $sent = count($delivered) === 1
                ? sprintf('$request->query->has(\'%s\')', $key)
                : sprintf('\\Illuminate\\Support\\Arr::has($request->query->all(), \'%s\')', $key);
            $read = sprintf('%s ? filter_var($request->validated(\'%s\'), FILTER_VALIDATE_BOOL) : null', $sent, $key);
        }

        $walk->information($walk->textSource === 'query string'
            ? sprintf(
                '%1$s is a boolean read from a query string, where `?name=true` is how OpenAPI writes `true` and '
                    .'Laravel\'s `boolean` refuses it. It accepts %3$s.%2$s '
                    .'`%4$s` holds the string as sent, and `"false"` is a truthy string in PHP: '
                    .'read it with `%5$s`, which is `null` when it was not sent.',
                $label,
                $tooMany,
                $quote($words),
                $holds,
                $read,
            )
            : sprintf(
                '%1$s is a boolean read from a form body, where every value is text and Laravel\'s `boolean` '
                    .'refuses `"true"`. It accepts %3$s.%2$s `validated()` holds the '
                    .'string as sent, which `dto()` reads as a `bool`.',
                $label,
                $tooMany,
                $quote($words),
            ));

        return new InRule($spellings);
    }

    /**
     * The finding for a boolean item of a list read from the query string: where the list is read from, or that each
     * value is read on its own.
     *
     * @param  list<string>|null  $list  the steps PHP delivers the list under, or null when it has no key to read as
     *                                   one
     */
    private static function textBooleanItemFinding(string $label, string $tooMany, string $words, ?array $list): string
    {
        $head = sprintf(
            '%1$s is a boolean read from a query string, where `?name=true` is how OpenAPI writes `true` and '
                .'Laravel\'s `boolean` refuses it. It accepts %3$s.%2$s',
            $label,
            $tooMany,
            $words,
        );

        if ($list === null) {
            return $head.' Each value is the string as sent, and `"false"` is a truthy string in PHP: read each value '
                .'with `filter_var($value, FILTER_VALIDATE_BOOL)`.';
        }

        if (array_filter($list, static fn (string $step): bool => str_contains($step, '.')) !== []) {
            $steps = implode(', ', array_map(static fn (string $step): string => var_export($step, true), $list));
            $holds = sprintf('data_get($request->validated(), [%s])', $steps);
            $values = sprintf('(array) data_get($request->validated(), [%s], [])', $steps);
        } else {
            $holds = sprintf('validated(\'%s\')', self::pathKey($list));
            $values = sprintf('(array) $request->validated(\'%s\', [])', self::pathKey($list));
        }

        return sprintf(
            '%s `%s` holds the strings as sent, and `"false"` is a truthy string in PHP: read the list with '
                .'`array_map(static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL), %s)`, which is '
                .'empty when none was sent.',
            $head,
            $holds,
            $values,
        );
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
     * @param  list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>  $rules
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
            $walk->unenforced($encoded
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
                ), [$encoded ? 'encoding' : 'contentMediaType' => [$label]]);
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
     * lists is one the document's own version gives that part when it writes none ({@see self::defaultPartTypes()}): a
     * contract that spells out its default has said nothing new. A list with nothing in it says nothing either.
     *
     * @throws ConflictingInputException
     */
    private static function reportUnreadEncoding(RuleWalk $walk, string $label, Schema $schema, string $contentType): void
    {
        $types = self::contentTypes($contentType);

        if (array_diff($types, self::defaultPartTypes($schema, $walk, $label)) === []) {
            return;
        }

        $walk->unenforced(sprintf(
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
        ), ['encoding' => [$label]]);
    }

    /**
     * The `contentType`s OpenAPI gives a part that writes none, so that spelling one out says nothing new.
     *
     * **The versions disagree, so four kinds of part read the table the document's own version handed the request
     * body** (`Contract\EncodingDefaults`): a `type: string` file part, a string with no `format: byte`, a
     * `format: byte` string, and a number, an integer or a boolean. Each minor is read by its latest patch text (3.0.x
     * as 3.0.4, 3.1.x as 3.1.1, which 3.1.2 repeats), and this learns no version: a body built by hand carries the
     * widest table, which reports nothing a version could call a default. What no version disagrees on is here: an
     * object is `application/json`, an array has its items' default, and a file part with no `type`, or a part with no
     * `type` at all, is `application/octet-stream`. A file part written with another type reads that type's row, as
     * 3.0.4 does.
     *
     * **A 3.1 string with a `contentEncoding` is `application/octet-stream` in 3.1.1, and reads as a plain string
     * here,** since the schema does not carry `contentEncoding`. The 3.1 table holds both types for a string, so a
     * correct `contentEncoding` string written `application/octet-stream` is not reported, and neither is a plain
     * string written so.
     *
     * @return list<string>
     *
     * @throws ConflictingInputException
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    private static function defaultPartTypes(Schema $schema, RuleWalk $walk, string $label): array
    {
        $table = $walk->encodingDefaults;

        if ($schema->isFilePart && $schema->types === []) {
            return ['application/octet-stream'];
        }

        if ($schema->isFilePart && $schema->soleType() === SchemaType::String) {
            return $table->typedFile;
        }

        if (self::isObjectBody($schema)) {
            return ['application/json'];
        }

        return match ($schema->soleType()) {
            SchemaType::String => $schema->format === 'byte' ? $table->byteString : $table->string,
            SchemaType::Integer, SchemaType::Number, SchemaType::Boolean => $table->scalar,
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
     * @param  list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>  $rules
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
                $walk->unenforced(sprintf(
                    '%s declares `format: %s`, which no Laravel rule means the same as, so it is not enforced.',
                    $label,
                    $schema->format,
                ), ['format' => [$label]]);
            }

            $handled[] = 'format';
        }

        if ($schema->pattern !== null) {
            $translation = PatternTranslation::of($schema->pattern);

            if ($translation->rule !== null) {
                $rules[] = $translation->rule;
            } else {
                $walk->unenforced(sprintf(
                    '%s declares a `pattern` that %s, so it is not enforced rather than enforced differently.',
                    $label,
                    $translation->reason,
                ), ['pattern' => [$label]]);
            }

            $handled[] = 'pattern';
        }
    }

    /**
     * What an integer's or a number's own keywords become.
     *
     * Beside `integer` or `numeric`, `min` and `max` compare the value, and `gt` and `lt` take a number as readily as a
     * field name. `multipleOf` is the package's `MultipleOf` on every field, which decides from the number's digits
     * where Laravel's `multiple_of` writes out every digit of an exponent or throws.
     *
     * @param  list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>  $rules
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

        if ($schema->multipleOf !== null) {
            $rules[] = new MultipleOf(self::number($schema->multipleOf));
        }

        array_push($handled, 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf');
    }

    /**
     * @param  list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>  $rules
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
     * `distinct` for a number and `distinct:strict` for a string or a boolean, or null where it would not mean
     * `uniqueItems`.
     *
     * JSON Schema compares two numbers by value, so `1` and `1.0` are one element and so are `1` and `+1` in a form:
     * the loose `distinct` says the same, and `distinct:strict` would call them two. A string or a boolean has no
     * second spelling in JSON, and `"1"` and `"01"` are two strings, so those keep the strict comparison. A form's
     * boolean has two (`"true"` and `"1"`), which `distinct:strict` calls two elements: a gap the guide states.
     *
     * The loose comparison is not exact at float precision, and the guide says so: PHP compares an integer with a
     * float, or two decimal strings, as two floats, so two numbers it rounds the same way count as one
     * (`9007199254740993` and `9007199254740992.0` in JSON, `0.1` and `0.10000000000000000001` in a form). The
     * generated file carries no finding for it.
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
        $type = $items->soleType();

        if (! in_array($type, [SchemaType::String, SchemaType::Integer, SchemaType::Number, SchemaType::Boolean], true) || $items->isNullable()) {
            return null;
        }

        return in_array($type, [SchemaType::Integer, SchemaType::Number], true) ? 'distinct' : 'distinct:strict';
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

        $walk->unenforced(
            sprintf('%s states %s, which nothing in this rule set enforces.', $label, self::list($keywords)),
            self::entriesOf([$label => $keywords]),
        );
    }

    /**
     * Write the finding of a field, or of several, the rule set drops whole, and record what each states that nothing
     * enforces.
     *
     * A field with no rule key (a `query` name PHP appends to, a `*` Laravel reads as a wildcard, a form name PHP
     * delivers under no name or under another's) gets a finding that says so, and none of what the contract states
     * about it is enforced: not its presence, not its type, and not any keyword a rule could have come from. Those are
     * the same unenforced keywords as any other, so they are named the same way, with what
     * {@see self::droppedKeywords()} says for each field.
     *
     * Why information when there is nothing to name: a field that states no type, no keyword and no presence loses
     * nothing by having no rule, and the sentence only tells a reader of the file where the field went.
     *
     * A field of a closed object that no rule can name also stops the object from refusing undeclared keys (`array:`
     * would refuse the key PHP delivers the field under), which is the object's own loss: it is recorded first, on the
     * object's label, and makes the sentence a gap whatever the field states.
     *
     * @param  array<string, list<string>>  $entries  the keywords each field the sentence is about leaves unenforced,
     *                                                by the field's label
     * @param  string|null  $closure  the label of the closed object around the field, when it is closed
     */
    private static function dropFields(RuleWalk $walk, string $finding, array $entries, ?string $closure = null): void
    {
        $named = array_filter($entries, static fn (array $keywords): bool => $keywords !== []);

        if ($named === [] && $closure === null) {
            $walk->information($finding);

            return;
        }

        // The closure first, then each field: the order the entries were written in before one method wrote them.
        $walk->unenforced($finding, self::entriesOf([
            ...($closure === null ? [] : [$closure => ['additionalProperties']]),
            ...$named,
        ]));
    }

    /**
     * Record what a field the rule set drops leaves unenforced, for a field whose finding is written elsewhere.
     *
     * @param  list<string>  $keywords
     */
    private static function leaveDropped(RuleWalk $walk, string $label, array $keywords): void
    {
        foreach ($keywords as $keyword) {
            $walk->leaveUnenforced($keyword, $label);
        }
    }

    /**
     * The entries of a gap, by keyword, from the keywords of each field it is about, by the field's label: what
     * {@see RuleWalk::unenforced()} takes. The keywords come out in the order they were met, each field in its own.
     *
     * @param  array<string, list<string>>  $byLabel
     * @return non-empty-array<string, non-empty-list<string>>
     */
    private static function entriesOf(array $byLabel): array
    {
        $entries = [];

        foreach ($byLabel as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (! in_array((string) $label, $entries[$keyword] ?? [], true)) {
                    $entries[$keyword][] = (string) $label;
                }
            }
        }

        assert($entries !== [], 'A gap names at least one keyword.');

        return $entries;
    }

    /**
     * What a field the rule set drops states and nothing enforces: as the field would be read if it were kept, its
     * presence, the `dependentRequired` that asks for it, its type, then every keyword a rule could have come from.
     *
     * @param  bool  $required  whether the rule set would have asked for the field, which the caller decides: never for
     *                          a `readOnly` property or a top-level name of a `PATCH`, as for a field it keeps
     * @param  bool  $dependent  whether `dependentRequired` asks for it
     * @return list<string>
     */
    private static function droppedKeywords(Schema $schema, bool $required, bool $dependent = false): array
    {
        $keywords = [];

        if ($required) {
            $keywords[] = 'required';
        }

        if ($dependent) {
            $keywords[] = 'dependentRequired';
        }

        if ($schema->types !== []) {
            $keywords[] = 'type';
        }

        return array_values(array_unique([...$keywords, ...self::statedKeywords($schema, [])]));
    }

    /**
     * Whether the contract asks for a property the rule set drops, read as a field the rule set keeps is: not a
     * `readOnly` property, which only a response has to carry, and not a top-level name of a `PATCH`, whose body is a
     * partial update.
     */
    private static function askedWhenDropped(RuleWalk $walk, Schema $property, bool $required, bool $topLevel): bool
    {
        return $required && ! ($topLevel && $walk->patchBody) && ! self::statesReadOnly($property);
    }

    /**
     * Whether `dependentRequired` asks for a property the rule set drops, read as a kept trigger would ask: the
     * dependent is not `readOnly`, and not one the object already asks for (a top-level name of a `PATCH` is not asked
     * for, so the dependency still is).
     */
    private static function askedByDependency(RuleWalk $walk, Schema $property, bool $required, bool $topLevel, bool $dependent): bool
    {
        return $dependent
            && ! self::statesReadOnly($property)
            && ! ($required && ! ($topLevel && $walk->patchBody));
    }

    /**
     * Whether a property, or a branch of its `allOf`, says `readOnly`. Read without folding the `allOf`: a field the
     * rule set drops gets no rules, so a contradiction between its branches is not refused here (it was not before the
     * rule set read what a dropped field states).
     */
    private static function statesReadOnly(Schema $property): bool
    {
        if ($property->readOnly) {
            return true;
        }

        foreach ($property->allOf as $branch) {
            if (self::statesReadOnly($branch)) {
                return true;
            }
        }

        return false;
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
        // The value schema is the same: the contract's keyword is `additionalProperties`, whatever it holds.
        $named = array_map(
            static fn (string $keyword): string => match ($keyword) {
                'isFilePart' => $schema->contentMediaType === null ? 'format' : 'contentMediaType',
                'additionalPropertiesSchema' => 'additionalProperties',
                'recursesTo' => '$ref',
                default => $keyword,
            },
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
     * The value constraints a written schema, or any `allOf` branch of it, states besides its type: an `enum` (a
     * `const` is a one-value enum), and each keyword the package does not read that constrains a value. `default`,
     * `deprecated`, `$defs` and `$anchor` are the ones that do not: they annotate or name, and accept every value.
     *
     * Read on the written schema, never the merged one: the merge drops a branch's ignored keywords. An `enum` counts
     * because `Rule::in()` compares `(string) $value`, so an untyped `[1, 2, 3]` accepts `"1"` and `true`.
     *
     * @return list<string>
     */
    private static function writtenKeywords(Schema $written): array
    {
        $keywords = $written->enum === null ? [] : ['enum'];
        array_push($keywords, ...array_keys(array_diff_key($written->ignored, array_flip(['default', 'deprecated', '$defs', '$anchor']))));

        foreach ($written->allOf as $branch) {
            array_push($keywords, ...self::writtenKeywords($branch));
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Whether an untyped field is read from text, and writes nothing but an `enum` (or a `const`) of numbers and
     * strings.
     *
     * Why: `Rule::in()` compares `(string) $value` and refuses an array, so it accepts exactly the texts of its values,
     * which is how a query string or a form writes a number. It is a standard reading, as a text boolean's `"1"` is. In
     * a JSON body `"1"` and `true` pass for `1`, and a boolean or a `null` among the values has no text of its own.
     *
     * @param  list<string>  $keywords  what {@see self::writtenKeywords()} found on the written schema
     */
    private static function isTextEnumeration(Schema $merged, array $keywords, RuleWalk $walk): bool
    {
        return $walk->textSource !== null
            && $keywords === ['enum']
            && $merged->enum !== null
            && array_filter($merged->enum, static fn (mixed $value): bool => ! is_int($value) && ! is_float($value) && ! is_string($value)) === [];
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
     * A root that is not an object records what it states, on `The body itself`, and an optional form body records the
     * names it asks only beside another key. The `PATCH` reading and the empty form are information, like the `PUT`
     * one. Each finding is written as the kind it is, in the order the body reads.
     */
    private static function rootFindings(Operation $operation, Schema $schema, RequestBody $body, RuleWalk $walk): void
    {
        if (! self::isObjectBody($schema)) {
            $keywords = self::droppedKeywords($schema, $body->required);

            if (self::holdsValueSchema($schema)) {
                $keywords[] = 'additionalProperties';
            }

            self::dropFields($walk, 'The body\'s own schema is not an object, so no rule is derived from it. '
                .'A rule key is a field name, and a body that is a bare value has none.', ['The body itself' => $keywords]);

            return;
        }

        // Why: the one place a request is stricter than the contract by design. Matching
        // it would take a check that holds for an object or for anything else, at a root
        // that has no field for a rule to sit on. A form cannot send a non-object, so only
        // a body read as JSON is stricter.
        if ($schema->types === [] && $walk->jsonBody) {
            $walk->stricter('The body names `properties` and no `type`, so it is read as an object, and a JSON '
                .'body that is not one (the string `"Ada"` is valid against this schema) is refused. That is '
                .'stricter than the contract, since the request cannot accept an object or anything else at '
                .'the root. Add `type: object` to the schema to make the reading the contract\'s own.');
        }

        // The request refuses an empty content for a required body whatever its type, bar a form,
        // which it cannot read that way.
        if ($body->required && $walk->formBody) {
            // Why: an empty form is how a form writes `{}`, which the schema accepts when no required key refuses it, so
            // the sentence only tells a reader of the file what the request accepts.
            $walk->information(self::EMPTY_FORM_FINDING);
        }

        if ($operation->method === HttpMethod::Patch && $schema->required !== []) {
            // Why: a partial update is what `PATCH` means (RFC 5789), so the sentence says what the request reads and
            // loses no constraint.
            $walk->information(sprintf(
                'A `PATCH` is a partial update, so the schema\'s required list (%s) is read as '
                    .'empty here. Every property is `sometimes`.',
                self::list($schema->required),
            ));
        } elseif (! $body->required && $schema->required !== [] && $walk->formBody) {
            // Why: a JSON body is not named here, since the request reads its raw content and refuses `{}` itself.
            // A form is the one it cannot read that way: its fields need not show in the content.
            $alone = count(array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required])) === 1;

            $walk->unenforced(sprintf(
                'The body may be absent entirely while its schema requires %s%s. A rule cannot tell a body that is not '
                    .'sent from a form that sends none of the body\'s keys, since both leave every key out of the data, '
                    .'so the form is accepted, which the contract refuses.',
                self::list($schema->required),
                $alone
                    ? ' and declares nothing else. There is no other key to name, so it is `sometimes`'
                    : (count($schema->required) === 1
                        ? ', so it is required only when another key of the body is sent'
                        : ', so each is required only when another key of the body is sent'),
            ), ['required' => array_map(static fn (string $name): string => '`'.$name.'`', $schema->required)]);
        }

        if ($operation->method === HttpMethod::Put) {
            $optional = array_values(array_diff(array_map('strval', array_keys($schema->properties)), $schema->required));

            if ($optional !== []) {
                $walk->information(sprintf(
                    'A `PUT` whose schema leaves %s optional. Nothing fills an absent field from a '
                        .'schema default, so a field the client omitted keeps its stored value. '
                        .'Override `update()` on your controller for replacement semantics.',
                    self::list($optional),
                ));
            }
        }
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
     * The path PHP delivers a name of a form body or of the query string under, or null when it delivers it under none:
     * `user.name` is `['user_name']`, `a b` is `['a_b']` and `page[size]` is `['page', 'size']`.
     *
     * Asked of PHP itself: `parse_str()` is the parser both go through, and what it keeps is the answer, where a
     * rewrite of its rules written here would be one more thing to keep in step with it. Measured against a real SAPI
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
     * one that is equal to what it becomes (`user.name` and `user_name`). Neither gets a rule, and one finding names
     * them, and a closed form body leaves their key out of the validated data; a name below them keeps its own.
     *
     * **A name above the names a form nests under it is judged by shape** (`page` beside `page[size]`, `filter[a]`
     * beside `filter[a][b]` at any depth, `ids` beside `ids[]`): its rules judge the value only when it is not an
     * array, which is what the nested names are ({@see self::recordShapes()}), and each nested name keeps its own. A
     * `*` name has no rule but claims its path, so it shapes the name above it unless the contract asks for that name
     * (a `PATCH` asks for none through `required`) or the body is closed (the level could not be closed, since the key
     * check cannot name a `*` key), whose rule then keeps its form and says it is stricter. A name typed `array` or
     * `object` may be an array itself, so it keeps the collision: no rule for it or the name below it. **A body that is
     * JSON as well shapes a top-level name PHP leaves alone** the same way, with the array dropped, since no name below
     * it has a rule there; a name PHP rewrites keeps the collision, as a JSON client sends it under one key and a form
     * client under another.
     *
     * **A body that is JSON as well keeps the rule keyed by the name the contract writes**, since a JSON client sends
     * exactly that, and the value a form client sends under the other name is not checked: no one rule set holds both
     * keys without refusing one of the clients. A presence rule is left out for the same reason, and a finding says
     * where the form value arrives.
     */
    private static function planFormNames(Schema $schema, RuleWalk $walk, bool $patch): void
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

        // What each name leaves unenforced once no rule is emitted for it, as the field would be read if it kept its rule.
        $dropped = static function (array $members) use ($schema, $walk, $dependent): array {
            $entries = [];

            foreach ($members as $member) {
                $property = $schema->properties[$member] ?? new Schema;
                $label = '`'.$member.'`';
                $entries[$label] = self::droppedKeywords(
                    $property,
                    self::askedWhenDropped($walk, $property, in_array($member, $schema->required, true), true),
                    self::askedByDependency($walk, $property, in_array($member, $schema->required, true), true, in_array($member, $dependent, true)),
                );
            }

            return $entries;
        };

        $declared = array_values(array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required, ...$dependedOn]));
        $names = array_values(array_filter(
            $declared,
            static fn (string $name): bool => self::unescapableKey($name) === null,
        ));

        // Why: a `*` name has no rule, but a form still delivers its value below the name above it, so it claims its
        // path to the name above it. Only in a body read only as a form: a body that is JSON as well keeps today's rule.
        $claimed = [];

        if (! $walk->mixedBody) {
            foreach ($declared as $name) {
                $path = self::unescapableKey($name) === null ? null : self::deliveredPath($name);

                if ($path !== null) {
                    $claimed[$name] = $path;
                }
            }
        }

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
                self::dropFields($walk, sprintf(
                    '`%s` is a property of a form body that PHP delivers under no name, so no rule is emitted for it, and '
                        .'neither its type nor its presence is enforced.',
                    $name,
                ), $dropped([$name]));

                continue;
            }

            $groups[$key][] = $name;

            // A bracketed name delivers below a key another name uses: a strict prefix of its path, compared step by
            // step (`filter[a]` is no prefix of `filter[ab][c]`). Beside JSON, only a top-level key counts: below it,
            // the names keep their literal rule keys, which Laravel reads `[` in as a plain character.
            $path = $paths[$name] ?? [];

            foreach ($names as $other) {
                $prefix = $paths[$other] ?? null;

                if (
                    $prefix === null
                    || $other === $name
                    || ! self::isBelow($path, $prefix)
                    || (count($prefix) > 1 && $walk->mixedBody)
                ) {
                    continue;
                }

                $overlaps[] = [$name, $other, $key, self::pathKey($prefix)];
            }
        }

        $collided = [];

        foreach ($groups as $key => $members) {
            $members = array_values(array_unique($members));

            if (count($members) < 2) {
                continue;
            }

            self::withhold($members, $walk, $collided);

            self::dropFields($walk, sprintf(
                '%s reach Laravel under one name, `%s`, which a form delivers them as, so no rule can tell them apart. '
                    .'None is emitted for them, and neither their type nor their presence is enforced, nor do they '
                    .'take part in `required_with` or `dependentRequired`.',
                self::list($members),
                $key,
            ), $dropped($members));
        }

        $shaped = [];

        foreach ($overlaps as [$name, $other, $key, $head]) {
            // Beside JSON, a name PHP rewrites is sent under one key by a JSON client and another by a form client, so no
            // one shape check holds both.
            if ($walk->mixedBody && self::phpRewrites($other)) {
                self::dropFields($walk, sprintf(
                    '`%1$s` is delivered by a form as `%2$s`, below the key `%3$s` that `%4$s` uses, and `%4$s` is a name PHP '
                        .'rewrites: a JSON client sends `%4$s` under that key and a form client as `%3$s`, so no one shape check '
                        .'holds both. None is emitted for either, and neither their type nor their presence is enforced, nor do '
                        .'they take part in `required_with` or `dependentRequired`.',
                    $name,
                    $key,
                    $head,
                    $other,
                ), $dropped([$name, $other]));

                self::withhold([$name, $other], $walk, $collided);

                continue;
            }

            $type = self::arrayOrObject($schema, $other, $walk);

            if ($type !== null) {
                self::dropFields($walk, sprintf(
                    '`%1$s` is delivered by a form as `%2$s`, below the key `%3$s` that `%4$s` uses. `%4$s` is typed `%5$s`, so its '
                        .'own value may be an array, and no rule can tell their values apart. None is emitted for either, and '
                        .'neither their type nor their presence is enforced, nor do they take part in `required_with` or '
                        .'`dependentRequired`.',
                    $name,
                    $key,
                    $head,
                    $other,
                    $type,
                ), $dropped([$name, $other]));

                self::withhold([$name, $other], $walk, $collided);

                continue;
            }

            $shaped[$other] = true;
        }

        // A `*` name below a name claims its path, which shapes the name above it unless the contract asks for it: the
        // array a form builds for `page[*]` would then stand in for a `page` that must be sent.
        $below = [];

        foreach ($claimed as $claimedName => $claimedPath) {
            foreach ($names as $upper) {
                if (isset($paths[$upper]) && self::isBelow($claimedPath, $paths[$upper])) {
                    $below[$upper][] = $claimedName;
                }
            }
        }

        foreach ($below as $upper => $starred) {
            $upper = (string) $upper;

            if (isset($collided[$upper]) || isset($shaped[$upper]) || self::arrayOrObject($schema, $upper, $walk) !== null) {
                continue;
            }

            $asked = (! $patch && in_array($upper, $schema->required, true)) || in_array($upper, $dependent, true);

            // Why: a closed body refuses `page[evil]`, and the key check of the level a shaped `page` holds cannot name the
            // `*` key (Laravel hides it behind a placeholder), so that level would stay open and accept what the contract
            // refuses. `page` keeps its rule, which refuses every array under it, as it did.
            if (! $asked && $schema->additionalProperties === false) {
                $walk->stricter(sprintf(
                    '`%1$s` is a property of a closed form body that also declares %2$s, which a form delivers below `%1$s` and '
                        .'no rule judges. Judging `%1$s` by shape would leave the level below it open, which accepts a key the '
                        .'contract refuses (`%1$s[x]=5`), since the key check cannot name a `*` key, so `%1$s` keeps its rule '
                        .'without a shape check. A form that sends %2$s (`%3$s=5`) delivers an array under `%1$s`, which that '
                        .'rule refuses although the contract accepts it: the request is stricter than the contract there.',
                    $upper,
                    self::list($starred),
                    $starred[0],
                ));

                continue;
            }

            if (! $asked) {
                $shaped[$upper] = true;

                continue;
            }

            $walk->stricter(sprintf(
                '`%1$s` is a property of a form body that also declares %2$s, which a form delivers below `%1$s` and no rule '
                    .'judges. The contract asks for `%1$s`, so the array a form builds for %2$s must not stand in for it, and '
                    .'`%1$s` keeps its rule without a shape check. A form that sends `%1$s` and %2$s together (`%1$s=2&%3$s=5`) '
                    .'delivers an array under `%1$s`, which that rule refuses although the contract accepts both: the request '
                    .'is stricter than the contract there.',
                $upper,
                self::list($starred),
                $starred[0],
            ));
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
                self::dropFields($walk, sprintf(
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
                ), $dropped([$name]));

                if ($path !== null) {
                    $walk->deliveredKeys[$name] = self::pathKey($path);
                }
            }

            self::recordShaped($schema, $shaped, $collided, $walk, $patch);

            return;
        }

        foreach ($names as $name) {
            if (isset($collided[$name]) || $paths[$name] === null || ! self::phpRewrites($name)) {
                continue;
            }

            $walk->formPaths[$name] = $paths[$name];
            $walk->deliveredKeys[$name] = self::pathKey($paths[$name]);
        }

        self::recordShaped($schema, $shaped, $collided, $walk, $patch);

        // The key two names deliver under one holds no rule, so only the array a form builds for a name below it can
        // reach it, and `dto()` would hand that array to a property typed for a scalar.
        foreach ($groups as $members) {
            $members = array_values(array_unique($members));
            $path = $paths[$members[0]] ?? null;

            if (count($members) < 2 || $path === null || count($path) !== 1) {
                continue;
            }

            foreach ($walk->formPaths as $other => $otherPath) {
                if (self::isBelow($otherPath, $path)) {
                    $walk->scalarNames[] = $path[0];

                    break;
                }
            }
        }
    }

    /**
     * Withhold names that reach Laravel under one key: no rule is emitted for them, and neither their type nor their
     * presence is enforced.
     *
     * @param  list<string>  $members
     * @param  array<string, true>  $collided
     */
    private static function withhold(array $members, RuleWalk $walk, array &$collided): void
    {
        foreach ($members as $member) {
            $collided[$member] = true;
            $walk->unchecked[$member] = 'another name in the body reaches Laravel under the same key';
            $walk->withheld[$member] = true;
        }
    }

    /**
     * Record the names of a form body judged by shape, once the names below them have their paths: the shape check
     * ({@see self::recordShapes()}) reads them, `dto()` reads each value back only when it is not an array, and a name
     * the contract asks for is said to be stood in for by the array of the names below it.
     *
     * Beside JSON the array branch is always `exclude` and `rules()` cannot tell the clients apart, so a JSON client's
     * array under the name passes and is left out of the validated data: a gap, which the file states.
     *
     * @param  array<string, true>  $shaped
     * @param  array<string, true>  $collided
     */
    private static function recordShaped(Schema $schema, array $shaped, array $collided, RuleWalk $walk, bool $patch): void
    {
        foreach (array_keys($shaped) as $name) {
            $name = (string) $name;

            // Two names under one key are withheld above, so the name below them keeps its own rule and nothing is
            // judged by shape above it.
            if (isset($collided[$name])) {
                continue;
            }

            $walk->shaped[$name] = true;

            // Why: an array never reaches `validated()` beside JSON (`exclude` drops it), so `dto()` has nothing to drop.
            if (! $walk->mixedBody) {
                $walk->scalarNames[] = $name;
            } else {
                $walk->unenforced(sprintf(
                    '`%1$s` is judged by shape in a body that is JSON and a form: its rules judge a value that is not an array, '
                        .'and the array a form builds for the names nested under it passes and is left out of the validated data. '
                        .'One rule set serves both clients and `rules()` does not branch on the media type, so a JSON client\'s '
                        .'array under `%1$s` (`{"%1$s": [1]}`) passes and is left out of the validated data too, which the '
                        .'contract refuses.',
                    $name,
                ), ['type' => ['`'.$name.'`']]);
            }

            // Why: the array a form builds for the names below stands in for the name, so a form can send the body
            // without it, and the payload cannot promise it. A `PATCH` asks for no presence, so nothing stands in.
            if (! $patch && in_array($name, $schema->required, true)) {
                $walk->standIn[$name] = $walk->mixedBody
                    ? 'whether it was sent, since an array under it, which a form builds for the names nested under it or a JSON '
                        .'client sends, stands in for it'
                    : 'whether it was sent, since the array a form builds for the names nested under it stands '
                        .'in for it, and a form that sends only those names satisfies the rule on it';
            }
        }
    }

    /**
     * `array` or `object` when a property is typed as one of them, or null: such a name may be an array itself, so no
     * shape check tells its value from the array a form builds for the names below it.
     */
    private static function arrayOrObject(Schema $schema, string $name, RuleWalk $walk): ?string
    {
        if (! isset($schema->properties[$name])) {
            return null;
        }

        $type = AllOfMerger::merge($schema->properties[$name], $walk->identity, '`'.$name.'`')->soleType();

        return $type === SchemaType::Array || $type === SchemaType::Object ? $type->value : null;
    }

    /**
     * Record the shape check of each name of a form body judged by shape, once every rule key exists: the rules of the
     * name are judged only when its value is not an array, and the array a form builds is dropped when no name below it
     * has a rule ({@see self::bodyRules()}).
     */
    private static function recordShapes(RuleWalk $walk): void
    {
        foreach (array_keys($walk->shaped) as $name) {
            $name = (string) $name;
            $key = self::fieldKey($name, $walk, '');
            $path = self::deliveredPath($name);

            if ($path === null || ! isset($walk->rules[$key])) {
                continue;
            }

            $walk->whenNotArray[$key] = $path;

            foreach ($walk->formPaths as $other => $otherPath) {
                if (self::isBelow($otherPath, $path) && isset($walk->rules[$walk->deliveredKeys[$other]])) {
                    continue 2;
                }
            }

            $walk->dropsArray[] = $key;

            // Why: `exclude` already keeps the array out of the validated data, so `dto()` has nothing to drop.
            $walk->scalarNames = array_values(array_diff($walk->scalarNames, [$name]));
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
     * JSON and form body's, one of a form's, none for a name with no rule. A name judged by shape gives its own key,
     * which the array of the names below it also fills.
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
     * What each `query` parameter becomes: the rule key it is validated under, or none and the findings that say why.
     *
     * **PHP parses the query string, and it does not keep every name as written.** A `.` or a space becomes `_`
     * (`user.name` arrives as `user_name`), and `[` opens a nested array (`page[size]` arrives as
     * `page => [size => ...]`). The value is still there, under the key PHP delivers, so the rule is keyed by that key
     * ({@see self::pathKey()} of {@see self::deliveredPath()}): `page.size`, `user_name`. Both are asked of PHP, as a
     * form body's names are.
     *
     * **Four kinds of name keep a finding and no rule**, where the value cannot be told from another one or has no key
     * to find it under:
     *
     * - a name with a `*`, which Laravel reads as a wildcard in a rule key and has no escape for (checked first, or
     *   `page[*]` would be keyed `page.*`);
     * - a name PHP appends to (`ids[]`, `a[][b]`, `a[]b`: each value takes the next free index, so no fixed key holds
     *   it), which {@see self::phpAppends()} asks of PHP by sending the name twice;
     * - a name PHP delivers under no name (`[x]`);
     * - a rewritten name whose head is a key the body arrives under too (`page[size]` beside a body property `page`):
     *   one key holds both values, so a rule for the query's would judge the body's. The build keeps its finding rather
     *   than refusing it, as no rule is emitted and so it does not choose which half of the contract to enforce, which
     *   is why a plain name there is refused.
     *
     * **Two names that arrive under the same key get no rule**: `user.name` and `user_name`, `a.b` and `a b`. The value
     * of one is the value of the other, so a rule for either would judge both. So does a name at an index of a list
     * another appends to (`ids[1]` and `ids[]`: PHP appends at any index). One finding names each such pair.
     *
     * **A name above the names PHP nests under it keeps its rules, behind a shape check**: `page` beside `page[size]`,
     * `ids` beside `ids[]`, `filter[a]` beside `filter[a][b]`. PHP builds an array for the nested one, so the plain
     * name's rules judge the value only when it is not an array ({@see self::build()} records it in
     * {@see RuleSet::$whenNotArray}), and the nested names keep theirs. A path is compared with {@see self::isBelow()},
     * step by step, and a name PHP appends to claims the path above its appended step. No finding: it has its rules. A
     * plain name is still refused beside a body key, whether or not it loses its rule.
     *
     * A finding carries the index of each name it is about: the sentence about two names is written on the first of
     * them, and says as much about the second.
     *
     * @return array<int, array{key: string|null, head: string, plain: bool, shaped: bool, shapedBy: list<string>, ruledBelow: bool, findings: list<array{text: string, names: list<int>}>}>
     */
    private static function planQueryNames(Operation $operation, RuleWalk $walk): array
    {
        $keys = [];
        $heads = [];
        $findings = [];
        $claims = [];
        $candidates = [];
        $plain = [];
        $appends = [];

        foreach ($operation->queryParameters as $index => $parameter) {
            $name = $parameter->name;
            $appended = self::phpAppends($name);
            $path = self::deliveredPath($name);
            $keys[$index] = null;
            $heads[$index] = $name;
            $findings[$index] = [];
            $finding = self::unescapableKey($name);

            if ($finding === null && $appended !== null) {
                $finding = self::queryNameAppended($name);
            } elseif ($finding === null && $path === null) {
                $finding = self::queryNameUndelivered($name);
            }

            if ($finding !== null) {
                $findings[$index][] = ['text' => $finding, 'names' => [$index]];

                if (($appended ?? $path) !== null) {
                    $claims[$index] = $appended ?? $path;
                    if ($appended !== null) {
                        $appends[$index] = true;
                    }
                }
            } elseif ($path === [$name]) {
                $keys[$index] = self::ruleKey($name);
                $claims[$index] = $path;
                $plain[$index] = true;
                $candidates[] = $index;
            } elseif (in_array($path[0], $walk->bodyKeys, true)) {
                $findings[$index][] = ['text' => self::queryNameUnderBodyKey($name, $path[0]), 'names' => [$index]];
            } else {
                $keys[$index] = self::pathKey($path);
                $heads[$index] = $path[0];
                $claims[$index] = $path;
                $candidates[] = $index;
            }
        }

        $shaped = [];

        foreach ($candidates as $index) {
            foreach ($claims as $other => $claim) {
                if ($other === $index) {
                    continue;
                }

                $mine = $claims[$index];
                $otherAppends = isset($appends[$other]);

                if (! $otherAppends && $mine === $claim) {
                    $keys[$index] = null;

                    // Two candidates share one finding, written on the first of them.
                    if ($other > $index || ! in_array($other, $candidates, true)) {
                        $findings[$index][] = [
                            'text' => self::queryNamesShareKey(
                                $operation->queryParameters[$index]->name,
                                $operation->queryParameters[$other]->name,
                                self::pathKey($mine),
                            ),
                            'names' => [$index, $other],
                        ];
                    }
                } elseif ($otherAppends && self::isBelow($mine, $claim)) {
                    $keys[$index] = null;
                    $findings[$index][] = [
                        'text' => self::queryNameBelowAppend(
                            $operation->queryParameters[$index]->name,
                            $operation->queryParameters[$other]->name,
                            self::pathKey($claim),
                            self::pathKey($mine),
                        ),
                        'names' => [$index, $other],
                    ];
                } elseif (self::isBelow($claim, $mine) || ($otherAppends && $mine === $claim)) {
                    $shaped[$index][] = $operation->queryParameters[$other]->name;
                }
            }
        }

        return array_map(
            static fn (int $index): array => [
                'key' => $keys[$index],
                'head' => $heads[$index],
                'plain' => isset($plain[$index]),
                'shaped' => $keys[$index] !== null && isset($shaped[$index]),
                'shapedBy' => $keys[$index] !== null ? $shaped[$index] ?? [] : [],
                'ruledBelow' => self::ruledBelow($index, $claims, $candidates, $keys),
                'findings' => $findings[$index],
            ],
            array_keys($keys),
        );
    }

    /**
     * Whether a name that has a rule sits below a name: Laravel keeps an `array` key's unchecked content in
     * `validated()` unless a rule sits below it, which decides how the array PHP builds for the nested names is
     * handled.
     *
     * @param  array<int, list<string>>  $claims
     * @param  list<int>  $candidates
     * @param  array<int, string|null>  $keys
     */
    private static function ruledBelow(int $index, array $claims, array $candidates, array $keys): bool
    {
        if (! isset($claims[$index])) {
            return false;
        }

        foreach ($candidates as $other) {
            if ($keys[$other] !== null && isset($claims[$other]) && self::isBelow($claims[$other], $claims[$index])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path above the step PHP appends at, for a name PHP appends to, or null for a name it does not.
     *
     * `ids[]`, `a[][b]` and `a[]b` each deliver a value at the next free index, so no fixed key holds it: sent twice,
     * the name arrives as two entries of one array. Asked of PHP like {@see self::deliveredPath()}: the name is sent
     * twice, and the first level that holds two entries is the one it appended to (`ids` for `ids[]`, `a` for
     * `a[][b]`). A name PHP replaces (`page[size]`, `user.name`) arrives once, as the second value.
     *
     * @return list<string>|null
     */
    private static function phpAppends(string $name): ?array
    {
        $sent = urlencode($name);

        parse_str($sent.'=1&'.$sent.'=2', $parsed);

        $above = [];
        $node = $parsed;

        while (is_array($node) && $node !== []) {
            if (count($node) > 1) {
                return $above === [] ? null : $above;
            }

            $key = array_key_first($node);
            $above[] = (string) $key;
            $node = $node[$key];
        }

        return null;
    }

    /**
     * Whether a path is strictly below another, compared step by step and never as strings (`filter[a]` is no prefix of
     * `filter[ab][c]`).
     *
     * @param  list<string>  $path
     * @param  list<string>  $prefix
     */
    private static function isBelow(array $path, array $prefix): bool
    {
        return count($prefix) < count($path) && array_slice($path, 0, count($prefix)) === $prefix;
    }

    /**
     * The finding for a `query` name PHP appends to.
     */
    private static function queryNameAppended(string $name): string
    {
        return sprintf(
            '`%s` is a `query` parameter whose name PHP appends to: each value it receives takes the next free '
                .'index of a list, so no fixed key holds it. None is emitted for it, and neither its type nor its '
                .'presence is enforced.',
            $name,
        );
    }

    /**
     * The finding for a `query` name PHP delivers under no name.
     */
    private static function queryNameUndelivered(string $name): string
    {
        return sprintf(
            '`%s` is a `query` parameter whose name PHP delivers under no name, so no key holds its value. None is '
                .'emitted for it, and neither its type nor its presence is enforced.',
            $name,
        );
    }

    /**
     * The finding for a rewritten `query` name whose head is a key the body arrives under too.
     */
    private static function queryNameUnderBodyKey(string $name, string $key): string
    {
        return sprintf(
            '`%s` is a `query` parameter that PHP delivers under `%s`, a key the body arrives under too. One key holds '
                .'both values, so a rule for it would judge the body\'s as well. None is emitted for it, and neither '
                .'its type nor its presence is enforced.',
            $name,
            $key,
        );
    }

    /**
     * The finding for two `query` names PHP delivers under one key: the value of one is the value of the other.
     */
    private static function queryNamesShareKey(string $name, string $other, string $key): string
    {
        return sprintf(
            '`%s` and `%s` are `query` parameters PHP delivers under one key (`%s`), so the value of one is the value '
                .'of the other. None is emitted for either, and neither their type nor presence is enforced.',
            $name,
            $other,
            $key,
        );
    }

    /**
     * The finding for a `query` name that sits at an index of a list another name appends to.
     */
    private static function queryNameBelowAppend(string $name, string $other, string $above, string $key): string
    {
        return sprintf(
            '`%1$s` and `%2$s` are `query` parameters whose keys overlap: PHP appends `%2$s` at any index of `%3$s`, so '
                .'a value at `%4$s` can be the appended one. None is emitted for `%1$s`, and neither its type nor '
                .'presence is enforced.',
            $name,
            $other,
            $above,
            $key,
        );
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
     * How a query parameter is sent, as the build reads it, with a fault recorded for what the document wrote wrong.
     *
     * A style OpenAPI does not define for a query is read as no style (null), and an `explode` that is not a boolean as
     * the style's own default: nothing is guessed from either, and the fault says so. The contract carries the values
     * as written for exactly this reason.
     *
     * @return array{0: string|null, 1: bool}
     */
    private static function querySerialization(RuleWalk $walk, QueryParameter $parameter): array
    {
        $style = is_string($parameter->style) && in_array($parameter->style, self::QUERY_STYLES, true)
            ? $parameter->style
            : null;

        if ($style === null) {
            $walk->fault(sprintf(
                '`%s` writes a `style` of `%s`, which is not a style OpenAPI defines for a `query` parameter: that is '
                    .'a fault in the document, and nothing is read from it.',
                $parameter->name,
                self::writtenValue($parameter->style),
            ));
        }

        if (is_bool($parameter->explode)) {
            return [$style, $parameter->explode];
        }

        $walk->fault(sprintf(
            '`%s` writes an `explode` of `%s`, which is not a boolean: that is a fault in the document, and nothing is '
                .'read from it.',
            $parameter->name,
            self::writtenValue($parameter->explode),
        ));

        return [$style, $style === 'form' || $style === null];
    }

    /**
     * A value as the document wrote it, for a sentence about what is wrong with it.
     */
    private static function writtenValue(mixed $value): string
    {
        return is_string($value) ? $value : (json_encode($value) ?: get_debug_type($value));
    }

    /**
     * Why an array query parameter is checked at its presence only, by how it is sent.
     */
    private static function unreadArray(string $name, ?string $style, bool $explode): string
    {
        return match (true) {
            $style === null => 'Its `style` is not one OpenAPI defines for a `query` parameter, so how it is sent is not known',
            $style === 'deepObject' => 'Its `style` is `deepObject`, which OpenAPI defines for an object and leaves undefined for an array',
            $style === 'form' && $explode => sprintf(
                'Its `form` style with `explode: true` sends it as `?%1$s=a&%1$s=b`, of which PHP keeps only the last value',
                $name,
            ),
            $style === 'form' => sprintf(
                'Its `form` style with `explode: false` sends it as one string (`?%s=a,b`), which the request does not split',
                $name,
            ),
            default => sprintf('Its `%s` style sends it as one string, which the request does not split', $style),
        };
    }

    /**
     * What an object query parameter gets, by how it is sent: the sentence after the opening of its finding.
     *
     * @param  list<string>  $sharedWith  the names another `query` parameter nests under it, for a `deepObject` that
     *                                    would be judged by a key they share
     */
    private static function unreadObject(string $name, ?string $style, bool $explode, array $sharedWith = []): string
    {
        return match (true) {
            $style === null => 'Its `style` is not one OpenAPI defines for a `query` parameter, so how it is sent is not '
                .'known and nothing about it is enforced, not even its presence.',
            $style === 'form' && ! $explode => sprintf(
                'Its `form` style with `explode: false` sends it as one string (`?%s=R,100,G,200`), which the request '
                    .'does not split, so only its presence is enforced.',
                $name,
            ),
            $style === 'form' => 'Its `form` style with `explode: true` sends one key per property and never its own name, '
                .'so nothing about it is enforced, not even its presence.',
            $style === 'deepObject' => sprintf(
                'Its `deepObject` style sends one key per property under its own name (`?%1$s[a]=1`), but %2$s PHP '
                    .'delivers under the same key, so a rule for `%1$s` would judge %3$s as well. Nothing about it is '
                    .'enforced, not even its presence.',
                $name,
                self::enumerate(array_map(static fn (string $other): string => '`'.$other.'` is a `query` parameter', $sharedWith)),
                count($sharedWith) === 1 ? 'its value' : 'their values',
            ),
            default => sprintf(
                'Its `%s` style is defined for an array in OpenAPI 3.0, and how it sends an object is not read, so '
                    .'nothing about it is enforced, not even its presence.',
                $style,
            ),
        };
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
