<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use PhpToken;

/**
 * What a custom controller's file says about its `routeAction`, read from the source and never from the class.
 *
 * **The file is tokenized, not loaded.** A child whose `routeAction` no longer fits its regenerated parent is a
 * compile-time fatal: loading it to ask would end the build that is trying to name it. Nothing here calls
 * `class_exists()`, reflects on, or requires the file, and a class this reader cannot be sure about is a class it stays
 * silent on. A false alarm on a file the developer owns costs more than a missed one, which PHP reports anyway.
 *
 * **It speaks only when sure.** `extends` is resolved the way PHP resolves it (fully qualified, through a top-level
 * `use` with or without `as`, relative to the namespace of the file), and null when it cannot be: a group `use` hides
 * which names it binds, so a name that might come from one is not guessed at.
 *
 * **A count, never a type.** What PHP refuses to load is decided by the signature as a whole; the count is the part
 * that is sound without resolving a single class, since it never flags an override PHP accepts.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */
final readonly class RouteActionOverride
{
    /**
     * @param  string|null  $extends  the parent class, fully qualified and without a leading separator, or null when
     *                                the class extends nothing or the name cannot be resolved from the file alone
     * @param  int  $parameters  how many parameters `routeAction` declares, a variadic one included
     * @param  int  $required  how many a caller has to pass: up to the last parameter with no default, since PHP reads
     *                         a default that precedes a required parameter as required too
     * @param  bool  $variadic  whether the last parameter collects the rest
     */
    private function __construct(
        public ?string $extends,
        public int $parameters,
        public int $required,
        public bool $variadic,
    ) {}

    /**
     * The `routeAction` the class declares in its own body, or null when the file has no such class or the class
     * declares none.
     *
     * **A method of the class and nothing nested in it.** An anonymous class inside another method, a closure, a
     * comment and a string all sit deeper than the class body or are not code, and a second class in the same file is
     * not the one asked about.
     *
     * @param  string  $source  the file's contents
     * @param  string  $class  the fully qualified class the file is expected to declare
     */
    public static function read(string $source, string $class): ?self
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $wanted = ltrim($class, '\\');
        $namespace = '';
        $imports = [];
        $group = false;
        $depth = 0;
        $namespaceDepth = 0;

        for ($at = 0, $count = count($tokens); $at < $count; $at++) {
            $token = $tokens[$at];

            if ($token->id === T_NAMESPACE && $depth === $namespaceDepth) {
                $name = $tokens[$at + 1] ?? null;
                $namespace = $name !== null && self::isName($name) ? $name->text : '';
                $imports = [];
                $group = false;

                if (($tokens[$at + 2] ?? null)?->text === '{') {
                    $namespaceDepth = 1;
                }

                continue;
            }

            if ($token->id === T_USE && $depth === $namespaceDepth && self::startsAnImport($tokens[$at + 1] ?? null)) {
                [$at, $group] = self::readImport($tokens, $at, $imports, $group);

                continue;
            }

            if (self::opens($token)) {
                $depth++;

                continue;
            }

            if (self::closes($token)) {
                $depth--;

                if ($depth === 0 && $namespaceDepth === 1) {
                    $namespace = '';
                    $imports = [];
                    $group = false;
                    $namespaceDepth = 0;
                }

                continue;
            }

            if ($token->id !== T_CLASS || ($tokens[$at - 1] ?? null)?->id === T_DOUBLE_COLON) {
                continue;
            }

            $name = $tokens[$at + 1] ?? null;

            if ($name === null || $name->id !== T_STRING) {
                continue;
            }

            $declared = $namespace === '' ? $name->text : $namespace.'\\'.$name->text;

            if (strcasecmp($declared, $wanted) !== 0) {
                continue;
            }

            return self::readClass($tokens, $at + 2, $namespace, $imports, $group);
        }

        return null;
    }

    /**
     * Whether PHP would load this override over a parent declaring that many parameters, by count alone.
     *
     * **Refused when the child accepts fewer than the parent declares (and cannot collect the rest), or requires more
     * than the parent passes.** Measured on PHP 8.5 against a parent of a request and an id: `(string $id)` and
     * `(R $request, string $id, $x)` are fatal at load, `(...$a)`, `(R $request, string $id, $x = 1)` and
     * `(object $request, string $id)` load. A type is never judged here, so `true` means the count is not what PHP
     * refuses.
     */
    public function fits(int $parentParameters): bool
    {
        return ($this->variadic || $this->parameters >= $parentParameters)
            && $this->required <= $parentParameters;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  int  $from  the first token after the class name
     * @param  array<string, string>  $imports  lower-cased alias to fully qualified name
     */
    private static function readClass(array $tokens, int $from, string $namespace, array $imports, bool $group): ?self
    {
        $extends = null;
        $at = $from;

        for ($count = count($tokens); $at < $count && $tokens[$at]->text !== '{'; $at++) {
            if ($tokens[$at]->id === T_EXTENDS) {
                $name = $tokens[$at + 1] ?? null;
                $extends = $name !== null ? self::resolve($name, $namespace, $imports, $group) : null;
            }
        }

        $depth = 0;

        for ($count = count($tokens); $at < $count; $at++) {
            $token = $tokens[$at];

            if (self::opens($token)) {
                $depth++;
            } elseif (self::closes($token)) {
                $depth--;

                if ($depth === 0) {
                    return null;
                }
            } elseif ($depth === 1 && $token->id === T_FUNCTION) {
                $name = $tokens[$at + 1] ?? null;

                // Returned by reference: `function &routeAction()`.
                if ($name?->text === '&') {
                    $name = $tokens[$at + 2] ?? null;
                    $at++;
                }

                if ($name?->id === T_STRING && strcasecmp($name->text, 'routeAction') === 0
                    && ($tokens[$at + 2] ?? null)?->text === '(') {
                    return self::readParameters($tokens, $at + 2, $extends);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  int  $open  the position of the opening parenthesis
     */
    private static function readParameters(array $tokens, int $open, ?string $extends): self
    {
        $segments = [[]];
        $depth = 1;

        for ($at = $open + 1, $count = count($tokens); $at < $count; $at++) {
            $token = $tokens[$at];

            if (self::opens($token)) {
                $depth++;
            } elseif (self::closes($token)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $token->text === ',') {
                $segments[] = [];

                continue;
            }

            $segments[array_key_last($segments)][] = $token;
        }

        // A trailing comma leaves an empty segment, which is no parameter.
        $segments = array_values(array_filter($segments, static fn (array $segment): bool => $segment !== []));

        $variadic = false;
        $required = 0;

        foreach ($segments as $index => $segment) {
            [$isVariadic, $hasDefault] = self::describe($segment);

            $variadic = $variadic || $isVariadic;

            if (! $isVariadic && ! $hasDefault) {
                $required = $index + 1;
            }
        }

        return new self($extends, count($segments), $required, $variadic);
    }

    /**
     * @param  list<PhpToken>  $segment  the tokens of one parameter
     * @return array{bool, bool} whether it collects the rest, and whether it has a default
     */
    private static function describe(array $segment): array
    {
        $depth = 0;
        $variadic = false;
        $default = false;

        foreach ($segment as $token) {
            if (self::opens($token)) {
                $depth++;
            } elseif (self::closes($token)) {
                $depth--;
            } elseif ($depth === 0 && $token->id === T_ELLIPSIS) {
                $variadic = true;
            } elseif ($depth === 0 && $token->text === '=') {
                $default = true;
            }
        }

        return [$variadic, $default];
    }

    /**
     * Reads one top-level `use` statement, up to its semicolon.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports  lower-cased alias to fully qualified name, added to
     * @return array{int, bool} the position of the semicolon, and whether a group `use` has been seen
     */
    private static function readImport(array $tokens, int $at, array &$imports, bool $group): array
    {
        $statement = [[]];

        for ($at++, $count = count($tokens); $at < $count && $tokens[$at]->text !== ';'; $at++) {
            $token = $tokens[$at];

            // A brace or a trailing separator means a group: which names it binds is not read.
            if ($token->text === '{' || $token->id === T_NS_SEPARATOR) {
                return [self::skipToSemicolon($tokens, $at), true];
            }

            if ($token->text === ',') {
                $statement[] = [];

                continue;
            }

            $statement[array_key_last($statement)][] = $token;
        }

        foreach ($statement as $parts) {
            $name = $parts[0] ?? null;

            if ($name === null || ! self::isName($name)) {
                continue;
            }

            $alias = count($parts) === 3 && $parts[1]->id === T_AS
                ? $parts[2]->text
                : basename(str_replace('\\', '/', $name->text));

            $imports[strtolower($alias)] = ltrim($name->text, '\\');
        }

        return [$at, $group];
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function skipToSemicolon(array $tokens, int $at): int
    {
        for ($count = count($tokens); $at < $count && $tokens[$at]->text !== ';'; $at++) {
            // Nothing to read: the position is the answer.
        }

        return $at;
    }

    /**
     * A class `use`, not `use function`, `use const`, a closure's `use (...)` or a trait's `use`.
     */
    private static function startsAnImport(?PhpToken $next): bool
    {
        return $next !== null && self::isName($next);
    }

    /**
     * @param  array<string, string>  $imports  lower-cased alias to fully qualified name
     */
    private static function resolve(PhpToken $name, string $namespace, array $imports, bool $group): ?string
    {
        if (! self::isName($name)) {
            return null;
        }

        if ($name->id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name->text, '\\');
        }

        if ($name->id === T_NAME_RELATIVE) {
            $rest = substr($name->text, strlen('namespace\\'));

            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
        }

        $separator = strpos($name->text, '\\');
        $first = strtolower($separator === false ? $name->text : substr($name->text, 0, $separator));

        if (isset($imports[$first])) {
            return $imports[$first].($separator === false ? '' : substr($name->text, $separator));
        }

        // A group `use` may bind this name, and which names it binds is not read.
        if ($group) {
            return null;
        }

        return $namespace === '' ? $name->text : $namespace.'\\'.$name->text;
    }

    private static function isName(PhpToken $token): bool
    {
        return in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private static function opens(PhpToken $token): bool
    {
        return in_array($token->text, ['(', '[', '{'], true)
            || in_array($token->id, [T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    private static function closes(PhpToken $token): bool
    {
        return in_array($token->text, [')', ']', '}'], true);
    }
}
