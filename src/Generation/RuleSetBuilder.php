<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;

/**
 * One operation's request body and `query` parameters, as one Laravel rule set.
 *
 * **Every constraint maps or reports**, and the second half is what makes this
 * safe to ship before the first is finished. A keyword this class does not yet
 * translate leaves a finding naming it and the field it was written on, so a
 * rule set that is incomplete says so in the file a reader opens. What is never
 * emitted is a looser rule that almost matches: accepting a payload the
 * contract refuses is a wrong answer where a report is a missing one, and only
 * the second is recoverable. Nor is a rule emitted that
 * is stricter than the contract, refusing what it accepts, unless matching it
 * exactly would be far too complex in Laravel, and then a finding says so.
 *
 * **What it translates is the whole table** in request-validation.md: types,
 * nullability and presence, the bounds on strings, numbers and arrays, the
 * `format` values Laravel has a rule of the same meaning for, enumerations,
 * patterns inside the ECMA-262 / PCRE boundary, nested objects and array
 * elements under dotted keys, `allOf` merged, `dependentRequired`, an
 * optional body's "all or none", and the file parts of a multipart body. What
 * it does not — an `encoding` beside a file part, a pattern across the
 * boundary, a `format` Laravel would read differently, a recursive schema's
 * infinite depth — leaves a finding.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class RuleSetBuilder
{
    /**
     * RFC 3339's `date-time` grammar, as a pattern: a fraction of any length,
     * `T` and `Z` in either case, an offset with hours below 24.
     *
     * **A pattern rather than `date_format`**, because `date_format` passes
     * only on an exact round trip through one of PHP's format strings, and
     * PHP has no variable-width fraction: six patterns covered 0, 3 and 6
     * digits and refused the 7 .NET writes and the 9 Go does. ASCII classes
     * and no `u`, since every character here is ASCII, and `D` so a trailing
     * newline is refused. Laravel's `date` beside it is what refuses a
     * February 30th, which a grammar cannot see. Measured on the validator:
     * fractions of 1, 3, 5, 7 and 9 digits pass, as do lower case and
     * `-00:00`; `02-30`, `24:00:00`, `+24:00`, a missing offset and a
     * trailing newline are refused.
     *
     * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
     */
    private const DATE_TIME_PATTERN = 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])[Tt]'
        .'([01][0-9]|2[0-3]):[0-5][0-9]:([0-5][0-9]|60)(\.[0-9]+)?([Zz]|[+-]([01][0-9]|2[0-3]):[0-5][0-9])$/D';

    /**
     * The `format` values Laravel has a rule of the same meaning for, and the
     * rule. Anything else is reported: `uri` most visibly, since Laravel's
     * `url` turns away the non-hierarchical URIs (`urn:…`) JSON Schema allows.
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
     * The one body media type a part can be a file in, so the one a file part
     * is read as one for.
     */
    private const MULTIPART = 'multipart/form-data';

    /**
     * The media types a generated rule set covers.
     *
     * All three arrive through `all()`, so one rule set covers them and the
     * order here is only the order a tie is broken in. What changes between
     * them is whether a part can be a file, which is `uploads.md`'s subject.
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
     * The list is closed and checked per field against what the walk actually
     * acted on, which is what makes a finding a measurement rather than a
     * reminder: a keyword stated on a field and absent from that field's
     * handled set is named, whatever the reason it was not translated. A
     * keyword added to {@see Schema} and not here is a keyword nothing
     * reports, and that is the silence this mechanism exists against.
     *
     * `writeOnly` and `examples` are absent on purpose: the support matrix
     * ignores them as rules rather than deferring them, so there is nothing
     * coming for a finding to promise. `readOnly` is read, and absent for a
     * different reason: it changes the presence of a required property
     * rather than adding a rule, and says so in its own finding.
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
     * @throws ConflictingInputException the body and a query parameter naming one field,
     *                                   two media types over two schemas, or an
     *                                   `allOf` whose branches disagree
     */
    public static function for(Operation $operation): RuleSet
    {
        $walk = new RuleWalk($operation->label());

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

                continue;
            }

            $key = self::ruleKey($parameter->name);

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

        return new RuleSet($walk->rules, $walk->findings, $closedKeys, $body, $multipart, $queryKeys);
    }

    /**
     * The one schema the rule set is built from, or null when the body states
     * none this package reads.
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
     * Whether a file part is written anywhere in a schema, a nested one and an
     * `allOf` branch included. A recursion marker has nothing under it, so it
     * ends the walk.
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
     * Compared by value rather than by identity, because two media types
     * pointing at one `$ref` are not guaranteed to reach here as one object,
     * and two identical inline schemas are not a conflict either: what the
     * document said is the same thing twice.
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

            if ($schema != $first) {
                throw ConflictingInputException::twoMediaTypeSchemas(
                    $operation->label(),
                    (string) $firstMediaType,
                    $mediaType,
                );
            }
        }
    }

    /**
     * Every rule the body's own schema produces, and the top-level keys it
     * closes the payload to, or null when it does not.
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

        foreach (self::rootFindings($operation, $schema, $body) as $finding) {
            $walk->findings[] = $finding;
        }

        if (! self::isObjectBody($schema)) {
            return null;
        }

        // The root goes through the same sweep a field does, minus what the
        // object walk below acts on. Without it a keyword written on the
        // body's own schema — an `enum` is the one a real contract writes there
        // — is read and enforced by nothing, and an empty findings list makes
        // the generated file claim the opposite.
        $walk->findings = [
            ...$walk->findings,
            ...self::unhandled(
                'The body itself',
                $schema,
                ['type', 'properties', 'required', 'dependentRequired', 'additionalProperties', 'allOf'],
            ),
        ];

        self::objectChildren(
            $walk,
            '',
            $schema,
            self::rootPresence($operation, $schema, $body, $walk),
        );

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

            if ($delivered !== null) {
                $closed[] = $delivered;
            }
        }

        return array_values(array_unique($closed));
    }

    /**
     * Whether the body's schema is read as an object: it says so, or it names
     * `properties` and says no type.
     *
     * JSON Schema applies `properties` to objects only, and a body that names
     * some is an object whether or not it spells `type: object`, which
     * generators that omit the type are common enough to matter. Only the
     * body's root: a nested field with the same shape would be refused a
     * string the contract accepts, so it keeps no type rule.
     */
    public static function isObjectBody(Schema $schema): bool
    {
        return $schema->soleType() === SchemaType::Object
            || ($schema->types === [] && $schema->properties !== []);
    }

    /**
     * How each of the root's properties is asked for, which is where the
     * method and the body's own `required` flag meet.
     *
     * - A `PATCH` is a partial update, so the schema's list describes a
     *   representation the client is not sending all of: every property is
     *   `sometimes`.
     * - An optional body may be absent entirely, and a plain presence rule
     *   would then refuse a request the contract allows. What the contract
     *   means is "a body or none", and `*_with` naming every other key the
     *   body may carry says exactly that: nothing sent, nothing required; any
     *   key sent — a required one or an optional one — and the required ones
     *   follow. Naming only the required siblings would accept a body made of
     *   optional keys alone, which the contract refuses.
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
                    static fn (string $other): bool => $other !== $name
                        && self::unescapableKey($other) === null
                        && ! ($walk->formBody && self::phpRewrites($other)),
                ));

                return $siblings === []
                    ? 'sometimes'
                    : self::withRule(array_map(self::ruleKey(...), $siblings));
            };
        }

        return static fn (string $name, Schema $property): string => in_array($name, $schema->required, true)
            ? self::requiredRule($property)
            : 'sometimes';
    }

    /**
     * Every property of one object, keyed under its parent, with the presence
     * a caller decides for the required ones.
     *
     * @param  callable(string, Schema): string  $presence  how a named property
     *                                                      is asked for, before
     *                                                      `dependentRequired`
     *
     * @throws ConflictingInputException
     */
    private static function objectChildren(
        RuleWalk $walk,
        string $prefix,
        Schema $schema,
        callable $presence,
    ): void {
        $dependents = self::dependents($schema, $walk, $prefix);

        foreach ($schema->properties as $name => $property) {
            $name = (string) $name;
            $unescapable = self::unescapableKey($name) ?? self::unreachableBodyName($name, $schema, $walk, $prefix);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;

                continue;
            }

            $asked = $presence($name, $property);

            if ($asked === 'sometimes' && isset($dependents[$name])) {
                $asked = self::withRule($dependents[$name]);
            }

            // A request never has to carry what only a response does: OpenAPI
            // reads `required` on a `readOnly` property as a promise about
            // responses. Its other rules still judge it when it is sent.
            if ($asked !== 'sometimes' && self::isReadOnly($property, $walk, '`'.$prefix.$name.'`')) {
                $walk->findings[] = self::readOnlyFinding('`'.$prefix.$name.'`');
                $asked = 'sometimes';
            }

            self::field($walk, $prefix.self::ruleKey($name), '`'.$prefix.$name.'`', $property, $asked);
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

            $unescapable = self::unescapableKey($name) ?? self::unreachableBodyName($name, $schema, $walk, $prefix);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;

                continue;
            }

            $asked = $presence($name, new Schema);

            if ($asked === 'sometimes') {
                if (! isset($dependents[$name])) {
                    continue;
                }

                $asked = self::withRule($dependents[$name]);
            }

            $walk->rules[$prefix.self::ruleKey($name)] = [$asked];
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
     * `dependentRequired`, read the other way round: for each property it
     * names, the keys whose presence makes it required.
     *
     * @return array<string, list<string>> a dependent name => trigger rule keys
     */
    private static function dependents(Schema $schema, RuleWalk $walk, string $prefix): array
    {
        $dependents = [];

        foreach ($schema->dependentRequired as $trigger => $names) {
            $unescapable = self::unescapableKey((string) $trigger)
                ?? self::unreachableBodyName((string) $trigger, $schema, $walk, $prefix);

            if ($unescapable !== null) {
                $walk->findings[] = $unescapable;

                continue;
            }

            foreach ($names as $name) {
                $dependents[$name][] = $prefix.self::ruleKey((string) $trigger);
            }
        }

        return $dependents;
    }

    /**
     * One field, every rule it produces, and a finding for everything it
     * states that none of them carries.
     *
     * @param  string  $presence  how the key itself is asked for, or the empty
     *                            string for an array element, which exists by
     *                            being in the array
     *
     * @throws ConflictingInputException
     */
    private static function field(
        RuleWalk $walk,
        string $key,
        string $label,
        Schema $written,
        string $presence,
    ): void {
        $schema = AllOfMerger::merge($written, $walk->identity, $label);
        $rules = $presence === '' ? [] : [$presence];

        if ($schema->recursesTo !== null) {
            // A self-referential schema is a supported contract and an
            // infinite tree, and no rule set reaches an infinite depth.
            $walk->rules[$key] = $rules;
            $walk->findings[] = sprintf(
                '%s points back at `%s`, so nothing below it is validated: no rule set reaches an '
                    .'infinite depth.',
                $label,
                $schema->recursesTo,
            );

            return;
        }

        $handled = ['allOf', 'type'];
        // A value has to satisfy the type list and the enumeration at once,
        // so `null` is allowed only when both allow it: `type: [string, null]`
        // with `enum: [a]` refuses it, and so does `type: string` with
        // `enum: [a, null]`. A field stating neither constrains nothing.
        if (self::allowsNull($schema) && ($schema->types !== [] || $schema->enum !== null)) {
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

            self::fileRules($schema, $label, $rules, $handled, $walk);
        } else {
            switch ($type) {
                case SchemaType::String:
                    $rules[] = 'string';
                    self::stringRules($schema, $label, $rules, $handled, $walk);
                    break;
                case SchemaType::Integer:
                    $rules[] = 'integer';
                    self::numberRules($schema, $rules, $handled);
                    break;
                case SchemaType::Number:
                    $rules[] = 'numeric';
                    self::numberRules($schema, $rules, $handled);
                    break;
                case SchemaType::Boolean:
                    if ($walk->textSource !== null) {
                        $rules[] = self::textBoolean($schema, $label, $walk, $handled);
                    } else {
                        $rules[] = 'boolean';

                        if ($walk->mixedBody) {
                            $walk->findings[] = sprintf(
                                '%s is a boolean in a body that is JSON and also a form. One rule set cannot accept '
                                    .'the text a form carries and still refuse `null`, so it keeps `boolean`, which '
                                    .'refuses the string `"true"` a form writes.',
                                $label,
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
                self::field($walk, $key.'.*', $label.'[]', $schema->items, '');
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

        $walk->findings = [...$walk->findings, ...self::unhandled($label, $schema, $handled)];
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
            '%s is `readOnly` and required. OpenAPI applies `required` on a `readOnly` property to '
                .'responses only, so a request does not have to carry it and no presence rule is emitted. '
                .'Its other rules judge it when it is sent.',
            $label,
        );
    }

    /**
     * The rule for a boolean whose value arrives as text.
     *
     * **Laravel's `boolean` refuses `"true"` and `"false"`**, and those are how
     * OpenAPI's default `form` style writes a boolean in a query string
     * (`?notify=true`), and how a form or multipart body carries one. Refusing
     * them answers 422 to every generated client for a contract it follows, so
     * `true`, `false`, `1` and `0` are accepted, as text. `false` is not in the
     * list as a PHP value: `Rule::in` compares `(string) false`, which is the
     * empty string, and that would accept the `null` an empty value becomes.
     * `validated()` holds the string as sent, which the finding says.
     *
     * An enumeration of booleans is read as every spelling of its values, since
     * `Rule::in([true])` would accept `1` and refuse `true`.
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
                    .'read it with `filter_var($request->validated(\'%3$s\'), FILTER_VALIDATE_BOOL)`.',
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
     * Whether a schema is read as a file: the schema says so, the body is
     * `multipart/form-data` alone, and the schema does not also say it is some
     * other type, since the other type is what its rules would answer to.
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
     * What a file part becomes: `file`, the types its `contentMediaType` names,
     * and a size in kilobytes.
     *
     * **`file` instead of `string`**, because `string` refuses an upload, which
     * is worse than leaving the rule out.
     *
     * **`mimetypes:` and only from `contentMediaType`.** `encoding.<part>.contentType`
     * wins over it in `uploads.md`, but `RequestBody` holds one schema per media
     * type and nothing else, so `encoding` never reaches the contract. Every
     * file part says so, because this walk cannot tell a part with an
     * `encoding` from one without.
     *
     * TODO (#90): read `encoding.<part>.contentType` once it is in the contract,
     * and drop the finding below.
     *
     * **A text type is not one to match either, and a finding says so.** See
     * {@see self::isSniffedAsAnotherType()}: `mimetypes:text/csv` refuses every
     * real CSV.
     *
     * **`application/octet-stream` and the any-type wildcard are not types to
     * match.** They are how a contract says "any bytes", and `mimetypes`
     * inspects the file's real type, so it would turn away every upload that
     * is not literally an octet stream: a rule stricter than the contract, in
     * the direction of refusing valid payloads.
     *
     * **`maxLength` is bytes and `max` is kilobytes, and the ceiling is exact.**
     * Laravel's `max` takes a decimal, so 2500 bytes is `max:2.44140625`.
     *
     * @param  list<string|InRule>  $rules
     * @param  list<string>  $handled
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    private static function fileRules(Schema $schema, string $label, array &$rules, array &$handled, RuleWalk $walk): void
    {
        $rules[] = 'file';
        array_push($handled, 'isFilePart', 'contentMediaType', 'maxLength');

        // Parameters (`; charset=...`) are not part of the type Laravel reads.
        $mediaType = $schema->contentMediaType === null
            ? ''
            : strtolower(trim(explode(';', $schema->contentMediaType)[0]));

        if (self::isSniffedAsAnotherType($mediaType)) {
            $walk->findings[] = sprintf(
                '%s declares `contentMediaType: %s`, and no `mimetypes` rule is emitted for it: Laravel reads '
                    .'the type libmagic sniffs from the content, which is never that one for a text file, so '
                    .'the rule would refuse every real upload.',
                $label,
                $mediaType,
            );
        } elseif ($mediaType !== '' && ! in_array($mediaType, ['application/octet-stream', '*/*'], true)) {
            $rules[] = 'mimetypes:'.$mediaType;
            // The one stricter-than-the-contract case here, and the exception the principle
            // allows: Laravel's `mimetypes` refuses a file whose name ends in a PHP extension
            // whatever its type, and nothing short of overriding that security default
            // from a generated class matches the contract exactly.
            if (! $walk->phpUploadNamed) {
                $walk->phpUploadNamed = true;
                $walk->findings[] = 'A `mimetypes` rule in this request is stricter than the contract in one case: '
                    .'Laravel refuses a file whose name ends in a PHP extension (`php`, `phtml`, `phar` and the '
                    .'like) whatever its type, a security default no rule turns off, and matching the contract '
                    .'there would mean overriding it from a generated class.';
            }
        }

        // A decimal, because Laravel's `max` compares with a `BigNumber` and takes
        // one: `max:2.44140625` is 2500 bytes exactly. A byte count over 1024 always
        // has a finite decimal expansion, so the ceiling is exact to the byte.
        if ($schema->maxLength !== null) {
            $rules[] = 'max:'.self::number($schema->maxLength / 1024);
        }

        $walk->findings[] = sprintf(
            '%s is a file part. Its types are read from `contentMediaType` alone: an '
                .'`encoding.<part>.contentType` beside the body is not read, so a restriction written '
                .'there is not enforced.',
            $label,
        );
    }

    /**
     * Whether a media type is one libmagic never reports for a file of that
     * type, so that `mimetypes` would refuse every real one.
     *
     * Measured on libmagic 5.41, with real files (a faked upload reports the
     * type it is given and hides this): a CSV, a Markdown file and a YAML file
     * sniff as `text/plain`, an XML file as `text/xml` where the contract says
     * `application/xml`, and a JSON file as `application/json` on this version
     * and `text/plain` on older ones. `text/*` is the class, and the textual
     * `application/*` types are the same class: libmagic reads their content,
     * and a content with a name of its own (`application/ld+json`) still comes
     * back as the generic one. `image/svg+xml`, `application/pdf` and the
     * binary types sniff as themselves and keep their rule.
     */
    private static function isSniffedAsAnotherType(string $mediaType): bool
    {
        return str_starts_with($mediaType, 'text/')
            || in_array($mediaType, ['application/json', 'application/xml', 'application/yaml', 'application/x-yaml'], true)
            || (
                $mediaType !== 'image/svg+xml'
                && (str_ends_with($mediaType, '+json') || str_ends_with($mediaType, '+xml'))
            );
    }

    /**
     * What a string's own keywords become.
     *
     * @param  list<string|InRule>  $rules
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
            } else {
                $walk->findings[] = sprintf(
                    '%s declares `format: %s`, which no Laravel rule means the same as, so it is not enforced.',
                    $label,
                    $schema->format,
                );
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
            }

            $handled[] = 'pattern';
        }
    }

    /**
     * What an integer's or a number's own keywords become.
     *
     * Beside `integer` or `numeric`, `min` and `max` compare the value, and
     * `gt` and `lt` take a number as readily as a field name.
     *
     * @param  list<string|InRule>  $rules
     * @param  list<string>  $handled
     */
    private static function numberRules(Schema $schema, array &$rules, array &$handled): void
    {
        self::bound($schema->minimum, 'min', $rules);
        self::bound($schema->maximum, 'max', $rules);
        self::bound($schema->exclusiveMinimum, 'gt', $rules);
        self::bound($schema->exclusiveMaximum, 'lt', $rules);
        self::bound($schema->multipleOf, 'multiple_of', $rules);
        array_push($handled, 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf');
    }

    /**
     * @param  list<string|InRule>  $rules
     */
    private static function bound(int|float|null $value, string $rule, array &$rules): void
    {
        if ($value !== null) {
            $rules[] = $rule.':'.self::number($value);
        }
    }

    /**
     * A number as a rule parameter: `1` rather than `1.0`, and a fraction as
     * PHP prints one.
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
     * Two shapes break it, both measured on Laravel's validator. Under a
     * wildcard, `distinct` turns every `*` into a pattern and compares across
     * every element of the outer list, so `[{tags: [a]}, {tags: [a]}]` is
     * refused although each list is unique. And on elements that are objects
     * or arrays it flattens them with dots and never compares the elements
     * themselves, so `[{a: 1}, {a: 1}]` passes. It also lets two nulls
     * through. A null leaves the keyword to the sweep, which reports it.
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
     * Whether a key name cannot appear in a rule's list of key names: a comma
     * splits the list, and a dot is read as nesting before the rule sees the
     * data.
     *
     * @internal Public so the input DTO asks the question the rules did, and the
     *           two cannot drift apart.
     */
    public static function breaksKeyList(string $name): bool
    {
        return str_contains($name, ',') || str_contains($name, '.');
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
     * Whether the field states no type and every allowed value is a string,
     * which is the contract saying the field is one.
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
     * The rule a key the contract requires gets, which is not always Laravel's
     * `required`.
     *
     * **JSON Schema's `required` asks for the key; Laravel's asks for a
     * non-empty value.** It refuses `null`, `""`, `[]` and `{}` whatever else
     * the field declares, so a required string the contract lets be empty, a
     * required array it lets hold nothing, or any field that may be null would
     * answer 422 to a payload the contract accepts. `present` asks for the key
     * and nothing else, and the type rule beside it judges any value that is
     * not blank.
     *
     * `required` is kept for the three types whose own rule refuses every
     * empty value anyway — an integer, a number, a boolean cannot be `""` or
     * `[]` — because there the two say the same thing and `required` is what
     * a reader expects to see.
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
     * What one field states that none of its rules carries.
     *
     * @param  list<string>  $handled  the keywords the walk acted on for this
     *                                 field, whether by a rule or by a finding
     *                                 of its own
     * @return list<string>
     */
    private static function unhandled(string $label, Schema $schema, array $handled): array
    {
        $keywords = array_values(array_filter(
            self::RULE_KEYWORDS,
            static fn (string $keyword): bool => ! in_array($keyword, $handled, true) && self::states($schema, $keyword),
        ));

        return $keywords === []
            ? []
            : [sprintf('%s states %s, which nothing in this rule set enforces.', $label, self::list($keywords))];
    }

    /**
     * Why a field got no type rule, or null when the reason is reported
     * elsewhere.
     *
     * Two different silences, told apart because the fix differs: a union no
     * single rule expresses, and a document that stated no type at all.
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
     * What the body's own root says that a rule set cannot act on, or that a
     * reader would not guess from the rules.
     *
     * @return list<string>
     */
    private static function rootFindings(Operation $operation, Schema $schema, RequestBody $body): array
    {
        $findings = [];

        if (! self::isObjectBody($schema)) {
            $findings[] = 'The body\'s own schema is not an object, so no rule is derived from it. '
                .'A rule key is a field name, and a body that is a bare value has none.';

            return $findings;
        }

        // Why: the one place a request is stricter than the contract by design. Matching
        // it would take a rule that holds for an object or for anything else, at a root
        // that has no field for a rule to sit on.
        if ($schema->types === []) {
            $findings[] = 'The body names `properties` and no `type`, so it is read as an object, and a body '
                .'that is not one (the JSON string `"Ada"` is valid against this schema) is refused. That is '
                .'stricter than the contract, since a rule set cannot accept an object or anything else at '
                .'the root. Add `type: object` to the schema to make the reading the contract\'s own.';
        }

        if ($operation->method === HttpMethod::Patch && $schema->required !== []) {
            $findings[] = sprintf(
                'A `PATCH` is a partial update, so the schema\'s required list (%s) is read as '
                    .'empty here. Every property is `sometimes`.',
                self::list($schema->required),
            );
        } elseif (
            ! $body->required
            && $schema->required !== []
            && count(array_unique([...array_map('strval', array_keys($schema->properties)), ...$schema->required])) === 1
        ) {
            $findings[] = sprintf(
                'The body may be absent entirely while its schema requires %s and declares nothing '
                    .'else. There is no other key to name, and Laravel cannot tell an absent body from an '
                    .'empty one, so it is `sometimes`: a body sent as `{}` is accepted.',
                self::list($schema->required),
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
     * Two shapes of "nothing was written" read the same way — null and the
     * empty array — and `false` joins them for the flags whose absence is
     * spelled that way, `uniqueItems` and `isFilePart`.
     *
     * **`additionalProperties` is the exception.** `false` there is the
     * statement rather than the silence, and the only value a rule can carry:
     * `true` asks for nothing, and `null` is a document that said nothing.
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
     * A rule key, with the structural character Laravel lets a key escape
     * escaped.
     *
     * `user.name` as a literal property name would otherwise generate rules
     * for a `name` key inside a `user` object the contract never declared,
     * which is a rule set that is wrong rather than incomplete.
     *
     * **`.` is the one Laravel offers an escape for, and not the only one it
     * reads.** `*` is the validator's wildcard in a rule key and has no
     * escape, so a field named with one is reported by
     * {@see self::unescapableKey()} rather than quietly mis-keyed.
     */
    private static function ruleKey(string $field): string
    {
        return str_replace('.', '\\.', $field);
    }

    /**
     * A finding for a field name Laravel would read as structure and offers no
     * way to escape, or null for the ordinary case.
     *
     * Reported rather than refused, on the rule the whole class follows: the
     * rule set is short by a field rather than wrong about one, and a build
     * that turned away a valid contract over a rare spelling would be the
     * worse answer.
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
     * The name PHP gives a top-level key of a form body, or null when it gives
     * none.
     *
     * Asked of PHP itself: `parse_str()` is the parser a form body goes through,
     * and what it keeps is the answer, where a rewrite of its rules written here
     * would be one more thing to keep in step with it. Measured against a real SAPI
     * (`curl -d` and `curl -F` to `php -S`), `$_POST` agrees with it: `user.name`
     * arrives as `user_name`, `a b` as `a_b` and `page[size]` as `page`, and a
     * name inside brackets (`address[a.b]`) keeps its dot.
     *
     * @internal Public so the input DTO asks the question the rules did.
     */
    public static function deliveredName(string $name): ?string
    {
        parse_str(urlencode($name).'=1', $parsed);

        $key = array_key_first($parsed);

        return $key === null ? null : (string) $key;
    }

    /**
     * Whether PHP rewrites a top-level name of a form body before Laravel sees it.
     *
     * @internal Public for the reason {@see self::deliveredName()} gives.
     */
    public static function phpRewrites(string $name): bool
    {
        return self::deliveredName($name) !== $name;
    }

    /**
     * A finding for a top-level body name PHP rewrites in a form, or null for
     * the ordinary case.
     *
     * The same reasoning as {@see self::unreachableQueryName()}: no rule key finds
     * the name the contract writes, so a rule would refuse every valid request or
     * never judge one. A name nested inside another keeps its dot, and a JSON body
     * keeps every name, so only the root of a body read as a form is affected.
     */
    private static function unreachableBodyName(string $name, Schema $schema, RuleWalk $walk, string $prefix): ?string
    {
        if ($prefix !== '' || ! $walk->formBody || ! self::phpRewrites($name)) {
            return null;
        }

        $delivered = self::deliveredName($name);

        return sprintf(
            '`%s` is a property of a form body whose name PHP rewrites before Laravel sees it (`.` and a '
                .'space become `_`, and `[` opens a nested array), so no rule key can find it. None is '
                .'emitted for it, and neither its type nor its presence is enforced.%s',
            $name,
            $schema->additionalProperties === false && $delivered !== null
                ? sprintf(' The body is closed to its declared names, so it accepts `%s`, the name PHP gives it.', $delivered)
                : '',
        );
    }

    /**
     * A finding for a query name PHP rewrites before Laravel sees the request,
     * or null for the ordinary case.
     *
     * **PHP parses the query string, and it does not keep every name.** A `.`
     * or a space becomes `_` (`user.name` arrives as `user_name`), and `[`
     * opens a nested array (`page[size]` arrives as `page => [size => ...]`).
     * Measured with `parse_str()`, which the request's query bag is built from.
     * No key a rule can name is ever found under the name the contract
     * writes, so a rule for it would never see what was sent: an optional one
     * is never validated, and a required one answers 422 to every valid
     * request. Reported instead, which is what the `*` case does, so the
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
