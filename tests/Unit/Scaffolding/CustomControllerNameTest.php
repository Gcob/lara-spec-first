<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Scaffolding\CustomControllerName;

/*
 * What `spec:make` proposes, and what it refuses to write into the contract.
 *
 * Every refusal here would otherwise be found later — by the reader, or by the
 * planner — which is the point of checking them at the prompt: found then, it means
 * a specification already edited and a command that declines to finish.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */

function nameFor(string $base = 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers'): CustomControllerName
{
    return new CustomControllerName($base, 'App\\Http\\Generated');
}

function operationNamed(?string $operationId, string $method = 'get', string $path = '/users/{id}'): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: $operationId,
    );
}

describe('what it proposes', function (): void {
    // The same value in almost every contract: the `operationId` studly-cased and
    // suffixed. Derived rather than asked for, so the common case is a keystroke.
    it('joins the configured namespace to the name the build would generate', function (): void {
        expect(nameFor('App\\Http\\Controllers')->propose(operationNamed('showUser')))
            ->toBe('App\\Http\\Controllers\\ShowUserController');
    });

    it('falls back to the method and path for an operation with no operationId', function (): void {
        expect(nameFor('App\\Http\\Controllers')->propose(operationNamed(null)))
            ->toBe('App\\Http\\Controllers\\GetUsersIdController');
    });

    it('does not double a separator the configured namespace already ends with', function (): void {
        expect(nameFor('App\\Http\\Controllers\\')->propose(operationNamed('showUser')))
            ->toBe('App\\Http\\Controllers\\ShowUserController');
    });
});

describe('what it refuses', function (): void {
    it('accepts a name this project maps', function (): void {
        expect(nameFor()->reasonToRefuse(
            'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\Anything'
        ))->toBeNull();
    });

    // A leading separator is how PHP writes an absolute name, and it names the same
    // class either way.
    it('accepts a name written with a leading separator', function (): void {
        expect(nameFor()->reasonToRefuse(
            '\\Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\Anything'
        ))->toBeNull();
    });

    it('says nothing about an empty submission, which means leaving the document alone', function (): void {
        expect(nameFor()->reasonToRefuse(''))->toBeNull()
            ->and(nameFor()->reasonToRefuse('   '))->toBeNull();
    });

    it('refuses a name PHP could not carry', function (string $candidate): void {
        expect(nameFor()->reasonToRefuse($candidate))->toContain('Not a class name PHP could carry');
    })->with([
        'a hyphen' => 'App\\Http\\User-Controller',
        'a leading digit' => 'App\\Http\\2FaController',
        'a trailing separator' => 'App\\Http\\Controllers\\',
        'a space' => 'App\\Http\\User Controller',
    ]);

    // The class would extend itself, and a build rewrites everything under that
    // namespace — so the work would not survive one.
    it('refuses a name inside the generated namespace', function (): void {
        expect(nameFor()->reasonToRefuse('App\\Http\\Generated\\Controllers\\UserController'))
            ->toContain('which a build rewrites');
    });

    // A namespace that merely starts with the same characters is a different
    // namespace, and refusing it would refuse a legitimate name.
    it('accepts a namespace that only looks like the generated one', function (): void {
        $name = new CustomControllerName(
            'Gcob\\LaraSpecFirst\\Tests\\Fixtures',
            'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\Generated',
        );

        expect($name->reasonToRefuse('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\GeneratedThings\\Thing'))
            ->toBeNull();
    });

    // A path invented from an unmapped namespace would produce a file that compiles
    // and that the autoloader never finds.
    it('refuses a namespace nothing maps', function (): void {
        expect(nameFor()->reasonToRefuse('Acme\\Nowhere\\UserController'))
            ->toContain('No PSR-4 prefix in this project maps that namespace');
    });
});
