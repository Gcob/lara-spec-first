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

            {$this->useStatements($planned->rules)}

            {$this->docblock($planned)}
            final class {$planned->name->shortName} extends FormRequest
            {
            {$this->closedKeysConstant($planned->rules)}    public function authorize(): bool
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
            {$this->after($planned->rules)}}

            PHP,
        );
    }

    /**
     * The import block: the base class always, and `Rule` and `Validator` only
     * when a rule or the closed-root check uses them — an unused import is
     * something a consumer's formatter removes, and a formatter with something
     * to remove is a formatter fighting the next build.
     */
    private function useStatements(RuleSet $set): string
    {
        $imports = [$this->import(self::BASE_CLASS)];

        if ($set->usesInRule()) {
            $imports[] = $this->import(self::RULE_CLASS);
        }

        if ($set->closedKeys !== null) {
            $imports[] = $this->import(self::VALIDATOR_CLASS);
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
     * The constant the closed-root check reads, or nothing.
     */
    private function closedKeysConstant(RuleSet $set): string
    {
        if ($set->closedKeys === null) {
            return '';
        }

        return sprintf(
            "    /**\n     * The only top-level keys the contract's body declares, and the only ones it allows.\n     */\n"
                ."    private const BODY_KEYS = [%s];\n\n",
            implode(', ', array_map($this->literal(...), $set->closedKeys)),
        );
    }

    /**
     * The closed-root check, or nothing.
     *
     * **A closure, and one emitted shape.** Laravel's `#[FailOnUnknownFields]`
     * does this from 12.x on, but not on the lowest Laravel this package
     * supports, and an attribute behind a version gate with this closure
     * underneath it anyway would be two shapes that have to stay equivalent.
     *
     * **Top-level keys only, read from the body rather than from `all()`** —
     * the JSON bag, or the form bag and the uploaded files otherwise, and never
     * `getInputSource()`, which answers with the query string on a `GET` or a
     * `HEAD`. The files are a bag of their own, so a closed root that left them
     * out would let an undeclared upload through.
     * The query string is not the body, and a nested object that closes
     * itself already refuses its own extras through `array:` — so checking
     * deeper here would repeat that, and would refuse the extras of a nested
     * object that allows them.
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
                            $body = $this->isJson() ? $this->json()->all() : [...$this->request->all(), ...$this->files->all()];

                            foreach (array_keys($body) as $key) {
                                if (! in_array((string) $key, self::BODY_KEYS, true)) {
                                    $validator->errors()->add((string) $key, 'The '.$key.' field is not part of this contract.');
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
                $this->literal($key),
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
        ];
    }
}
