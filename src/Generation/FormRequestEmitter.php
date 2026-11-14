<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/**
 * Turns one operation's input into the PHP of its generated `FormRequest`.
 *
 * **The class extends `Illuminate\Foundation\Http\FormRequest`, which this
 * package does not depend on.** Only `illuminate/routing` and
 * `illuminate/support` are required, and the class the generated file extends
 * lives in the application that will host it. Nothing here instantiates one:
 * the emitter writes a string, and the only place a real `FormRequest` is
 * constructed is a test running inside a booted application, which is also the
 * only proof that Laravel actually executes what is written.
 *
 * **It is `final`, and there is nothing to extend.** A project that wants
 * different rules is describing a different contract; the three things a
 * developer would otherwise reach for — authorization, messages, and
 * normalizing a payload — each have a home, and the docblock names them.
 *
 * @see docs/guide/code-generation/request-validation.md — "The generated request is final"
 */
final readonly class FormRequestEmitter
{
    /**
     * The class a generated request extends, written rather than imported.
     *
     * `illuminate/foundation` is not a dependency of this package and must not
     * become one: only `illuminate/routing` and `illuminate/support` are
     * required, and everything here emits a string. A `::class` reference would
     * put a class this package does not ship in its own type graph for no
     * benefit, since nothing here ever constructs one.
     */
    private const BASE_CLASS = 'Illuminate\\Foundation\\Http\\FormRequest';

    /**
     * The two other framework classes a generated request may name, written
     * rather than imported for the reason {@see self::BASE_CLASS} gives:
     * `illuminate/validation` is not a dependency of this package either.
     */
    private const RULE_CLASS = 'Illuminate\\Validation\\Rule';

    private const VALIDATOR_CLASS = 'Illuminate\\Validation\\Validator';

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

                /**
                 * @return array<string, list<mixed>>
                 */
                public function rules(): array
                {
                    return [
            {$this->rules($planned->rules)}
                    ];
                }
            {$this->validationData($planned->rules)}{$this->dto($planned)}{$this->after($planned->rules)}}

            PHP,
        );
    }

    /**
     * The import block: the base class always, and `Rule` and `Validator` only
     * when a rule, the closed-root check or `dto()` uses them — an unused import is
     * something a consumer's formatter removes, and a formatter with something
     * to remove is a formatter fighting the next build.
     */
    private function useStatements(PlannedRequest $planned): string
    {
        $set = $planned->rules;
        $imports = [$this->import(self::BASE_CLASS)];

        if ($set->usesInRule()) {
            $imports[] = $this->import(self::RULE_CLASS);
        }

        if ($set->closedKeys !== null || $planned->dto !== null) {
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
     * `BODY_KEYS` is the closed-root check's, and `QUERY_KEYS` is what
     * `validationData()` takes from the query string. Both are names as the
     * document writes them, which is how PHP's own bags key them.
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

        if ($set->queryKeys !== []) {
            $constants .= sprintf(
                "    /**\n     * The `query` parameters the contract declares, the only keys read from the query string.\n     */\n"
                    ."    private const QUERY_KEYS = [%s];\n\n",
                implode(', ', array_map($this->literal(...), $set->queryKeys)),
            );
        }

        return $constants;
    }

    /**
     * `validationData()`, and the body reader it shares with the closed-root check.
     *
     * **Laravel validates `$this->all()`, which is the body with the query string
     * merged in**, and a rule key has one source in the contract. So a required
     * body field supplied in the URL passed, a `query` parameter was satisfied by a
     * body key of the same name, and a body integer sent as `?age=31` reached
     * `validated()` as the string `"31"`. The build already refuses one name
     * declared in both places, so each key can be read from the one place it
     * belongs to: the body's keys from the body, and the declared `query`
     * parameters, by name, from the query string.
     *
     * **The body is read from the body, never through `input()` or
     * `getInputSource()`**, which answer with the query string on a `GET` or a
     * `HEAD`. The uploads are merged the way Laravel's own `all()` merges them, with
     * the text field winning on a shared name, so a file rule still judges an
     * `Illuminate\Http\UploadedFile` and `dto()` still receives one. `array_diff_key()`
     * and `+` keep an integer key where a spread would renumber it: a property
     * named `"2024"` is a valid payload.
     *
     * **`rules()` is untouched**: this changes the data the rules judge, and the
     * rule set stays exactly what the contract says.
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
                '        return array_replace_recursive($body, $this->allFiles(), $body);',
                '    }',
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * `dto()`, or nothing for a request whose body is not an object.
     *
     * **`validated()` keeps returning Laravel's array**: it is the framework's
     * own method and has callers, so the DTO sits beside it under a name of
     * ours. The DTO's properties are the body schema's, and a query parameter is
     * not one of them: `validated()` carries both, and `from()` reads only what
     * the body declares, so `validated('page')` is still where a query
     * parameter is read.
     *
     * **It reads the validated data with `excludeUnvalidatedArrayKeys` off**, and
     * puts the flag back. By default `validated()` leaves out an array that has
     * child rules when none of its declared children was sent, so a required
     * object sent as `{}` passes `present|array` and is then absent from the data
     * `from()` reads, a list of objects with no declared key loses its elements, and
     * an optional `{}` arrives as `Optional`. Kept as sent, the array carries the
     * keys the contract does not declare too, and `from()` reads only the ones it
     * does, so none reaches the DTO. The `assert` is for the static analysis of
     * the class a consumer reads: `getValidatorInstance()` is typed as the
     * contract, and the flag is on the validator Laravel builds.
     *
     * @see docs/guide/code-generation/request-validation.md — "The payload arrives as a DTO"
     */
    private function dto(PlannedRequest $planned): string
    {
        if ($planned->dto === null) {
            return '';
        }

        return sprintf(<<<'PHP'

                public function dto(): %1$s
                {
                    $validator = $this->getValidatorInstance();
                    assert($validator instanceof Validator);

                    $excluded = $validator->excludeUnvalidatedArrayKeys;
                    $validator->excludeUnvalidatedArrayKeys = false;

                    try {
                        return %1$s::from($validator->validated());
                    } finally {
                        $validator->excludeUnvalidatedArrayKeys = $excluded;
                    }
                }

            PHP, $planned->dto);
    }

    /**
     * The closed-root check, or nothing.
     *
     * **A closure, and one emitted shape.** Laravel's `#[FailOnUnknownFields]`
     * does this from 12.x on, but not on the lowest Laravel this package
     * supports, and an attribute behind a version gate with this closure
     * underneath it anyway would be two shapes that have to stay equivalent.
     *
     * **Top-level keys only, read from the body rather than from `all()`**, by
     * the same `bodyInput()` `validationData()` reads: the JSON bag, or the form
     * bag, and the uploaded files either way, never `getInputSource()`, which
     * answers with the query string on a `GET` or a `HEAD`. The files are a bag of
     * their own, so a closed root that left them out would let an undeclared upload
     * through. The query string is not the body, and a nested object that closes
     * itself already refuses its own extras through `array:` — so checking
     * deeper here would repeat that, and would refuse the extras of a nested
     * object that allows them.
     *
     * **The refusal is Laravel's `prohibited`**, through `addFailure()`, so its
     * words are the ones in `lang/en/validation.php` and a project's own lines
     * and translations apply, which a message written here could not honor. An
     * undeclared key is exactly what that rule says: a field that is not allowed.
     */
    private function after(RuleSet $set): string
    {
        if ($set->closedKeys === null) {
            return '';
        }

        return <<<'PHP'

                /**
                 * @return list<callable(Validator): void>
                 */
                public function after(): array
                {
                    return [
                        function (Validator $validator): void {
                            foreach (array_keys($this->bodyInput()) as $key) {
                                if (! in_array((string) $key, self::BODY_KEYS, true)) {
                                    $validator->addFailure((string) $key, 'prohibited');
                                }
                            }
                        },
                    ];
                }

            PHP;
    }

    /**
     * The rule set as the body of the returned array, one field per line.
     *
     * Indented to sit inside a `return [` two levels deep, and emitted with
     * `var_export` for the same reason every other literal in this namespace is:
     * a field name may legally carry an apostrophe, and a hand-quoted literal
     * would stop parsing.
     */
    private function rules(RuleSet $set): string
    {
        $lines = [];

        foreach ($set->rules as $key => $rules) {
            $lines[] = sprintf(
                '            %s => [%s],',
                // Cast: PHP turned a key like "2024" into an integer on the way in.
                $this->literal((string) $key),
                implode(', ', array_map($this->rule(...), $rules)),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * A value as a PHP string literal, whatever it contains.
     */
    private function literal(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * One rule as PHP: a string literal, or `Rule::in()` over the values an
     * enumeration allows — never `in:a,b`, which splits on a comma inside a
     * value.
     */
    private function rule(string|InRule $rule): string
    {
        if (is_string($rule)) {
            return $this->literal($rule);
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
     * What the build knew and the reader cannot see.
     *
     * **Everything the rule set could not express is here**, which is the half
     * that makes an incomplete rule set honest rather than misleading. A reader
     * asking "why is this field not enforced" gets the answer at the moment
     * they ask it, in the file they already have open.
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
                '`dto()` returns the body as `%s`, built from `validated()`, which keeps returning '
                    .'Laravel\'s array. A `query` parameter is not a property of it: read one with '
                    .'`validated(\'name\')`.',
                CommentText::safe($planned->dto),
            );
        }

        if ($planned->rules->findings === []) {
            $findings[] = 'Every constraint the contract states is in the rule set above. Nothing '
                .'was read and left unenforced.';

            return $findings;
        }

        return [...$findings, ...$planned->rules->findings];
    }

    /**
     * Where to go from here.
     *
     * **The first line is the one that matters.** Laravel runs a `FormRequest`
     * because a method declared one, so a generated class nobody declares is
     * emitted, correct, tested, and never executed. Naming the method that
     * declares it is how a reader checks that in one jump.
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
