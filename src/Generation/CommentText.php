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
 * @see docs/guide/code-generation/index.md — "A specification is data, and generated code is code"
 */
final readonly class CommentText
{
    /**
     * Break a line of comment text the way this repository writes.
     *
     * **Wrapped here rather than left to a formatter**, because a consumer's
     * formatter is not ours to assume and both callers have to be idempotent:
     * output another tool then reformats produces a diff on every run. It is
     * shared because two emitters need it — the generated controller's docblock
     * and the scaffolded controller's body — and a second copy would be a second
     * width to keep in step.
     *
     * Measured in characters rather than bytes, because the text interpolates
     * values that come from the document: a non-ASCII summary would otherwise
     * wrap early for a width nobody asked for.
     *
     * @param  positive-int  $width
     * @return non-empty-list<string>
     */
    public static function wrap(string $text, int $width = 92): array
    {
        $lines = [];
        $current = '';

        foreach (self::words($text, $width) as $word) {
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

    public static function safe(string $value): string
    {
        // `*\/` rather than dropping the characters: it stays readable as what it
        // was, and it cannot terminate the comment. Escaping a slash inside a
        // comment is meaningless to PHP, which is precisely why it is safe.
        $neutralized = str_replace('*/', '*\/', $value);

        return trim((string) preg_replace('/\s+/', ' ', $neutralized));
    }

    /**
     * The text's words, with any word too long for a line cut into pieces that
     * fit.
     *
     * **A token longer than the line is cut rather than left to run**, and that
     * case is reachable rather than theoretical: the text interpolates document
     * values, `x-sunset` is deliberately unparsed, and a URL or a hand-typed
     * value carrying no space at all would otherwise produce a single line
     * hundreds of characters long. Cut rather than truncated, because the value is
     * what a reader came here for and dropping its tail would make the comment lie
     * by omission.
     *
     * @param  positive-int  $width
     * @return list<string>
     */
    private static function words(string $text, int $width): array
    {
        $words = [];

        foreach (explode(' ', $text) as $word) {
            if ($word === '') {
                continue;
            }

            foreach (mb_str_split($word, $width) as $piece) {
                $words[] = $piece;
            }
        }

        return $words;
    }
}
