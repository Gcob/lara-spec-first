<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\RouteActionOverride;

/*
 * What a custom controller's file says about its `routeAction`, read from the
 * source and never from the class: loading an override that no longer fits its
 * parent is a compile-time fatal that would end the build reading it.
 *
 * Every row hands `read()` a string, which is the whole interface. A string
 * cannot load a class, so there is nothing here that could prove the file is
 * left unloaded; the feature test on `spec:build` pins that.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */

/**
 * A class `Acme\Child` with one method written as given, extending what is given.
 */
function childSource(string $method, string $extends = '\\Acme\\Generated\\Base'): string
{
    return "<?php\n\nnamespace Acme;\n\nfinal class Child extends {$extends}\n{\n    {$method}\n}\n";
}

it('reads the parameters a custom routeAction declares', function (
    string $declaration,
    int $parameters,
    int $required,
    bool $variadic,
): void {
    $override = RouteActionOverride::read(childSource($declaration), 'Acme\\Child');

    expect($override)->not->toBeNull()
        ->and($override?->parameters)->toBe($parameters)
        ->and($override?->required)->toBe($required)
        ->and($override?->variadic)->toBe($variadic);
})->with([
    'none' => ['public function routeAction(): mixed { return 1; }', 0, 0, false],
    'one' => ['public function routeAction(string $id): mixed { return 1; }', 1, 1, false],
    'a request and an id' => [
        'public function routeAction(\\Acme\\Req $request, string $id): mixed { return 1; }',
        2, 2, false,
    ],
    'an optional array' => ['public function routeAction(string $id, array $x = []): mixed { return 1; }', 2, 1, false],
    'a default with parentheses and a comma' => [
        'public function routeAction(string $id, $x = new Foo(1, 2)): mixed { return 1; }',
        2, 1, false,
    ],
    'a default array with commas' => [
        'public function routeAction(string $id, array $x = [1, 2, [3, 4]]): mixed { return 1; }',
        2, 1, false,
    ],
    'a variadic' => ['public function routeAction(...$rest): mixed { return 1; }', 1, 0, true],
    'an id then a variadic' => ['public function routeAction(string $id, ...$rest): mixed { return 1; }', 2, 1, true],
    'a trailing comma' => ['public function routeAction(string $id,): mixed { return 1; }', 1, 1, false],
    'an attribute with arguments on a parameter' => [
        'public function routeAction(#[SensitiveParameter(1, 2)] string $id, string $other): mixed { return 1; }',
        2, 2, false,
    ],
    'a default before a required parameter, which PHP reads as required' => [
        'public function routeAction(string $a = "x", string $b): mixed { return 1; }',
        2, 2, false,
    ],
    'the method written RouteAction' => ['public function RouteAction(string $id): mixed { return 1; }', 1, 1, false],
    'returned by reference' => ['public function &routeAction(string $id): mixed { return 1; }', 1, 1, false],
    // The tokenizer cuts an interpolated string into pieces, and a piece can be exactly one bracket: it is text, not
    // code, so it opens and closes nothing.
    'a method above it whose string ends in a closing parenthesis' => [
        'public function note(string $id, string $name): string { return "Updated user $id ($name)"; }'
            ."\n    ".'public function routeAction(string $id): mixed { return 1; }',
        1, 1, false,
    ],
    'a method above it whose string holds a bare opening parenthesis' => [
        'public function note(string $id, string $name): string { return "$id($name"; }'
            ."\n    ".'public function routeAction(string $id): mixed { return 1; }',
        1, 1, false,
    ],
    'a method above it whose string holds a bare closing brace' => [
        'public function note(string $id): string { return "{$id}}"; }'
            ."\n    ".'public function routeAction(string $id): mixed { return 1; }',
        1, 1, false,
    ],
    'a method above it whose string holds a bare opening brace' => [
        'public function note(string $id): string { return "$id{"; }'
            ."\n    ".'public function routeAction(string $id): mixed { return 1; }',
        1, 1, false,
    ],
]);

