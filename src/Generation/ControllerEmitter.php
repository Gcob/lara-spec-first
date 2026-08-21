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
            final class {$planned->name->shortName} extends SpecController
            {
                public function routeAction(): mixed
                {
                    throw OperationNotImplementedException::operation({$this->literal($operation->label())});
                }
            }

            PHP,
        );
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
        $lines[] = ' *   @see '.GeneratedRoutesLocator::FILE.' — the route that reaches this class';
        $lines[] = ' */';

        return implode("\n", $lines);
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
     * @return non-empty-list<string>
     */
    private function wrap(string $finding, int $width = 92): array
    {
        $lines = [];
        $current = '';

        foreach (explode(' ', $finding) as $word) {
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
     * The JSON pointer into the document, so a reader lands on the exact position
     * rather than grepping for the path.
     */
    private function pointer(Operation $operation): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $operation->path->template);

        return '#/paths/'.$escaped.'/'.$operation->method->value;
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
            $planned->name->wasDeclared
                ? 'Class name taken from the operation\'s `operationId`.'
                : 'Class name derived from the method and path, because the operation declares no '
                    .'`operationId`. A derived name is disposable, which is why this class is `final`.',
            sprintf(
                'Audience `%s`%s. Effective values: an absent extension is already resolved to its '
                    .'default here, so this says what applies rather than what was written.',
                $operation->audience->value,
                $operation->lifecycle === null
                    ? ', no lifecycle claim'
                    : ', lifecycle `'.$operation->lifecycle->value.'`',
            ),
            'Nothing implements this operation, so it answers 501. That is the honest answer while '
                .'the contract describes an endpoint and no code does.',
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
