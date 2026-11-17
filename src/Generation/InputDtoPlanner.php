<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

/**
 * Works out every input DTO of a build: its name, its properties, and which operation reads it.
 *
 * **A DTO is named after the schema it describes, never after the `$ref` that reached it or the file that holds it.**
 * `#/components/schemas/Pet` and `./other.yaml#/components/schemas/Pet` are one `PetInputDto`, because splitting a
 * specification across files does not change the contract and must not rename a class a custom controller imports. Two
 * operations sending one `$ref` share one type for the same reason. Two schemas that are *not* the same and give the
 * same name are refused, naming both positions.
 *
 * **The request side always carries `Input`**, `$ref` or not, so a schema used both ways does not collide with its
 * response DTO (#39).
 *
 * **Two types per body, always.** The full one and the `Partial`, even when they are identical, because a class that
 * exists only under a condition is a class a developer has to check for before importing. Only the body's root has a
 * partial: a nested object inside a `PATCH` stays complete.
 *
 * **Built before it is written.** One planner per build, so that a refusal costs nothing: no file exists yet when two
 * schemas turn out to share a name.
 *
 * @internal Not public API.
 *
 * @see docs/guide/code-generation/request-validation.md — "Two directions, two types"
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
final class InputDtoPlanner extends DtoPlanner
{
    /**
     * The operations that read each DTO, by DTO and in document order, and the request each is read through.
     *
     * @var array<string, list<string>>
     */
    private array $readers = [];

    /**
     * @var array<string, list<string>>
     */
    private array $requests = [];

    /**
     * @var array<string, string>
     */
    private array $readBy = [];

    /**
     * The positions of the map bodies whose values the request leaves unvalidated, found before any class is
     * registered. One class serves every operation that reaches a named schema, so whether its values are typed is
     * decided here for all of them, and the tree is the same in every document order.
     *
     * @var array<string, true>
     */
    private array $unvalidatedBodies = [];

    private bool $multipart = false;

    protected function suffix(): string
    {
        return 'InputDto';
    }

    protected function isFile(Schema $schema): bool
    {
        return RuleSetBuilder::isFile($schema, $this->multipart);
    }

    protected function unrolls(Schema $site, ?Schema $ancestor): bool
    {
        return RuleSetBuilder::unrolls($site, $ancestor);
    }

    protected function mixedNote(): string
    {
        return ' The request\'s findings say the same about its rules.';
    }

    protected function unbuiltRecursionFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, and the request validates nothing below that point, so it is '
                .'`mixed` rather than built into a DTO from input nothing checked.',
            $segment,
            $position,
        );
    }

    protected function propertiesBesideFinding(string $segment, string $ancestor): string
    {
        return sprintf(
            '`%s` is validated by the request, which unrolls it, but it is `mixed` here: it writes properties '
                .'beside the reference, and the class of `%s` does not carry them.',
            $segment,
            $ancestor,
        );
    }

    protected function ancestorNotAnObjectFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, which is not an object with properties, so it is `mixed` rather than a '
                .'class the request did not check the shape of.',
            $segment,
            $position,
        );
    }

    protected function registerNested(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string {
        return $this->register($shortName, false, $written, $merged, $position, $base, $origin, $naming, $named);
    }

    /**
     * @param  list<PlannedRequest>  $requests  in document order
     *
     * @throws UnusableNameException two schemas under one name, a name that is not a class name, or two keys under one
     *                               property name
     * @throws ConflictingInputException an `allOf` whose branches disagree
     */
    public function plan(array $requests): InputDtoPlan
    {
        $this->unvalidatedBodies = [];

        foreach ($requests as $request) {
            if (! RuleSetBuilder::validatesRootMapValues($request->operation)) {
                $root = $this->locate($request);

                if ($root !== null) {
                    $this->unvalidatedBodies[$root['position']] = true;
                }
            }
        }

        foreach ($requests as $request) {
            $this->root($request);
        }

        $dtos = [];

        foreach ($this->registry as $shortName => $entry) {
            $dtos[] = new PlannedInputDto(
                $shortName,
                $entry['partial'],
                $entry['position'],
                $entry['properties'],
                $this->readers[$shortName] ?? [],
                $entry['naming'],
                $entry['findings'],
                $this->requests[$shortName] ?? [],
            );
        }

        return new InputDtoPlan($dtos, $this->readBy);
    }

    /**
     * Where an operation's body is written, and what it merges to, or null when it has no body the rule set reads as an
     * object.
     *
     * One computation for the walk and for the pre-pass of {@see self::plan()}, so the position a map body is recorded
     * at is the position its class is registered under.
     *
     * @return array{written: Schema, merged: Schema, name: string|null, position: string}|null
     *
     * @throws ConflictingInputException
     */
    private function locate(PlannedRequest $request): ?array
    {
        $written = $request->rules->body;

        if ($written === null) {
            return null;
        }

        $operation = $request->operation;
        $body = $operation->requestBody;
        assert($body !== null);

        $this->identity = $operation->label();

        $merged = AllOfMerger::merge($written, $this->identity, 'the body');

        // A body that is not an object has no rule set and no DTO, for the same
        // reason: a property is a field name, and a bare value has none. The
        // rule set's own test, so a body it reads as an object gets a DTO too.
        if (! RuleSetBuilder::isObjectBody($merged)) {
            return null;
        }

        $mediaTypes = array_values(array_intersect($body->mediaTypes(), RuleSetBuilder::READ_MEDIA_TYPES));
        $inline = DocumentPointer::forOperation($operation).'/requestBody/content/'
            .DocumentPointer::escape($mediaTypes[0] ?? '').'/schema';

        // Where the schema is written: its component when it has one, so that an
        // inline object nested in it reports a position inside the component and
        // not inside whichever operation happened to reach it first.
        [$name, $source] = $this->nameOf($written, $merged);

        return ['written' => $written, 'merged' => $merged, 'name' => $name, 'position' => $source ?? $inline];
    }

    /**
     * The two types of one operation's body, and the one its `dto()` reads.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function root(PlannedRequest $request): void
    {
        $root = $this->locate($request);

        if ($root === null) {
            return;
        }

        ['written' => $written, 'merged' => $merged, 'name' => $name, 'position' => $position] = $root;

        $operation = $request->operation;
        $body = $operation->requestBody;
        assert($body !== null);

        $this->multipart = $request->rules->multipart;
        $this->ancestors = RuleSetBuilder::definitions($written, $merged, $this->identity, 'the body');

        if ($name === null) {
            $base = $request->name->stem();
            $origin = sprintf('derived from the request "%s"', $request->name->shortName);
            $naming = sprintf(
                'Class name derived from the operation\'s request, `%s`, because the body is written '
                    .'inline and has no name of its own.',
                $request->name->shortName,
            );
        } else {
            $base = $this->className($name, $position);
            $origin = 'the schema\'s name';
            $naming = self::namedAfter($name, $position);
        }

        $named = $name !== null;
        $unchecked = $request->rules->uncheckedNames;
        $full = $this->register($base.'InputDto', false, $written, $merged, $position, $base, $origin, $naming, $named, $unchecked);
        $partial = $this->register($base.'PartialInputDto', true, $written, $merged, $position, $base, $origin, $naming, $named, $unchecked);

        // The `PATCH` reads the partial type, and so does a body that may be
        // absent entirely: there every property may be missing.
        $reads = $operation->method === HttpMethod::Patch || ! $body->required ? $partial : $full;

        $this->readers[$reads][] = $this->identity;
        $this->requests[$reads][] = $request->name->shortName;
        $this->readBy[$this->identity] = $reads;
    }

    /**
     * Put one DTO in the registry and build its properties, or recognize it as one already there.
     *
     * Registered *before* its properties are built, so a node that recurses back to it finds it instead of building it
     * again.
     *
     * @param  array<string, string>  $unchecked  the body's top-level names the request cannot check when a form sends
     *                                            them, by the reason. Only a root has any
     * @return string the short name
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function register(
        string $shortName,
        bool $partial,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
        array $unchecked = [],
    ): string {
        if (isset($this->registry[$shortName])) {
            $existing = $this->registry[$shortName];

            // A named schema is identified by where it is written, and an inline one
            // by what it says. By position because the merged value depends on how
            // deep the walk expanded it and on what a 3.0 `$ref` wrapper carries
            // beside it (an `example`, a `readOnly`), so one component reached two
            // ways would be refused as two. By value, strictly rather than loosely
            // compared, because `[0]` and `[false]` are `==`, and without the
            // positions of the keywords nothing reads: two inline copies are
            // written at two places.
            $same = $named && $existing['named']
                ? $existing['position'] === $position
                : ! $named && ! $existing['named'] && $existing['schema']->shapeKey() === $merged->shapeKey();

            if ($existing['partial'] !== $partial || ! $same) {
                throw UnusableNameException::inputDtoClaimedTwice(
                    $shortName,
                    ['position' => $existing['position'], 'base' => $existing['base'], 'origin' => $existing['origin'], 'partial' => $existing['partial']],
                    ['position' => $position, 'base' => $base, 'origin' => $origin, 'partial' => $partial],
                );
            }

            // One schema, two bodies: a part that is a file in a multipart body
            // is a string in any other, so a schema with a file part in it
            // needs a type for each and has only the one name.
            if ($existing['multipart'] !== $this->multipart && self::hasFilePart($merged)) {
                throw UnusableNameException::inputDtoServesFilesAndStrings($shortName, $position);
            }

            // One class serves every request that reads the schema, so a name one of them cannot
            // check is optional for all, whichever got here first.
            $this->hedge($shortName, $unchecked);

            return $shortName;
        }

        $this->registry[$shortName] = [
            'partial' => $partial,
            'named' => $named,
            'multipart' => $this->multipart,
            'schema' => $merged,
            'position' => $position,
            'base' => $base,
            'origin' => $origin,
            'naming' => $naming,
            'properties' => [],
            'findings' => [],
        ];

        $properties = [];
        $claimed = [];

        foreach ($merged->properties as $key => $property) {
            $key = (string) $key;

            // Laravel reads a `*` in a rule key as a wildcard and offers no
            // escape, so the rule set emits nothing for such a key and the
            // validated payload never carries it. A required property reading
            // `$payload[$key]` would then fail on every request.
            if (RuleSetBuilder::unescapableKey($key) !== null) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` carries a `*`, which the request cannot validate, so it never reaches the '
                        .'payload and this type has no property for it.',
                    $key,
                );

                continue;
            }

            $name = self::propertyName($key);

            if (isset($claimed[$name])) {
                throw UnusableNameException::propertyClaimedTwice($shortName, $name, $claimed[$name], $key);
            }

            $claimed[$name] = $key;

            if ($name !== $key) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is not a PHP identifier, so it is read into `$%s`. `from()` and `toArray()` keep the key as written.',
                    $key,
                    $name,
                );
            }

            $type = $this->type(
                $property,
                $base,
                $key,
                self::within($position, '/properties/'.DocumentPointer::escape($key)),
                $shortName,
            );

            // A required key Laravel cannot name in `required_array_keys` is not
            // enforced when its object is nested, so a request can reach `from()`
            // without it. The DTO is shared by every operation that reaches the
            // schema, whether as a body or inside another, so its shape cannot
            // depend on which came first: the key is optional in both.
            //
            // A required `readOnly` property is the other case: the request emits no
            // presence rule for it, since `required` there is about responses, so a
            // body without it reaches `from()` all the same.
            //
            // A name a form client sends under another one is the third: when the request has no rule
            // that checks it there (the body is also JSON, or the name collides with another), the
            // validated payload cannot be promised to carry it. Only a root is read from a form, and
            // a name PHP delivers is checked and built like any other.
            $readOnly = AllOfMerger::merge($property, $this->identity, '`'.$key.'`')->readOnly;
            $uncheckedReason = $unchecked[$key] ?? null;
            $optional = $partial
                || ! in_array($key, $merged->required, true)
                || RuleSetBuilder::breaksKeyList($key)
                || $readOnly
                || $uncheckedReason !== null;

            if (! $partial && RuleSetBuilder::breaksKeyList($key) && in_array($key, $merged->required, true)) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is required by the schema, but its name has a `.` or a `,`, which Laravel cannot '
                        .'name in `required_array_keys` when the object is nested, so the property is `Optional` '
                        .'here whatever the rules say at the root.',
                    $key,
                );
            }

            if (! $partial && $uncheckedReason !== null && in_array($key, $merged->required, true)) {
                $this->registry[$shortName]['findings'][] = self::uncheckedFinding($key, $uncheckedReason);
            }

            if (! $partial && $readOnly && in_array($key, $merged->required, true)) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is required by the schema and `readOnly`, so a request does not have to carry it: '
                        .'the property is `Optional` here.',
                    $key,
                );
            }

            $properties[] = new InputDtoProperty($key, $name, $type, $optional);
        }

        // Only a body's root gets here without properties: a nested object with none is a `map` property of its
        // parent. Its keys are the data, so the root has one property that holds them all.
        if ($merged->properties === []) {
            $properties[] = $this->payloadProperty($shortName, $merged, $position, $base);
        }

        $this->registry[$shortName]['properties'] = $properties;

        return $shortName;
    }

    /**
     * The one property of a map or open-object body: `additionalProperties`, holding every key as sent.
     *
     * **Typed by the value schema only where the request judged the values.** A `query` parameter shares the validated
     * data with the body, so beside one the request judges no value, and the DTO says `mixed` rather than a type
     * nothing checked. That is asked of {@see RuleSetBuilder::validatesRootMapValues()}, before any class is registered
     * ({@see self::plan()}), so a class several operations read is the same whichever came first, and the value schema
     * of a map that ends up `mixed` is never walked: no class is registered for a value nothing reads.
     *
     * Never `Optional`: an absent or empty body is `[]`.
     */
    private function payloadProperty(string $shortName, Schema $merged, string $position, string $base): InputDtoProperty
    {
        $value = RuleSetBuilder::mapValueSchema($merged);

        if (isset($this->unvalidatedBodies[$position])) {
            if ($value !== null && ! RuleSetBuilder::allowsAnyValue($value)) {
                $this->registry[$shortName]['findings'][] = 'The request does not validate the values of this body, because a `query` '
                    .'parameter shares the validated data with it, so `additionalProperties` holds `mixed` values '
                    .'rather than the type the schema states for them.';
            }

            $type = new InputDtoType(InputDtoType::MAP);
        } else {
            $type = $this->map($merged, $base, 'additionalProperties', $position, false, $shortName);
        }

        return new InputDtoProperty('additionalProperties', 'additionalProperties', $type, false, wholePayload: true);
    }

    /**
     * Make the properties a request cannot check optional in a type already registered.
     *
     * @param  array<string, string>  $unchecked
     */
    private function hedge(string $shortName, array $unchecked): void
    {
        if ($unchecked === [] || $this->registry[$shortName]['partial']) {
            return;
        }

        $required = $this->registry[$shortName]['schema']->required;

        foreach ($this->registry[$shortName]['properties'] as $index => $property) {
            if ($property->optional || ! isset($unchecked[$property->key])) {
                continue;
            }

            $this->registry[$shortName]['properties'][$index] = new InputDtoProperty($property->key, $property->name, $property->type, true);

            if (in_array($property->key, $required, true)) {
                $this->registry[$shortName]['findings'][] = self::uncheckedFinding($property->key, $unchecked[$property->key]);
            }
        }
    }

    private static function uncheckedFinding(string $key, string $reason): string
    {
        return sprintf(
            '`%s` is required by the schema, but the request cannot check it in a form body: %s. The '
                .'payload is not promised to carry it, so the property is `Optional` here.',
            $key,
            $reason,
        );
    }

    /**
     * Whether a file part is written anywhere in a schema, a nested one and an `allOf` branch included.
     *
     * A recursion marker has nothing under it, so it ends the walk.
     */
    private static function hasFilePart(Schema $schema): bool
    {
        if ($schema->isFilePart) {
            return true;
        }

        foreach ([
            ...array_values($schema->properties),
            ...$schema->allOf,
            ...($schema->items === null ? [] : [$schema->items]),
            ...($schema->additionalPropertiesSchema === null ? [] : [$schema->additionalPropertiesSchema]),
        ] as $child) {
            if (self::hasFilePart($child)) {
                return true;
            }
        }

        return false;
    }
}
