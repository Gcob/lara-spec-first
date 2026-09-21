<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\OperationNotImplementedException;
use Gcob\LaraSpecFirst\Http\Controllers\SpecController;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/**
 * Turns one operation into the PHP of its generated controller.
 *
 * **Every file it emits explains itself**, and that is a requirement rather than
 * a courtesy. A banner saying "generated, do not edit" answers who owns the file,
 * which is settled elsewhere, and it is not what a reader opening the file
 * actually needs. Three things are: where this came from, what the build worked
 * out while emitting it, and where to go next.
 *
 * The audience is deliberately both a person and a coding agent. An agent reads a
 * handful of files rather than a codebase, cannot infer a convention from ten
 * sibling examples, and has no way to know that the interesting behavior lives
 * three directories away unless the file says so. Every guess it has to make is a
 * chance to write something plausible and wrong into an application.
 *
 * @see docs/guide/code-generation/generated-file-anatomy.md — "Every generated file explains itself"
 * @see docs/guide/controllers.md — "One controller, one routeAction"
 */
final readonly class ControllerEmitter
{
    public function __construct(
        private string $namespace,
        private string $specPath,
    ) {}

    public function emit(PlannedController $planned): GeneratedFile
    {
        $operation = $planned->operation;

        return new GeneratedFile(
            $planned->relativePath(),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace}\\Controllers;

            {$this->imports($planned)}

            {$this->docblock($planned)}
            {$this->modifier($planned)}class {$planned->name->shortName} extends SpecController
            {
                public function routeAction({$this->signature($planned)}): mixed
                {
                    throw OperationNotImplementedException::operation({$this->literal($operation->label())}, {$this->name($operation)});
                }
            }

            PHP,
        );
    }

    /**
     * Every `use` the emitted file needs, in the order Pint sorts them.
     *
     * Sorted here rather than left to the formatter, because a consumer whose
     * formatter has something to reorder is a formatter fighting the next
     * build. `Illuminate\\` sorts before `Gcob\\` on neither rule, so the list is
     * built and sorted rather than written in a fixed order.
     */
    private function imports(PlannedController $planned): string
    {
        $imports = [
            $this->import(OperationNotImplementedException::class),
            $this->import(SpecController::class),
        ];

        if ($planned->request !== null) {
            $imports[] = $this->import($planned->request->fullyQualifiedName($this->namespace));
        }

        sort($imports);

        return implode("\n", array_map(static fn (string $class): string => 'use '.$class.';', $imports));
    }

    /**
     * What `routeAction` declares, from the one place both sides read it.
     *
     * @see RouteActionSignature for why it cannot be empty, and why the request comes first
     */
    private function signature(PlannedController $planned): string
    {
        return RouteActionSignature::declaration(
            $planned->operation,
            $planned->request?->shortName,
        );
    }

    /**
     * `final `, or nothing at all.
     *
     * **The one place the extendability rule turns into syntax.** A class whose
     * name nobody chose is disposable, and `final` is what stops an `extends`
     * being built on top of something disposable — so the modifier follows
     * {@see NameSource} rather than a second rule that could disagree with it.
     *
     * @see docs/guide/controllers.md — "The contract decides what is customizable"
     */
    private function modifier(PlannedController $planned): string
    {
        return $planned->name->isExtendable() ? '' : 'final ';
    }

    /**
     * How `spec:make` would be told which operation this is, as PHP.
     *
     * `null` rather than an empty string when the operation has no `operationId`:
     * the 501 body names the command to run, and it has to fall back to the
     * method and path rather than print an invocation with nothing after it.
     */
    private function name(Operation $operation): string
    {
        return $operation->operationId === null ? 'null' : $this->literal($operation->operationId);
    }

    /**
     * A value as a PHP string literal, whatever it contains.
     *
     * `var_export` rather than wrapping in quotes: a path may legally carry an
     * apostrophe or end in a backslash, and either turns a hand-quoted literal
     * into PHP that does not parse.
     */
    private function literal(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * A fully-qualified name with no leading separator, for a `use` statement.
     */
    private function import(string $class): string
    {
        return ltrim($class, '\\');
    }

    private function docblock(PlannedController $planned): string
    {
        return GeneratedDocblock::render(
            [
                CommentText::safe($this->specPath),
                CommentText::safe($this->pointer($planned->operation)),
            ],
            $this->findings($planned),
            $this->navigation($planned),
        );
    }

    /**
     * Where the class name came from, and what that costs or buys.
     *
     * Reported rather than left implicit, because it is what decides whether
     * anything may extend this class — a fact a reader cannot recover from the
     * name itself.
     */
    private function nameFinding(PlannedController $planned): string
    {
        return match ($planned->name->source) {
            NameSource::CustomController => 'Class name taken from `x-controller`, the one value in the '
                .'contract whose only job is to name this class. Nothing else in the document can move '
                .'it, which is what makes this class safe to extend — and why it is not `final`.',
            NameSource::OperationId => 'Class name taken from the operation\'s `operationId`. Declare '
                .'`x-controller` to name a class of your own and make this one extendable.',
            NameSource::Derived => 'Class name derived from the method and path, because the operation '
                .'declares no `operationId`. A derived name is disposable, which is why this class is '
                .'`final`.',
        };
    }

    /**
     * The JSON pointer into the document, so a reader lands on the exact position
     * rather than grepping for the path.
     */
    private function pointer(Operation $operation): string
    {
        return DocumentPointer::forOperation($operation);
    }

    /**
     * Where to go from here.
     *
     * **The rule is to point at what actually runs**, which for an operation with
     * a custom controller is not this file. A reader — human or agent — who lands
     * on a generated default that has been overridden is the single most common
     * way to misread a codebase like this one, and one annotation removes the
     * mistake entirely.
     *
     * @return non-empty-list<string>
     */
    private function navigation(PlannedController $planned): array
    {
        $custom = $planned->name->customController;

        if ($custom === null) {
            return ['@see '.GeneratedRoutesLocator::FILE.' — the route that reaches this class'];
        }

        if ($planned->customControllerExists) {
            return [
                // Backticked for the reason {@see FormRequestEmitter} states
                // at length: Pint rewrites a bare fully-qualified name in a
                // docblock into an import plus a short name, and here that
                // import would carry the *child's* name into the parent — two
                // classes that share a short name by design, so the file would
                // stop loading. It survived this long only because the
                // collision is what stopped the fixer.
                '@see `\\'.CommentText::safe($custom).'` — the class that extends this one, and',
                '     what the route actually reaches',
                '@see '.GeneratedRoutesLocator::FILE.' — that route',
            ];
        }

        // The command is named rather than the extension point merely described.
        // Discovering that a class can be extended should not require reading this
        // package's documentation first — and `spec:make` creates exactly this
        // file, so printing the invocation is the shortest true thing to say.
        $lines = CommentText::wrap(sprintf(
            'The contract names `%s` as this operation\'s controller, and no file for it exists '
                .'yet. Create it with `php artisan spec:make %s`. Until it exists, the route reaches '
                .'this class and answers 501.',
            CommentText::safe($custom),
            $planned->operation->operationId ?? '"'.CommentText::safe($planned->operation->label()).'"',
        ));

        $lines[] = '';
        $lines[] = '@see '.GeneratedRoutesLocator::FILE.' — the route that reaches this class';

        return $lines;
    }

    /**
     * What the build knew and the reader cannot see.
     *
     * Findings are the part that has to stay honest. A summary that says nothing
     * costs a reader the time it takes to discover that, so what earns its place
     * is a default that was resolved rather than declared, a name derived because
     * `operationId` was absent, or something the build read and does not act on.
     *
     * @return non-empty-list<string>
     */
    private function findings(PlannedController $planned): array
    {
        $operation = $planned->operation;

        $findings = [
            $this->nameFinding($planned),
            sprintf(
                'Audience `%s`%s. Effective values: an absent extension is already resolved to its '
                    .'default here, so this says what applies rather than what was written.',
                $operation->audience->value,
                $operation->lifecycle === null
                    ? ', no lifecycle claim'
                    : ', lifecycle `'.$operation->lifecycle->value.'`',
            ),
            $planned->routesToCustomController()
                ? 'The route reaches the custom controller rather than this class, so what answers '
                    .'this operation is that class. This one is the generated half, and it is '
                    .'rewritten on every build.'
                : 'Nothing implements this operation, so it answers 501. That is the honest answer '
                    .'while the contract describes an endpoint and no code does.',
            'The return type is `mixed` because no response schema is read yet. It is deliberately '
                .'not `never`, which would be accurate today and would forbid every future override.',
        ];

        if ($operation->deprecated) {
            $findings[] = $operation->sunset === null
                ? 'Marked `deprecated` with no `x-sunset`, so the contract states no removal date.'
                : 'Marked `deprecated`, to be removed on '.$operation->sunset.'.';
        }

        if ($operation->security !== null && $operation->security !== []) {
            $findings[] = sprintf(
                'Declares %d security requirement(s), which this build reads and does not enforce '
                    .'yet. Until it does, this endpoint is not protected by anything this package put '
                    .'there.',
                count($operation->security),
            );
        }

        return $findings;
    }
}
