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
 * the second is recoverable.
 *
 * **What this pass translates**, with everything else reported: the four scalar
 * types, nullability, and presence. The keywords that constrain a value —
 * lengths, bounds, patterns, formats, enumerations — and the two composite
 * shapes are named in the findings and belong to the passes after this one.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class RuleSetBuilder
{
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
        'multipart/form-data',
        'application/x-www-form-urlencoded',
    ];

    /**
     * Keywords this pass does not translate, by the property that carries them.
     *
     * The list is closed and checked per field, which is what makes a finding a
     * measurement rather than a reminder: a keyword added to
     * {@see Schema} and to neither list is a keyword nothing reports, and that
     * is the silence this whole mechanism exists against.
     *
     * `readOnly`, `writeOnly` and `examples` are absent on purpose: the support
     * matrix ignores them as rules rather than deferring them, so there is
     * nothing coming for a finding to promise.
     */
    private const UNTRANSLATED_KEYWORDS = [
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
     *                                   or two media types over two schemas
     */
    public static function for(Operation $operation): RuleSet
    {
        $rules = [];
        $findings = [];

        $body = self::bodySchema($operation, $findings);

        if ($body !== null) {
            self::bodyRules($operation, $body, $rules, $findings);
        }

        foreach ($operation->queryParameters as $parameter) {
            $unescapable = self::unescapableKey($parameter->name);

            if ($unescapable !== null) {
                $findings[] = $unescapable;

                continue;
            }

            $key = self::ruleKey($parameter->name);

            if (isset($rules[$key])) {
                throw ConflictingInputException::fieldDeclaredTwice($operation->label(), $parameter->name);
            }

            $rules[$key] = [
                $parameter->required ? 'required' : 'sometimes',
                ...self::valueRules($parameter->schema),
            ];

            $findings = [...$findings, ...self::untranslated('`'.$parameter->name.'`', $parameter->schema)];
        }

        return new RuleSet($rules, $findings);
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
            );
        }

        return reset($read);
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
     * Every rule the body's own schema produces.
     *
     * @param  array<string, list<string>>  $rules
     * @param  list<string>  $findings
     */
    private static function bodyRules(Operation $operation, Schema $schema, array &$rules, array &$findings): void
    {
        $body = $operation->requestBody;
        assert($body instanceof RequestBody);

        foreach (self::rootFindings($operation, $schema, $body) as $finding) {
            $findings[] = $finding;
        }

        // The root goes through the same sweep its properties do. Without it a
        // keyword written on the body's own schema — `dependentRequired` and
        // `enum` are the two a real contract writes there — is read into the
        // contract and enforced by nothing, with no finding saying so. Worse
        // than a missing line: an empty findings list makes the generated file
        // claim that nothing was left unenforced.
        //
        // Four keywords are skipped because something else already speaks to
        // them. `properties` is walked below and `required` is read into each
        // property's presence — except for a name it declares that
        // `properties` does not, which the loop below cannot see and which
        // gets a rule of its own. `allOf` and `additionalProperties` have a
        // root finding that says more than the generic one would.
        $findings = [
            ...$findings,
            ...self::untranslated(
                'The body itself',
                $schema,
                ['properties', 'required', 'allOf', 'additionalProperties'],
                withType: false,
            ),
        ];

        $required = self::effectiveRequired($operation, $schema, $body);

        foreach ($schema->properties as $name => $property) {
            $unescapable = self::unescapableKey((string) $name);

            if ($unescapable !== null) {
                $findings[] = $unescapable;

                continue;
            }

            $rules[self::ruleKey((string) $name)] = [
                self::presence((string) $name, $property, $required),
                ...self::valueRules($property),
            ];

            $findings = [...$findings, ...self::untranslated('`'.$name.'`', $property)];
        }

        // A name in `required` that `properties` does not declare is legal
        // OpenAPI and the ordinary way to say "this key must be present, any
        // value". The loop above cannot see it, so it would otherwise be read
        // into the contract and enforced by nothing — and with no other
        // finding the generated file would claim that nothing was left
        // unenforced. Presence is the whole of what the contract states about
        // it, so presence is the whole rule — and `present` rather than
        // `required`, because a key with no schema accepts any value, and
        // Laravel's `required` refuses `null`, `""` and `[]`. A wildcard name
        // is skipped and reported, on the same rule as a declared one.
        $undeclared = array_values(array_diff($required, array_keys($schema->properties)));

        foreach ($undeclared as $position => $name) {
            $unescapable = self::unescapableKey($name);

            if ($unescapable !== null) {
                $findings[] = $unescapable;
                unset($undeclared[$position]);

                continue;
            }

            $rules[self::ruleKey($name)] = ['present'];
        }

        $undeclared = array_values($undeclared);

        if ($undeclared !== []) {
            $findings[] = sprintf(
                'The body requires %s without declaring %s under `properties`, so the rule set asks '
                    .'for the key and says nothing about the value.',
                self::list($undeclared),
                count($undeclared) === 1 ? 'it' : 'them',
            );
        }
    }

    /**
     * The required list as this rule set reads it, which is not always as the
     * document wrote it.
     *
     * Two reasons empty it, and they are different statements.
     * A `PATCH` is a partial update, so the schema's list describes a
     * representation the client is not sending all of. An optional body may be
     * absent entirely, and a plain `required` would then refuse a request the
     * contract allows — which is the one direction worse than a missing rule.
     * `required_with` is what says "all or none", and it belongs to the pass
     * that maps the rest of the table.
     *
     * @return list<string>
     */
    private static function effectiveRequired(Operation $operation, Schema $schema, RequestBody $body): array
    {
        if ($operation->method === HttpMethod::Patch || ! $body->required) {
            return [];
        }

        return $schema->required;
    }

    /**
     * `required`, `present` or `sometimes`.
     *
     * `present` rather than `required` for a property that may be null, because
     * Laravel's `required` refuses `null` and a schema requiring a nullable
     * property is asking for the key, not for a value.
     *
     * @param  list<string>  $required
     */
    private static function presence(string $name, Schema $property, array $required): string
    {
        if (! in_array($name, $required, true)) {
            return 'sometimes';
        }

        return $property->isNullable() ? 'present' : 'required';
    }

    /**
     * What the value itself has to be, as far as this pass reads it.
     *
     * @return list<string>
     */
    private static function valueRules(Schema $schema): array
    {
        $rules = [];

        if ($schema->isNullable()) {
            $rules[] = 'nullable';
        }

        $type = match ($schema->soleType()) {
            SchemaType::String => 'string',
            SchemaType::Integer => 'integer',
            SchemaType::Number => 'numeric',
            SchemaType::Boolean => 'boolean',
            default => null,
        };

        if ($type !== null) {
            $rules[] = $type;
        }

        return $rules;
    }

    /**
     * What one field states and this pass leaves unenforced.
     *
     * @param  string  $name  the subject of the sentence, already quoted the way
     *                        it should read: a field is written in backticks
     *                        and the body itself is not
     * @param  list<string>  $skip  keywords another finding already speaks to,
     *                              which is only ever the root's case: naming
     *                              one twice reads as two problems
     * @param  bool  $withType  whether a missing type rule is worth a line
     *                          here. The root's own shape has a finding that
     *                          says more than "states no type" would
     * @return list<string>
     */
    private static function untranslated(
        string $name,
        Schema $schema,
        array $skip = [],
        bool $withType = true,
    ): array {
        $findings = [];
        $keywords = [];

        foreach (self::UNTRANSLATED_KEYWORDS as $keyword) {
            if (! in_array($keyword, $skip, true) && self::states($schema, $keyword)) {
                $keywords[] = $keyword;
            }
        }

        if ($keywords !== []) {
            $findings[] = sprintf(
                '%s states %s, which nothing in this rule set enforces yet.',
                $name,
                self::list($keywords),
            );
        }

        $unmapped = $withType ? self::unmappedType($schema) : null;

        if ($unmapped !== null) {
            $findings[] = sprintf('%s %s, so no type rule is emitted for it.', $name, $unmapped);
        }

        return $findings;
    }

    /**
     * Why a field got no type rule, or null when it got one.
     *
     * Three different silences, told apart because the fix differs: a shape
     * this pass does not walk into, a union no single rule expresses, and a
     * document that stated no type at all.
     */
    private static function unmappedType(Schema $schema): ?string
    {
        $sole = $schema->soleType();

        if ($sole !== null) {
            return match ($sole) {
                SchemaType::Array => 'is an array, whose element rules are not emitted yet',
                SchemaType::Object => 'is an object, whose properties are not emitted yet',
                default => null,
            };
        }

        $stated = array_values(array_filter(
            $schema->types,
            static fn (SchemaType $type): bool => $type !== SchemaType::Null,
        ));

        if (count($stated) > 1) {
            return 'declares more than one type, which no single Laravel rule expresses';
        }

        return $schema->recursesTo === null ? 'states no type' : null;
    }

    /**
     * What the body's own root says that this pass does not act on.
     *
     * @return list<string>
     */
    private static function rootFindings(Operation $operation, Schema $schema, RequestBody $body): array
    {
        $findings = [];

        if ($schema->soleType() !== SchemaType::Object) {
            $findings[] = 'The body\'s own schema is not an object, so no rule is derived from it. '
                .'A rule key is a field name, and a body that is a bare value has none.';
        }

        if ($schema->allOf !== []) {
            $findings[] = 'The body\'s own schema writes `allOf`, whose branches are not merged into '
                .'the rule set yet.';
        }

        if ($schema->additionalProperties === false) {
            $findings[] = 'The body forbids properties it did not declare, which is not enforced '
                .'yet: an undeclared field is absent from `validated()` rather than refused.';
        }

        if (! $body->required && $schema->required !== []) {
            $findings[] = sprintf(
                'The body may be absent entirely while its schema requires %s. Nothing enforces '
                    .'"all or none" yet, so every field is `sometimes`: half a body is accepted where '
                    .'the contract refuses it.',
                self::list($schema->required),
            );
        }

        if ($operation->method === HttpMethod::Patch && $schema->required !== []) {
            $findings[] = sprintf(
                'A `PATCH` is a partial update, so the schema\'s required list (%s) is read as '
                    .'empty here. Every property is `sometimes`.',
                self::list($schema->required),
            );
        }

        if ($operation->method === HttpMethod::Put) {
            $optional = array_values(array_diff(array_keys($schema->properties), $schema->required));

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
     * empty array — and `false` joins them for the two flags whose absence is
     * spelled that way, `uniqueItems` and `isFilePart`.
     *
     * **`additionalProperties` is the exception, and it is the whole reason
     * this is a method.** `false` there is the statement rather than the
     * silence, and it is the only value worth a finding: it forbids undeclared
     * fields, which nothing enforces yet. `true` asks for nothing, so there is
     * no rule for it to be missing, and `null` is a document that said nothing
     * — {@see Schema} keeps all three apart on purpose.
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
     */
    private static function unescapableKey(string $field): ?string
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
