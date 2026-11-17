<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Illuminate\Support\Fluent;

/**
 * Turns one operation's input into the PHP of its generated `FormRequest`.
 *
 * **The class extends `Illuminate\Foundation\Http\FormRequest`, which this package does not depend on.** Only
 * `illuminate/routing` and `illuminate/support` are required, and the class the generated file extends lives in the
 * application that will host it. Nothing here instantiates one: the emitter writes a string, and the only place a real
 * `FormRequest` is constructed is a test running inside a booted application, which is also the only proof that Laravel
 * actually executes what is written.
 *
 * **It is `final`, and there is nothing to extend.** A project that wants different rules is describing a different
 * contract; the three things a developer would otherwise reach for — authorization, messages, and normalizing a payload
 * — each have a home, and the docblock names them.
 *
 * @see docs/guide/code-generation/request-validation.md — "The generated request is final"
 */
final readonly class FormRequestEmitter
{
    /**
     * The class a generated request extends, written rather than imported.
     *
     * `illuminate/foundation` is not a dependency of this package and must not become one: only `illuminate/routing`
     * and `illuminate/support` are required, and everything here emits a string. A `::class` reference would put a
     * class this package does not ship in its own type graph for no benefit, since nothing here ever constructs one.
     */
    private const BASE_CLASS = 'Illuminate\\Foundation\\Http\\FormRequest';

    /**
     * The two other framework classes a generated request may name, written rather than imported for the reason
     * {@see self::BASE_CLASS} gives: `illuminate/validation` is not a dependency of this package either.
     */
    private const RULE_CLASS = 'Illuminate\\Validation\\Rule';

    private const VALIDATOR_CLASS = 'Illuminate\\Validation\\Validator';

    /**
     * The raw content of a JSON `null` body, as the emitted code compares it (after `trim()`). Written once: the
     * `dto()` guard, the required-key check and the root check all test for it.
     */
    private const NULL_CONTENT = "'null'";

    /**
     * What `dto()` starts with when the body may be `null`: the payload is `null`, so `dto()` says so.
     *
     * A JSON `null` is the one body that is neither an object nor absent, and a DTO of `Optional`s would say "an object
     * with nothing in it". An absent optional body has an empty content, not `null`, and keeps the DTO.
     */
    private const NULL_BODY = "\n"
        ."        // Why: a `null` body is the payload `null`, not an object with nothing in it.\n"
        .'        if ($this->isJson() && trim($this->getContent()) === '.self::NULL_CONTENT.") {\n"
        ."            return null;\n"
        ."        }\n";

    public function __construct(
        private string $namespace,
        private string $specPath,
    ) {}

    public function emit(PlannedRequest $planned): GeneratedFile
    {
        return new GeneratedFile(
            $planned->relativePath(),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace}\\Requests;

            {$this->useStatements($planned)}

            {$this->docblock($planned)}
            final class {$planned->name->shortName} extends FormRequest
            {
            {$this->keyConstants($planned->rules)}    public function authorize(): bool
                {
                    return true;
                }

            {$this->rulesMethod($planned->rules)}
            {$this->validationData($planned->rules)}{$this->dto($planned)}{$this->after($planned->rules)}}

            PHP,
        );
    }

    /**
     * The import block: the base class always, and `Rule` and `Validator` only when a rule, the closed-root check or
     * `dto()` uses them — an unused import is something a consumer's formatter removes, and a formatter with something
     * to remove is a formatter fighting the next build.
     */
    private function useStatements(PlannedRequest $planned): string
    {
        $set = $planned->rules;
        $imports = [$this->import(self::BASE_CLASS)];

        if ($set->usesRule()) {
            $imports[] = $this->import(self::RULE_CLASS);
        }

        if ($set->whenNotArray !== []) {
            $imports[] = $this->import(Fluent::class);
        }

        if ($set->usesNullOrAbsent()) {
            $imports[] = $this->import(NullOrAbsent::class);
        }

        if ($set->closedKeys !== null || $set->jsonRoot || $planned->dto !== null) {
            $imports[] = $this->import(self::VALIDATOR_CLASS);
        }

        if ($planned->dto !== null) {
            $imports[] = $this->import($this->namespace.'\\Data\\'.$planned->dto);
        }

        // Ordered the way php-cs-fixer's `ordered_imports` orders them, for the
        // reason ControllerEmitter gives at length.
        usort($imports, static fn (string $first, string $second): int => strcasecmp(
            str_replace('\\', ' ', $first),
            str_replace('\\', ' ', $second),
        ));

        return implode("\n", array_map(static fn (string $class): string => 'use '.$class.';', $imports));
    }

    /**
     * The constants the generated class reads its keys from, or nothing.
     *
     * `BODY_KEYS` is the closed-root check's, `FORM_NAMES` is where `dto()` finds a form value under a name PHP
     * rewrote, `SCALAR_NAMES` lists the names whose value `dto()` reads only when it is not an array, and `QUERY_KEYS`
     * is what `validationData()` takes from the query string. `FORM_NAMES` is keyed by the names the document writes.
     * `BODY_KEYS` and `QUERY_KEYS` hold the keys the values arrive under, which is how PHP's own bags key them: the
     * name the document writes, and for a name PHP rewrites the one it gives it (`page` for a `query` parameter
     * `page[size]`).
     */
    private function keyConstants(RuleSet $set): string
    {
        $constants = '';

        if ($set->closedKeys !== null) {
            $constants .= sprintf(
                "    /**\n     * The only top-level keys the contract's body declares, and the only ones it allows.\n     */\n"
                    ."    private const BODY_KEYS = [%s];\n\n",
                implode(', ', array_map($this->literal(...), $set->closedKeys)),
            );
        }

        if ($set->formPaths !== []) {
            $constants .= sprintf(
                "    /**\n     * Where a form delivers the properties whose names PHP rewrites, by the name the contract writes.\n"
                    ."     * The rules are keyed by what arrives, and `dto()` reads each value back from here.\n     */\n"
                    ."    private const FORM_NAMES = [%s];\n\n",
                implode(', ', array_map(
                    fn (string $name, array $path): string => $this->literal($name).' => ['.implode(', ', array_map($this->literal(...), $path)).']',
                    array_keys($set->formPaths),
                    $set->formPaths,
                )),
            );
        }

        if ($set->scalarNames !== []) {
            $constants .= sprintf(
                "    /**\n     * The names a form also nests other names under: a value is theirs only when it is not an array, which is the\n"
                    ."     * nested names'. `dto()` drops the array after it has read each of them.\n     */\n"
                    ."    private const SCALAR_NAMES = [%s];\n\n",
                implode(', ', array_map($this->literal(...), $set->scalarNames)),
            );
        }

        if ($set->requiredOnceSent !== []) {
            $constants .= sprintf(
                "    /**\n     * The keys the body must carry once it is sent, which the rules ask for only through a sibling: an optional\n"
                    ."     * body's, and a required body's that may be `null`. An empty object leaves every key out of the data, so the\n"
                    ."     * request reads them from the raw content.\n     */\n"
                    ."    private const REQUIRED_KEYS = [%s];\n\n",
                implode(', ', array_map(
                    fn (string|int $name, string $rule): string => $this->literal((string) $name).' => '.$this->literal($rule),
                    array_keys($set->requiredOnceSent),
                    $set->requiredOnceSent,
                )),
            );
        }

        if ($set->queryKeys !== []) {
            $constants .= sprintf(
                "    /**\n     * The keys the contract's `query` parameters arrive under, the only ones read from the query string.\n     */\n"
                    ."    private const QUERY_KEYS = [%s];\n\n",
                implode(', ', array_map($this->literal(...), $set->queryKeys)),
            );
        }

        return $constants;
    }

    /**
     * `validationData()`, and the body reader it shares with the closed-root check.
     *
     * **Laravel validates `$this->all()`, which is the body with the query string merged in**, and a rule key has one
     * source in the contract. So a required body field supplied in the URL passed, a `query` parameter was satisfied by
     * a body key of the same name, and a body integer sent as `?age=31` reached `validated()` as the string `"31"`. The
     * build already refuses one name declared in both places, so each key can be read from the one place it belongs to:
     * the body's keys from the body, and the keys the declared `query` parameters arrive under, from the query string.
     *
     * **The body is read from the body, never through `input()` or `getInputSource()`**, which answer with the query
     * string on a `GET` or a `HEAD`. The uploads are merged the way Laravel's own `all()` merges them, with the text
     * field winning on a shared name, so a file rule still judges an `Illuminate\Http\UploadedFile` and `dto()` still
     * receives one. `array_diff_key()` and `+` keep an integer key where a spread would renumber it: a property named
     * `"2024"` is a valid payload.
     *
     * **`rules()` is untouched**: this changes the data the rules judge, and the rule set stays exactly what the
     * contract says.
     *
     * @see docs/guide/code-generation/request-validation.md — "Each field is read from where the contract put it"
     */
    private function validationData(RuleSet $set): string
    {
        $hasBody = $set->body !== null;
        $hasQuery = $set->queryKeys !== [];

        $statements = match (true) {
            $hasBody && $hasQuery => [
                '        $query = array_flip(self::QUERY_KEYS);',
                '',
                '        return array_diff_key($this->bodyInput(), $query) + array_intersect_key($this->query->all(), $query);',
            ],
            $hasBody => ['        return $this->bodyInput();'],
            $hasQuery => ['        return array_intersect_key($this->query->all(), array_flip(self::QUERY_KEYS));'],
            default => ['        return [];'],
        };

        $lines = [
            '',
            '    /**',
            '     * The data the rules judge: the body\'s keys come from the body and the `query`',
            '     * parameters from the query string, where Laravel would have merged the two.',
            '     *',
            '     * @return array<array-key, mixed>',
            '     */',
            '    public function validationData(): array',
            '    {',
            ...$statements,
            '    }',
        ];

        if ($hasBody) {
            array_push(
                $lines,
                '',
                '    /**',
                '     * The body and its uploads, and never the query string.',
                '     *',
                '     * @return array<array-key, mixed>',
                '     */',
                '    private function bodyInput(): array',
                '    {',
                '        $body = $this->isJson() ? $this->json()->all() : $this->request->all();',
                '',
                '        // Why: this is Laravel\'s own all(): a text field wins over an upload of the same name.',
                '        return array_replace_recursive($body, $this->allFiles(), $body);',
                '    }',
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * `dto()`, or nothing for a request whose body is not an object.
     *
     * **`validated()` keeps returning Laravel's array**: it is the framework's own method and has callers, so the DTO
     * sits beside it under a name of ours. The DTO's properties are the body schema's, and a query parameter is not one
     * of them: `validated()` carries both, and `from()` reads only what the body declares, so `validated('page')` is
     * still where a query parameter is read.
     *
     * **It reads the validated data with `excludeUnvalidatedArrayKeys` off**, and puts the flag back. By default
     * `validated()` leaves out an array that has child rules when none of its declared children was sent, so a required
     * object sent as `{}` passes `present|array` and is then absent from the data `from()` reads, a list of objects
     * with no declared key loses its elements, and an optional `{}` arrives as `Optional`. Kept as sent, the array
     * carries the keys the contract does not declare too, and `from()` reads only the ones it does, so none reaches the
     * DTO. The `assert` is for the static analysis of the class a consumer reads: `getValidatorInstance()` is typed as
     * the contract, and the flag is on the validator Laravel builds.
     *
     * **A map or open-object body's DTO holds every key it is handed**, so `dto()` takes the `query` parameters' keys
     * out of the validated data first: they are in `validated()` and are not the body's.
     *
     * **It returns `null` for a `null` body, and is typed `?X` then**: when the root is nullable and read as JSON, the
     * request lets a JSON `null` through and the payload is `null`, not an object with nothing in it.
     *
     * @see docs/guide/code-generation/request-validation.md — "The payload arrives as a DTO"
     */
    private function dto(PlannedRequest $planned): string
    {
        if ($planned->dto === null) {
            return '';
        }

        $payload = $planned->rules->formPaths === [] && $planned->rules->scalarNames === []
            ? '$validator->validated()'
            : '$this->contractNames($validator->validated())';

        // Why: the keys of a map or open-object body are the data, so the DTO reads every key it is handed, and
        // `validated()` holds the `query` parameters' keys beside them.
        if ($planned->rules->mapRoot && $planned->rules->queryKeys !== []) {
            $payload = 'array_diff_key('.$payload.', array_flip(self::QUERY_KEYS))';
        }

        $nullable = self::dtoIsNullable($planned->rules);

        return sprintf(<<<'PHP'

                public function dto(): %3$s%1$s
                {%4$s
                    $validator = $this->getValidatorInstance();
                    assert($validator instanceof Validator);

                    $excluded = $validator->excludeUnvalidatedArrayKeys;
                    $validator->excludeUnvalidatedArrayKeys = false;

                    try {
                        return %1$s::from(%2$s);
                    } finally {
                        $validator->excludeUnvalidatedArrayKeys = $excluded;
                    }
                }

            PHP, $planned->dto, $payload, $nullable ? '?' : '', $nullable ? self::NULL_BODY : '').$this->contractNames($planned->rules);
    }

    /**
     * Whether `dto()` may return `null`: when the request lets a JSON `null` through, which is its root check's
     * condition (`after()`), so the type cannot drift from the check. The guard in `dto()` and the checks in `after()`
     * each test for the content itself, through {@see self::NULL_CONTENT}. A form cannot send `null`.
     */
    private static function dtoIsNullable(RuleSet $set): bool
    {
        return $set->nullableRoot && $set->jsonRoot;
    }

    /**
     * `contractNames()`, or nothing: the validated payload of a form body with each value PHP delivered under another
     * name also under the name the contract writes, which is the key `from()` reads, and without the array a form
     * builds for the names below a name.
     *
     * **A form client's `user.name` reaches Laravel as `user_name`**, and the rules are keyed by what arrives, so
     * `validated()` holds it there. The DTO's properties are the contract's, and one class serves a JSON body and a
     * nested use too, so the name is put back here and not in the DTO. `validated()` is left as Laravel returns it.
     *
     * **A name a form also nests other names under holds the array a form builds for them** (`page` for `page[size]`),
     * and `from()` casts what it reads (`(int)` of an array is `1`), so the array is dropped once every name is put
     * back: the nested names were read out of it first.
     */
    private function contractNames(RuleSet $set): string
    {
        if ($set->formPaths === [] && $set->scalarNames === []) {
            return '';
        }

        $putBack = $set->formPaths === [] ? '' : <<<'PHP'
                    foreach (self::FORM_NAMES as $name => $path) {
                        $value = $payload;

                        foreach ($path as $step) {
                            if (! is_array($value) || ! array_key_exists($step, $value)) {
                                continue 2;
                            }

                            $value = $value[$step];
                        }

                        $payload[$name] = $value;
                    }


            PHP;
        $dropped = $set->scalarNames === [] ? '' : <<<'PHP'
                    foreach (self::SCALAR_NAMES as $name) {
                        if (is_array($payload[$name] ?? null)) {
                            unset($payload[$name]);
                        }
                    }


            PHP;

        return <<<PHP

                /**
                 * @param  array<array-key, mixed>  \$payload
                 * @return array<array-key, mixed>
                 */
                private function contractNames(array \$payload): array
                {
            {$putBack}{$dropped}        return \$payload;
                }

            PHP;
    }

    /**
     * The root checks, or nothing: a body that may be absent or `null` refuses what is sent without a key it requires,
     * a JSON body that is not an object is refused, and a closed root refuses the keys it does not declare.
     *
     * **A closure, and one emitted shape.** Laravel's `#[FailOnUnknownFields]` does the third from 12.x on, but not on
     * the lowest Laravel this package supports, and an attribute behind a version gate with this closure underneath it
     * anyway would be two shapes that have to stay equivalent. All the checks sit in the one closure, since a root that
     * is not an object has no keys to compare, and answering for both would say the same thing twice.
     *
     * **The required-key check comes first, and reads the raw content.** An optional body whose schema requires
     * properties asks for each through its siblings (`present_with`), which says nothing about `{}`: every key is out
     * of the data, and a required body whose root allows `null` asks the same way, since `null` reaches the rules as
     * `[]`. So what is sent as the body without a key `REQUIRED_KEYS` lists is refused on that key, with the rule it
     * would get in a required body that cannot be `null`. **For an optional body it runs for a decoded JSON object
     * only:** an empty content is an absent body, and `null` is not an object. **For a required body it runs for
     * anything but a JSON `null`:** a form or a `text/plain` content that lacks the keys, and an empty content, are
     * refused on them as they were before the keys followed their siblings, and only `null` is let through. It is first
     * so that a closed root, whose checks return early, keeps these errors too, and it skips a key `present_with`
     * already refused, so each is named once.
     *
     * **The second is the contract's own for a typed object, and stricter for an untyped one.** Laravel reads a JSON
     * body as `(array) json_decode(...)`, so `"Ada"`, `42` and `[1,2]` reach the rules as a list that no rule has a key
     * for, and an open root with nothing required would accept them. Only a JSON request is checked (a form cannot send
     * a non-object). An empty content is not a non-object but an absent body: it is refused when the body is
     * `required`, which no rule says when every property is optional, and accepted when it is not. That check holds for
     * a form body too, and reads a request as absent whatever its `Content-Type` is, unless that is a form's (JSON,
     * `text/plain`, none at all: a request with none carries no form field either). A request with a form
     * `Content-Type` has its body read from the form bag, whose fields need not show in the raw content, so it is not
     * read as absent. The type is compared without case, as PHP parses a form whatever the case of its type, and
     * Symfony's `getFormat()` compares exactly. A schema that allows `null` allows it here.
     *
     * **The third reads the top-level keys from the body rather than from `all()`**, by the same `bodyInput()`
     * `validationData()` reads: the JSON bag, or the form bag, and the uploaded files either way, never
     * `getInputSource()`, which answers with the query string on a `GET` or a `HEAD`. The files are a bag of their own,
     * so a closed root that left them out would let an undeclared upload through. The query string is not the body, and
     * a nested object that closes itself already refuses its own extras through `array:` — so checking deeper here
     * would repeat that, and would refuse the extras of a nested object that allows them.
     *
     * **The refusal is Laravel's own rule**, through `addFailure()`, so its words are the ones in
     * `lang/en/validation.php` and a project's own lines and translations apply, which a message written here could not
     * honor: `prohibited` for an undeclared key, which is exactly what that rule says, and `array` for a root that is
     * not an object, as a nested object that is not one is refused.
     */
    private function after(RuleSet $set): string
    {
        if ($set->closedKeys === null && ! $set->jsonRoot && ! $set->requiredRoot) {
            return '';
        }

        $lines = [];

        if ($set->jsonRoot || $set->requiredRoot) {
            $lines = ['                $content = $this->getContent();', ''];
        }

        // Why: first, before the checks that return early, so a closed root keeps these errors too. The list is
        // only ever filled for a body read as JSON, which is what reads `$content` above. An optional body may be
        // absent, so it is refused when it is a JSON object (`{}` or one with none of the keys), and `null` or an
        // empty content is not one. A required body may only be `null`, so it is refused for anything else. A key
        // `present_with` already refused has its error, so it is named once.
        if ($set->requiredOnceSent !== []) {
            $lines = [
                ...$lines,
                $set->requiredRoot
                    ? '                if (! ($this->isJson() && trim($content) === '.self::NULL_CONTENT.')) {'
                    : '                if ($this->isJson() && is_object(json_decode($content))) {',
                '                    foreach (self::REQUIRED_KEYS as $key => $rule) {',
                '                        if (! array_key_exists($key, $this->bodyInput()) && ! $validator->errors()->has((string) $key)) {',
                '                            $validator->addFailure((string) $key, $rule);',
                '                        }',
                '                    }',
                '                }',
                '',
            ];
        }

        // Why: a request whose type is not a form's reads nothing from the form bag, and one with no
        // `Content-Type` carries no form field either (a multipart always sets one), so no content is as
        // absent as a body gets. A form with a type and no content is not read as absent, since its fields
        // need not show in the content. The type is lowered because `getFormat()` compares exactly.
        if ($set->requiredRoot) {
            $lines = [
                ...$lines,
                '                if (',
                '                    trim($content) === \'\'',
                '                    && $this->allFiles() === []',
                '                    && $this->getFormat(strtolower((string) $this->headers->get(\'Content-Type\'))) !== \'form\'',
                '                ) {',
                '                    $validator->addFailure('.$this->literal($set->rootKey).', \'required\');',
                ...($set->closedKeys === null ? [] : ['', '                    return;']),
                '                }',
            ];

            if ($set->jsonRoot) {
                $lines[] = '';
            }
        }

        if ($set->jsonRoot) {
            $lines = [
                ...$lines,
                '                // Why: decoded without `assoc`, because `{}` and `[]` are both `[]` with it.',
                '                if ($this->isJson() && trim($content) !== \'\' && ! is_object(json_decode($content))'
                    .($set->nullableRoot ? ' && trim($content) !== '.self::NULL_CONTENT : '').') {',
                '                    $validator->addFailure('.$this->literal($set->rootKey).', \'array\');',
                ...($set->closedKeys === null ? [] : ['', '                    return;']),
                '                }',
            ];
        }

        if ($set->closedKeys !== null) {
            if ($lines !== []) {
                $lines[] = '';
            }

            array_push(
                $lines,
                '                foreach (array_keys($this->bodyInput()) as $key) {',
                '                    if (! in_array((string) $key, self::BODY_KEYS, true)) {',
                '                        $validator->addFailure((string) $key, \'prohibited\');',
                '                    }',
                '                }',
            );
        }

        return implode("\n", [
            '',
            '    /**',
            '     * @return list<callable(Validator): void>',
            '     */',
            '    public function after(): array',
            '    {',
            '        return [',
            '            function (Validator $validator): void {',
            ...$lines,
            '            },',
            '        ];',
            '    }',
        ])."\n";
    }

    /**
     * `rules()`, and the helper that names the integer-like keys of a map or open-object body.
     *
     * **A key such as `"1042"` needs a rule of its own.** Laravel expands `*` over data it has put through
     * `array_merge()`, which renumbers an integer-like top-level key, so the wildcard never reaches it: the key would
     * be missing from `validated()` and its value never judged. Every rule under the root `*` is written again under
     * each integer-like key the body carries, which is what a request for a map of IDs needs. A key that is not an
     * integer (`sku-1`, `a.b`) is the wildcard's, since Laravel keeps it as it is.
     */
    private function rulesMethod(RuleSet $set): string
    {
        $rules = $this->rules($set);

        if (! $set->mapRoot || ! array_key_exists('*', $set->rules)) {
            return "    /**\n     * @return array<string, list<mixed>>\n     */\n    public function rules(): array\n    {\n        return [\n"
                .$rules."\n        ];\n    }";
        }

        $skip = $set->queryKeys === [] ? '' : ' (the `query` parameters have their own rules)';

        return "    /**\n     * @return array<array-key, list<mixed>>\n     */\n    public function rules(): array\n    {\n        return \$this->withIntegerKeys([\n"
            .$rules."\n        ]);\n    }\n\n"
            ."    /**\n"
            ."     * Why: Laravel renumbers an integer-like top-level key when it expands `*`, so the wildcard never reaches it.\n"
            ."     * Each rule under `*` is written again under every integer-like key the body carries{$skip}.\n"
            ."     *\n"
            ."     * @param  array<string, list<mixed>>  \$rules\n"
            ."     * @return array<array-key, list<mixed>>\n"
            ."     */\n"
            ."    private function withIntegerKeys(array \$rules): array\n"
            ."    {\n"
            .($set->queryKeys === []
                ? "        \$body = \$this->bodyInput();\n"
                : "        \$body = array_diff_key(\$this->bodyInput(), array_flip(self::QUERY_KEYS));\n")
            ."\n"
            ."        \$wildcard = \$rules;\n"
            ."\n"
            ."        foreach (array_keys(\$body) as \$key) {\n"
            ."            if (! is_int(\$key)) {\n"
            ."                continue;\n"
            ."            }\n"
            ."\n"
            ."            foreach (\$wildcard as \$name => \$set) {\n"
            ."                \$name = (string) \$name;\n"
            ."\n"
            ."                if (\$name === '*' || str_starts_with(\$name, '*.')) {\n"
            ."                    \$rules[\$key.substr(\$name, 1)] = \$set;\n"
            ."                }\n"
            ."            }\n"
            ."        }\n"
            ."\n"
            ."        return \$rules;\n"
            .'    }';
    }

    /**
     * The rule set as the body of the returned array, one field per line.
     *
     * Indented to sit inside a `return [` two levels deep, and emitted with `var_export` for the same reason every
     * other literal in this namespace is: a field name may legally carry an apostrophe, and a hand-quoted literal would
     * stop parsing.
     */
    private function rules(RuleSet $set): string
    {
        $lines = [];

        foreach ($set->rules as $key => $rules) {
            // Cast: PHP turned a key like "2024" into an integer on the way in.
            $key = (string) $key;
            $list = '['.implode(', ', array_map($this->rule(...), $rules)).']';

            if (isset($set->whenNotArray[$key])) {
                $lines[] = sprintf(
                    "            %s => Rule::when(\n                fn (Fluent \$input): bool => ! is_array(%s),\n                %s,\n                ['%s'],\n            ),",
                    $this->literal($key),
                    $this->valueAt($set->whenNotArray[$key]),
                    $list,
                    in_array($key, $set->dropsArray, true) ? 'exclude' : 'array',
                );

                continue;
            }

            $lines[] = sprintf('            %s => %s,', $this->literal($key), $list);
        }

        return implode("\n", $lines);
    }

    /**
     * The read of a key's value out of the data a condition receives: one dotted key, or a list of steps when one of
     * them holds a dot, which a dotted key cannot spell.
     *
     * @param  list<string>  $path
     */
    private function valueAt(array $path): string
    {
        foreach ($path as $step) {
            if (str_contains($step, '.')) {
                return 'data_get($input, ['.implode(', ', array_map($this->literal(...), $path)).'])';
            }
        }

        return '$input->get('.$this->literal(implode('.', $path)).')';
    }

    /**
     * A value as a PHP string literal, whatever it contains.
     */
    private function literal(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * One rule as PHP: a string literal, or `Rule::in()` over the values an enumeration allows — never `in:a,b`, which
     * splits on a comma inside a value.
     */
    private function rule(string|InRule|NullOrAbsent $rule): string
    {
        if (is_string($rule)) {
            return $this->literal($rule);
        }

        if ($rule instanceof NullOrAbsent) {
            return 'new NullOrAbsent';
        }

        return 'Rule::in(['.implode(', ', array_map(
            static fn (string|int|float|bool $value): string => var_export($value, true),
            $rule->values,
        )).'])';
    }

    /**
     * A fully-qualified name with no leading separator, for a `use` statement.
     */
    private function import(string $class): string
    {
        return ltrim($class, '\\');
    }

    private function docblock(PlannedRequest $planned): string
    {
        return GeneratedDocblock::render(
            [
                CommentText::safe($this->specPath),
                CommentText::safe(DocumentPointer::forOperation($planned->operation)),
            ],
            $this->findings($planned),
            $this->navigation($planned),
        );
    }

    /**
     * The clause that names the key a `query` name PHP rewrites is read under, with the first such name of the request
     * that has a rule, or nothing when there is none: a request with no rewritten name has nothing to say about a key.
     */
    private function rewrittenQueryExample(PlannedRequest $planned): string
    {
        foreach ($planned->operation->queryParameters as $parameter) {
            $key = RuleSetBuilder::queryKey($parameter->name);

            if ($key !== $parameter->name && ! str_contains($key, '\\') && isset($planned->rules->rules[$key])) {
                return sprintf(
                    ', under the key it arrives under for a name PHP rewrites (`validated(\'%s\')` for `%s`)',
                    CommentText::safe($key),
                    CommentText::safe($parameter->name),
                );
            }
        }

        return '';
    }

    /**
     * What the build knew and the reader cannot see.
     *
     * **Everything the rule set could not express is here**, which is the half that makes an incomplete rule set honest
     * rather than misleading. A reader asking "why is this field not enforced" gets the answer at the moment they ask
     * it, in the file they already have open.
     *
     * @return non-empty-list<string>
     */
    private function findings(PlannedRequest $planned): array
    {
        $operation = $planned->operation;

        $findings = [
            $operation->operationId === null
                ? 'Class name derived from the method and path, because the operation declares no '
                    .'`operationId`. `x-controller` does not name this class: it names a controller, '
                    .'and one declaration renaming two classes is not a seam worth having.'
                : 'Class name taken from the operation\'s `operationId`. There is no `x-request`, '
                    .'because this class is `final` and a name nobody may extend is a name nobody '
                    .'needs to choose.',
            '`authorize()` returns true, and that is not an omission. What an operation requires of '
                .'a caller is the route\'s middleware and a Policy in the controller, so a generated '
                .'answer here would be a second place to look for one decision.',
            'Messages and attribute names come from `lang/en/validation.php`, which Laravel already '
                .'reads per field and per rule. Normalize a payload in `middleware()`, which runs '
                .'before this class does.',
        ];

        if ($planned->dto !== null) {
            $findings[] = sprintf(
                '`dto()` returns the body as `%s`%s, built from `validated()`, which keeps returning '
                    .'Laravel\'s array. A `query` parameter is not a property of it: read one with '
                    .'`validated(\'name\')`%s.',
                CommentText::safe($planned->dto),
                self::dtoIsNullable($planned->rules) ? ', or `null` when the body is `null`' : '',
                $this->rewrittenQueryExample($planned),
            );
        }

        if (! $planned->rules->leavesUnenforced()) {
            $findings[] = 'Every constraint the contract states is in the rule set above. Nothing '
                .'was read and left unenforced.';
        }

        return [...$findings, ...$planned->rules->findings];
    }

    /**
     * Where to go from here.
     *
     * **The first line is the one that matters.** Laravel runs a `FormRequest` because a method declared one, so a
     * generated class nobody declares is emitted, correct, tested, and never executed. Naming the method that declares
     * it is how a reader checks that in one jump.
     *
     * @return non-empty-list<string>
     */
    private function navigation(PlannedRequest $planned): array
    {
        $dto = $planned->dto === null ? [] : [
            sprintf(
                '@see `\\%s\\Data\\%s`',
                CommentText::safe($this->namespace),
                CommentText::safe($planned->dto),
            ),
            '     — what `dto()` returns',
        ];

        return [
            // Written as two fixed lines rather than wrapped, the way the
            // controller's own navigation block writes its longest annotation.
            // A wrapper measuring the whole line breaks after `@see` when the
            // class name alone is long, and an annotation separated from its
            // target is worse than one that overflows.
            //
            // **Backticks, and they are load-bearing.** Pint's
            // `fully_qualified_strict_types` rewrites a bare fully-qualified
            // name in a docblock into an import plus a short name — verified
            // against the vendored Pint — so an unquoted one means the build
            // and a consumer's formatter rewriting each other forever. It also
            // means a `use` for the controller in a file that never mentions
            // it in code, which would collide outright with a class of the
            // same short name.
            sprintf(
                '@see `\\%s\\Controllers\\%s::routeAction()`',
                CommentText::safe($this->namespace),
                CommentText::safe($planned->controllerShortName),
            ),
            '     — the method that declares this class, and what makes Laravel run it',
            '@see '.GeneratedRoutesLocator::FILE.' — the route that reaches that method',
            ...$dto,
        ];
    }
}
