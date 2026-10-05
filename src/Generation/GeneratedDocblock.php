<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * The header every generated class carries, assembled once.
 *
 * **Every file the build emits explains itself**, and that is a requirement
 * rather than a courtesy. A banner saying "generated, do not edit" answers who
 * owns the file, which is settled elsewhere, and it is not what a reader
 * opening the file actually needs. Three things are: where this came from, what
 * the build worked out while emitting it, and where to go next.
 *
 * The audience is deliberately both a person and a coding agent. An agent reads
 * a handful of files rather than a codebase, cannot infer a convention from ten
 * sibling examples, and has no way to know that the interesting behavior lives
 * three directories away unless the file says so. Every guess it has to make is
 * a chance to write something plausible and wrong into an application.
 *
 * Here rather than in one emitter because the second emitter arrived: a
 * controller and a request carry the same three sections, and two copies of the
 * whitespace rules below would be two chances for a consumer's formatter to
 * find something to change in one of them.
 *
 * @see docs/guide/code-generation/generated-file-anatomy.md — "Every generated file explains itself"
 */
final readonly class GeneratedDocblock
{
    /**
     * @param  non-empty-list<string>  $provenance  where this came from, one line
     *                                              each and already escaped
     * @param  non-empty-list<string>  $findings  what the build knew and the
     *                                            reader cannot see, wrapped here
     * @param  non-empty-list<string>  $navigation  where to go next, already
     *                                              wrapped; an empty string is a
     *                                              blank line inside the block
     */
    public static function render(array $provenance, array $findings, array $navigation): string
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
        ];

        foreach ($provenance as $line) {
            $lines[] = ' *   '.$line;
        }

        $lines[] = ' *';
        $lines[] = ' * Findings';

        foreach ($findings as $finding) {
            foreach (CommentText::wrap(CommentText::safe($finding)) as $position => $line) {
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

        foreach ($navigation as $line) {
            // A blank line inside the block is ` *` and never ` *   `: trailing
            // whitespace in a comment is something `no_trailing_whitespace_in_comment`
            // strips, and a formatter that has something to strip is a formatter
            // fighting the next build.
            $lines[] = $line === '' ? ' *' : ' *   '.$line;
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }
}