it('resolves the class a custom controller extends', function (
    string $source,
    ?string $extends,
): void {
    $override = RouteActionOverride::read($source, 'Acme\\Child');

    expect($override)->not->toBeNull()
        ->and($override?->extends)->toBe($extends);
})->with([
    'a fully qualified name, the scaffold shape' => [
        childSource('public function routeAction(): mixed {}', '\\A\\B\\Base'),
        'A\\B\\Base',
    ],
    'a use' => [
        "<?php\n\nnamespace Acme;\n\nuse A\\B\\Base;\n\nfinal class Child extends Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'A\\B\\Base',
    ],
    'a use with an alias' => [
        "<?php\n\nnamespace Acme;\n\nuse A\\B\\Base as Alias;\n\nfinal class Child extends Alias\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'A\\B\\Base',
    ],
    'a qualified name starting at a use' => [
        "<?php\n\nnamespace Acme;\n\nuse A\\B;\n\nfinal class Child extends B\\Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'A\\B\\Base',
    ],
    'a name relative to the namespace of the file' => [
        "<?php\n\nnamespace Acme;\n\nfinal class Child extends Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'Acme\\Base',
    ],
    'a qualified name relative to the namespace of the file' => [
        "<?php\n\nnamespace Acme;\n\nfinal class Child extends Sub\\Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'Acme\\Sub\\Base',
    ],
    'a braced namespace' => [
        "<?php\n\nnamespace Acme {\n    use A\\B\\Base;\n\n    final class Child extends Base\n    {\n"
            ."        public function routeAction(): mixed {}\n    }\n}\n",
        'A\\B\\Base',
    ],
    'the namespace keyword' => [
        "<?php\n\nnamespace Acme;\n\nfinal class Child extends namespace\\Sub\\Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        'Acme\\Sub\\Base',
    ],
    'a group use, which is not read' => [
        "<?php\n\nnamespace Acme;\n\nuse A\\{B, Base};\n\nfinal class Child extends Base\n{\n"
            ."    public function routeAction(): mixed {}\n}\n",
        null,
    ],
    'a class extending nothing' => [
        "<?php\n\nnamespace Acme;\n\nfinal class Child\n{\n    public function routeAction(): mixed {}\n}\n",
        null,
    ],
]);

it('finds no routeAction where the class does not declare one', function (string $source): void {
    expect(RouteActionOverride::read($source, 'Acme\\Child'))->toBeNull();
})->with([
    'a class overriding only a CRUD method' => [
        childSource('public function index(string $id): mixed { return 1; }'),
    ],
    'a routeAction only in a comment' => [
        childSource("// public function routeAction(string \$id): mixed\n    public function index(): mixed { return 1; }"),
    ],
    'a routeAction only in a string' => [
        childSource("public function index(): string { return 'function routeAction(string \$id)'; }"),
    ],
    'a routeAction in another class of the same file' => [
        childSource('public function index(): mixed { return 1; }')
            ."\nfinal class Other extends \\Acme\\Generated\\Base\n{\n"
            ."    public function routeAction(string \$id): mixed { return 1; }\n}\n",
    ],
    'a routeAction in an anonymous class inside another method' => [
        childSource(
            'public function index(): mixed { return new class { '
            .'public function routeAction(string $id): mixed { return 1; } }; }',
        ),
    ],
    'a routeAction of a closure' => [
        childSource('public function index(): mixed { return function routeAction(string $id) {}; }'),
    ],
    'a file with no such class at all' => ["<?php // mine\n"],
]);

it('fits a parent only when PHP would load the override', function (
    string $declaration,
    int $parentParameters,
    bool $fits,
): void {
    $override = RouteActionOverride::read(childSource("public function routeAction({$declaration}): mixed {}"), 'Acme\\Child');

    expect($override?->fits($parentParameters))->toBe($fits);
})->with([
    'an id short of a request' => ['string $id', 2, false],
    'a variadic takes anything' => ['...$a', 2, true],
    'an optional extra' => ['\\Acme\\R $request, string $id, $x = 1', 2, true],
    'a required extra' => ['\\Acme\\R $request, string $id, $x', 2, false],
    'a wider request type' => ['object $request, string $id', 2, true],
    'a request lost by the parent' => ['\\Acme\\R $request, string $id', 1, false],
    'the same count' => ['\\Acme\\R $request, string $id', 2, true],
    'a parent with nothing, a child with nothing' => ['', 0, true],
]);
