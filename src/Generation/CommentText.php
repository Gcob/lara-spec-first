<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Makes a value taken from the specification safe to put inside a PHP comment.
 *
 * **Every string a generated docblock takes from the document goes through here.**
 * A specification is data, and a generated file is code: the boundary between the
 * two is exactly where injection lives, and a docblock is the easy half to forget
 * because nothing about a comment looks executable.
 *
 * It is not. A `*\/` sequence closes a block comment — written escaped here for
 * the reason this class exists — and a value containing one produces a
 * file that is worse than broken: the docblock ends early, whatever follows
 * becomes a statement, and the docblock's own closing delimiter conveniently reopens
 * and closes a comment around the rest, so the file **parses, loads and runs**.
 * Verified rather than reasoned about: a specification with
 * `x-sunset` carrying that sequence followed by `echo` produced a controller that printed when
 * autoloaded. A contract handed over by another team, reviewed like documentation
 * rather than like code, is enough to execute anything in the application that
 * generated from it.
 *
 * Newlines are collapsed for the same reason a wrapped finding is wrapped: a raw
 * newline breaks out of the ` * ` prefix and turns one line of a comment into
 * something a reader has to interpret.
 *
 * @see docs/guide/code-generation.md — "A specification is data, and generated code is code"
 */
final readonly class CommentText
{
    public static function safe(string $value): string
    {
        // `*\/` rather than dropping the characters: it stays readable as what it
        // was, and it cannot terminate the comment. Escaping a slash inside a
        // comment is meaningless to PHP, which is precisely why it is safe.
        $neutralized = str_replace('*/', '*\/', $value);

        return trim((string) preg_replace('/\s+/', ' ', $neutralized));
    }
}
