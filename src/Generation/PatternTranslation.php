<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * A JSON Schema `pattern` as a Laravel `regex:` rule, or the reason it cannot
 * be one.
 *
 * **JSON Schema specifies ECMA-262 and Laravel runs PCRE**, and most patterns
 * mean the same thing in both. Where they part company, a pattern is reported
 * rather than translated, because a rule that accepts what the contract refuses
 * — or refuses what it accepts — is a wrong answer where a report is a missing
 * one. Measured against the PHP this package runs on rather than reasoned
 * about:
 *
 * - **`\d`, `\w`, `\b` and their negations.** The `u` modifier every pattern
 *   needs, so that a multibyte character counts as one, also makes PCRE read
 *   these as Unicode classes: `/^\d$/u` matches `٣`. ECMA-262 reads them as
 *   ASCII. Reported.
 * - **Lookbehind.** PCRE accepts only a fixed-length one, ECMA-262 any length,
 *   so a pattern that compiles in one fails to in the other. Reported.
 * - **`$`.** PCRE's matches before a trailing newline, ECMA-262's only at the
 *   end, so `/^a$/` accepts `"a\n"`. The `D` modifier gives PCRE the ECMA-262
 *   reading, so this one is translated rather than reported.
 *
 * A pattern PCRE cannot compile at all is reported too, rather than emitted as
 * a rule that throws on every request.
 *
 * @see docs/guide/openapi-support.md — "Strings and numbers"
 */
final readonly class PatternTranslation
{
    private function __construct(
        public ?string $rule,
        public ?string $reason,
    ) {}

    public static function of(string $pattern): self
    {
        $divergent = self::divergentEscape($pattern);

        if ($divergent !== null) {
            return new self(null, sprintf(
                'uses `\\%s`, which PCRE reads as a Unicode class under the `u` modifier and ECMA-262 '
                    .'as ASCII',
                $divergent,
            ));
        }

        if (preg_match('/\(\?<[=!]/', $pattern) === 1) {
            return new self(null, 'uses a lookbehind, which PCRE accepts only at a fixed length');
        }

        $delimited = '/'.self::escapeDelimiter($pattern).'/uD';

        if (! self::compiles($delimited)) {
            return new self(null, 'does not compile as a PCRE pattern');
        }

        return new self('regex:'.$delimited, null);
    }

    /**
     * Whether PCRE compiles a pattern, asked without letting its warning out.
     *
     * A handler rather than `@`, because the `@` operator does not stop a
     * handler that turns warnings into exceptions — Laravel's does, and so
     * does the test runner's — and what this needs is the answer, which
     * becomes a finding, not the warning.
     */
    private static function compiles(string $delimited): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($delimited, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The first escape whose meaning differs between the two dialects, or null.
     *
     * Walked character by character rather than matched with one expression,
     * because `\\d` is a literal backslash followed by a `d` and only a walk
     * that consumes each escape whole tells the two apart.
     */
    private static function divergentEscape(string $pattern): ?string
    {
        $length = strlen($pattern);

        for ($at = 0; $at < $length - 1; $at++) {
            if ($pattern[$at] !== '\\') {
                continue;
            }

            $next = $pattern[$at + 1];

            if (in_array($next, ['d', 'D', 'w', 'W', 'b', 'B'], true)) {
                return $next;
            }

            $at++;
        }

        return null;
    }

    /**
     * Escape every `/` the pattern does not already escape, since `/` is the
     * delimiter the rule is written with.
     */
    private static function escapeDelimiter(string $pattern): string
    {
        $escaped = '';
        $length = strlen($pattern);

        for ($at = 0; $at < $length; $at++) {
            $character = $pattern[$at];

            if ($character === '\\' && $at + 1 < $length) {
                $escaped .= $character.$pattern[++$at];

                continue;
            }

            $escaped .= $character === '/' ? '\\/' : $character;
        }

        return $escaped;
    }
}
