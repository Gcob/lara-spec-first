<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\CommentText;
use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnwritableScaffoldException;

/**
 * Writes the one file this package creates that a developer will own.
 *
 * **Everything about it is the opposite of a generated file, deliberately.** No
 * marker, no "DO NOT EDIT", no provenance block, no findings: this file is the
 * developer's from the moment it exists, and a build must never touch it again.
 * What it carries instead is a comment about the one line they did not write —
 * the `extends` — and then it gets out of the way.
 *
 * **It writes `routeAction` with the signature the parent declares, and one line
 * in it.** Writing the method is what a developer expects from a `make`: the
 * signature is the fiddly part, PHP will not let them widen it, and copying it out
 * of a comment is work a generator should have done.
 *
 * **The one line is a call to the parent, and that is not decoration.** A method
 * with a genuinely empty body returns `null`, which Laravel renders as an empty
 * `200` — so a scaffold with an empty body would quietly turn the operation's
 * honest `501` into a lie, in the one command whose whole job is to help. The
 * parent call keeps the 501 until the developer replaces it, and replacing it is
 * exactly what implementing the operation means.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 * @see docs/guide/code-generation/generated-file-anatomy.md — "A reference to generated code says what to do when it goes missing"
 */
final readonly class CustomControllerScaffold
{
    private CustomControllerLookup $lookup;

    public function __construct(?CustomControllerLookup $lookup = null)
    {
        $this->lookup = $lookup ?? CustomControllerLookup::fromAutoloader();
    }

    /**
     * Create the file, and never overwrite one.
     *
     * @throws UnwritableScaffoldException
     */
    public function write(PlannedScaffold $scaffold): void
    {
        // Checked here rather than trusting the plan's answer. Planning happens
        // before a confirmation prompt, so a human has had time to create the
        // file in another window — and overwriting a file its owner just wrote is
        // the one failure this command cannot be allowed to have.
        //
        // Asked of the lookup rather than of `$scaffold->path` with `is_file()`:
        // that path is the single longest-prefix-first candidate, while a PSR-4
        // prefix that maps two directories can have the class already written in
        // the second one. `is_file()` on the one path would miss it and this
        // write would shadow the developer's own class with a fresh stub.
        if ($this->lookup->exists($scaffold->class)) {
            throw UnwritableScaffoldException::alreadyThere($scaffold->path);
        }

        $directory = dirname($scaffold->path);

        // The mode is `0777` because the umask decides, not this number — the
        // same reasoning the generated tree's writer states at length, and the
        // same value Laravel's own generators pass.
        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw UnwritableScaffoldException::directory($directory);
        }

        if (file_put_contents($scaffold->path, $this->contents($scaffold)) === false) {
            throw UnwritableScaffoldException::file($scaffold->path);
        }
    }

    /**
     * The PHP of one scaffolded controller.
     */
    public function contents(PlannedScaffold $scaffold): string
    {
        $namespace = $this->namespaceOf($scaffold->class);
        $lines = ['<?php', '', 'declare(strict_types=1);', ''];

        if ($namespace !== null) {
            $lines[] = 'namespace '.$namespace.';';
            $lines[] = '';
        }

        foreach ($this->missingParentNote() as $line) {
            $lines[] = $line;
        }

        // The parent is named in full, in the `extends` clause, rather than
        // imported. Both classes share a short name — that is what the two-class
        // seam is — so an import would force `use …\Generated\UserController as
        // GeneratedUserController` into a file the developer owns. Writing the
        // name where it is used costs one line and reads as what it is.
        $lines[] = 'class '.$this->shortNameOf($scaffold->class).' extends \\'.$scaffold->parent;
        $lines[] = '{';

        foreach ($this->body($scaffold->operation) as $line) {
            $lines[] = '    '.$line;
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * What to do when PHP cannot find the parent.
     *
     * **Written once, by the command that creates the file, and never again.** The
     * build [never writes outside its own directories](../../docs/guide/code-generation/index.md),
     * and that rule has no exception for a helpful comment — so from here on the
     * comment belongs to the developer, including the freedom to delete it.
     *
     * Three lines because three situations sit behind them, and the first answer
     * is a command rather than an explanation: the build has not run here, the
     * `x-controller` no longer points at this class, or the operation is gone.
     *
     * @return list<string>
     */
    private function missingParentNote(): array
    {
        return [
            '// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.',
            '// If it still fails, the specification no longer has an `x-controller` pointing here.',
        ];
    }

    /**
     * The method, its signature, and the line that keeps the operation honest until
     * it is replaced.
     *
     * The parameters are the path's own, named as the document names them, because
     * that is what the parent declares and PHP forbids an override from widening —
     * so this is not a suggestion the developer may reword.
     *
     * @return list<string>
     */
    private function body(Operation $operation): array
    {
        $parameters = $operation->path->parameterNames;

        $signature = 'public function routeAction('.implode(', ', array_map(
            static fn (string $parameter): string => 'string $'.$parameter,
            $parameters,
        )).'): mixed';

        $comment = array_map(
            static fn (string $line): string => '    // '.$line,
            CommentText::wrap(sprintf(
                'Replace this line with your answer to `%s`.',
                CommentText::safe($operation->label()),
            ), 84),
        );

        return [
            $signature,
            '{',
            ...$comment,
            '    return parent::routeAction('.implode(', ', array_map(
                static fn (string $parameter): string => '$'.$parameter,
                $parameters,
            )).');',
            '}',
        ];
    }

    private function namespaceOf(string $class): ?string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? null : substr($class, 0, $separator);
    }

    private function shortNameOf(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
