<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

use Gcob\LaraSpecFirst\Contract\Exceptions\InvalidPathTemplateException;

/**
 * A path as the specification writes it, plus the form used to identify it.
 *
 * The two are not the same, and the difference is a decision rather than a
 * convenience: **the names of path parameters are not part of an operation's
 * identity.** Renaming `/users/{id}` to `/users/{userId}` changes nothing a
 * client can observe — the URL on the wire is identical and the name is
 * documentation — so `$normalized` reduces every parameter to its position.
 *
 * That is what lets a rename be reported as a rename instead of as a deletion
 * and an addition, and what keeps such an edit out of a contract diff entirely.
 *
 * It also means `/users/{id}` and `/users/{slug}` normalize alike and collide.
 * That is correct: those two paths already collide in any router, so surfacing
 * it is a service rather than a limitation.
 *
 * @see docs/CODE-GENERATION.md — "Identity is the path and the method, not the name"
 */
final readonly class PathTemplate
{
    /**
     * @param  string  $template  the path exactly as the specification writes it
     * @param  list<string>  $parameterNames  in the order they appear
     * @param  string  $normalized  the same path with every parameter reduced to `{}`
     */
    private function __construct(
        public string $template,
        public array $parameterNames,
        public string $normalized,
    ) {}

    /**
     * @throws InvalidPathTemplateException
     */
    public static function fromString(string $template): self
    {
        if (! str_starts_with($template, '/')) {
            throw InvalidPathTemplateException::notAbsolute($template);
        }

        $names = self::parseParameterNames($template);
        $normalized = (string) preg_replace('/\{[^{}]*\}/', '{}', $template);

        return new self($template, $names, $normalized);
    }

    /**
     * Whether two paths address the same endpoint, whatever their parameters are
     * called.
     */
    public function isSameEndpointAs(self $other): bool
    {
        return $this->normalized === $other->normalized;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidPathTemplateException
     */
    private static function parseParameterNames(string $template): array
    {
        // Counted rather than matched, so that `{a{b}` and `/users/{id` are
        // rejected as unbalanced instead of quietly yielding whatever a lenient
        // pattern happens to find.
        if (substr_count($template, '{') !== substr_count($template, '}')) {
            throw InvalidPathTemplateException::unbalanced($template);
        }

        preg_match_all('/\{([^{}]*)\}/', $template, $matches, PREG_OFFSET_CAPTURE);

        $braces = substr_count($template, '{');
        if (count($matches[0]) !== $braces) {
            throw InvalidPathTemplateException::unbalanced($template);
        }

        $names = [];

        foreach ($matches[1] as [$name]) {
            if ($name === '') {
                throw InvalidPathTemplateException::emptyParameter($template);
            }

            if (in_array($name, $names, true)) {
                throw InvalidPathTemplateException::duplicateParameter($template, $name);
            }

            $names[] = $name;
        }

        return $names;
    }
}
