<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

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
 * @see docs/guide/code-generation.md — "Every generated file explains itself"
 * @see docs/guide/controllers.md — "One controller per operation, one method named routeAction"
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

            use {$this->import(OperationNotImplementedException::class)};
            use {$this->import(SpecController::class)};

            {$this->docblock($planned)}
            {$this->modifier($planned)}class {$planned->name->shortName} extends SpecController
            {
                public function routeAction({$this->signature($operation)}): mixed
                {
                    throw OperationNotImplementedException::operation({$this->literal($operation->label())});
                }
            }

            PHP,
        );
    }

    /**
     * The parameters `routeAction` declares: the path's own, named as the
     * specification names them.
     *
     * **This is the signature a child has to match, which is why it cannot be
     * empty.** PHP forbids an override from adding a required parameter, so a
     * parent declaring none would make `x-controller` useless on every templated
     * path — a developer could only reach `{id}` through the request object,
     * which is the opposite of what a generated seam is for. Verified rather than
     * reasoned about: a Workbench child declaring `routeAction(string $id)` over a
     * parameterless parent is a fatal error at load.
     *
     * **Named, because Laravel matches route parameters to method parameters by
     * name** rather than by position. The specification's spelling is therefore
     * load-bearing here in a way it is not anywhere else, and renaming `{id}` to
     * `{userId}` changes this signature — which a child overriding it must follow.
     *
     * `string` because that is what a route parameter is until something says
     * otherwise. `x-model` is what will turn one into a bound model, and the
     * parent will declare that type when it does.
     */
    private function signature(Operation $operation): string
    {
        return implode(', ', array_map(
            static fn (string $parameter): string => 'string $'.$parameter,
            $operation->path->parameterNames,
        ));
    }

    /**
     * `final `, or nothing at all.
     *
     * **The one place the extendability rule turns into syntax.** A class whose
     * name nobody chose is disposable, and `final` is what stops an `extends`
     * being built on top of something disposable — so the modifier follows
     * {@see NameSource} rather than a second rule that could disagree with it.
     *
     * @see docs/guide/controllers.md — "The specification decides what is customizable"
     */
    private function modifier(PlannedController $planned): string
    {
        return $planned->name->isExtendable() ? '' : 'final ';
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
        $lines = [
            '/**',
            ' * '.GeneratedFile::MARKER.'. DO NOT EDIT.',
            ' *',
            ' * Rewritten from scratch on every `php artisan spec:build`, so an edit here is gone on',
            ' * the next run. That is the design rather than a caveat: the generated side has to be',
            ' * free to change shape, and it can only be free if nobody has hand-edits in it to',
            ' * protect.',
            ' *',
            ' * Provenance',
            ' *   '.CommentText::safe($this->specPath),
            ' *   '.CommentText::safe($this->pointer($planned->operation)),
            ' *',
            ' * Findings',
        ];

        foreach ($this->findings($planned) as $finding) {
            foreach ($this->wrap(CommentText::safe($finding)) as $position => $line) {
                $lines[] = $position === 0 ? ' *   - '.$line : ' *     '.$line;
            }
        }

        $lines[] = ' *';
        $lines[] = ' * Navigation';
        // The blank line before the annotation is not decoration. `phpdoc_separation`,
        // which ships in Pint's Laravel preset and in php-cs-fixer's defaults, inserts
        // one here — so emitting it means a consumer's formatter finds nothing to change.
        // Without it the formatter and the build rewrite each other forever, and the
        // idempotence this package promises would hold only for projects that format
        // nothing.
        $lines[] = ' *';

        foreach ($this->navigation($planned) as $line) {
            // A blank line inside the block is ` *` and never ` *   `: trailing
            // whitespace in a comment is something `no_trailing_whitespace_in_comment`
            // strips, and a formatter that has something to strip is a formatter
            // fighting the next build.
            $lines[] = $line === '' ? ' *' : ' *   '.$line;
        }

        $lines[] = ' */';

        return implode("\n", $lines);
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
     * Break a finding across lines the way the rest of this repository writes.
     *
     * Generated code is read far more than it is written, and a docblock whose
     * lines run to three hundred characters is one nobody reads twice. Wrapped
     * here rather than left to a formatter, because a consumer's formatter is not
     * ours to assume and the build has to be [idempotent](GeneratedTree) — output
     * that another tool then reformats would produce a diff on every run.
     *
     * Measured in characters rather than bytes, because a finding interpolates
     * values that come from the document: a non-ASCII `x-sunset` or summary would
     * otherwise wrap early for a width nobody asked for.
     *
     * **A token longer than the line is cut rather than left to run**, and that
     * case is reachable rather than theoretical: a finding interpolates document
     * values, `x-sunset` is [deliberately unparsed](../Contract/Operation.php), and
     * a URL or a hand-typed value carrying no space at all would otherwise produce
     * a single line hundreds of characters long. Cut rather than truncated, because
     * the value is what a reader came here for and dropping its tail would make
     * the finding lie by omission.
     *
     * @param  positive-int  $width
     * @return non-empty-list<string>
     */
    private function wrap(string $finding, int $width = 92): array
    {
        $lines = [];
        $current = '';

        foreach ($this->words($finding, $width) as $word) {
            if ($current === '') {
                $current = $word;

                continue;
            }

            if (mb_strlen($current) + 1 + mb_strlen($word) > $width) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current .= ' '.$word;
        }

        $lines[] = $current;

        return $lines;
    }

    /**
     * The finding's words, with any word too long for a line cut into pieces that
     * fit.
     *
     * @param  positive-int  $width
     * @return list<string>
     */
    private function words(string $finding, int $width): array
    {
        $words = [];

        foreach (explode(' ', $finding) as $word) {
            if ($word === '') {
                continue;
            }

            foreach (mb_str_split($word, $width) as $piece) {
                $words[] = $piece;
            }
        }

        return $words;
    }

    /**
     * The JSON pointer into the document, so a reader lands on the exact position
     * rather than grepping for the path.
     */
    private function pointer(Operation $operation): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $operation->path->template);

        return '#/paths/'.$escaped.'/'.$operation->method->value;
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
                '@see \\'.CommentText::safe($custom).' — the class that extends this one, and',
                '     what the route actually reaches',
                '@see '.GeneratedRoutesLocator::FILE.' — that route',
            ];
        }

        return [
            'The contract names `'.CommentText::safe($custom).'` as this operation\'s',
            'controller, and no file for it exists yet. Until one does, the route reaches this',
            'class and answers 501.',
            '',
            '@see '.GeneratedRoutesLocator::FILE.' — the route that reaches this class',
        ];
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
