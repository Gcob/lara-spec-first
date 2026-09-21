<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;

/**
 * What `routeAction` declares, in the one place both sides of the seam read it
 * from.
 *
 * **Two classes have to agree on this signature or the application does not
 * load.** The generated parent declares it and a custom child overrides it, and
 * PHP forbids an override from widening — so a scaffold that built the
 * signature its own way would write a child that is a fatal error the moment
 * the two derivations disagree. They did disagree in exactly the way this class
 * prevents: the request parameter had to be learned twice.
 *
 * **Named, because Laravel matches route parameters to method parameters by
 * name** rather than by position. The specification's spelling is load-bearing
 * here in a way it is not anywhere else, and renaming `{id}` to `{userId}`
 * changes this signature.
 *
 * **The request comes first**, ahead of the path's own parameters. Laravel
 * splices a class-typed parameter in from the container and fills the rest from
 * the route, so either order works and only one of them reads like ordinary
 * Laravel.
 *
 * @see docs/guide/code-generation/request-validation.md — "The type hint is what runs it"
 * @see docs/guide/controllers.md — "One controller, one routeAction"
 */
final readonly class RouteActionSignature
{
    /**
     * The parameter name the generated request is bound to.
     *
     * A constant rather than a literal in three files: the declaration, the
     * arguments a scaffolded child passes up, and every test that reads either.
     */
    public const REQUEST_PARAMETER = 'request';

    /**
     * The parameter list as PHP, ready to sit between the parentheses.
     *
     * **It cannot be empty, and that is a constraint rather than an
     * observation.** PHP forbids an override from adding a required parameter,
     * so a parent declaring none would make `x-controller` useless on every
     * templated path: a developer could only reach `{id}` through the request
     * object. Verified rather than reasoned about — a Workbench child declaring
     * `routeAction(string $id)` over a parameterless parent is a fatal error at
     * load.
     *
     * `string` for a path parameter because that is what one is until something
     * says otherwise. `x-model` is what will turn one into a bound model.
     *
     * @param  string|null  $requestType  the request class as the emitting file
     *                                    refers to it — its short name, since
     *                                    both callers import it — or null for an
     *                                    operation with nothing to validate
     */
    public static function declaration(Operation $operation, ?string $requestType): string
    {
        $parameters = array_map(
            static fn (string $parameter): string => 'string $'.$parameter,
            $operation->path->parameterNames,
        );

        if ($requestType !== null) {
            array_unshift($parameters, $requestType.' $'.self::REQUEST_PARAMETER);
        }

        return implode(', ', $parameters);
    }

    /**
     * The same parameters as arguments, for a child calling `parent::`.
     */
    public static function arguments(Operation $operation, bool $hasRequest): string
    {
        $arguments = array_map(
            static fn (string $parameter): string => '$'.$parameter,
            $operation->path->parameterNames,
        );

        if ($hasRequest) {
            array_unshift($arguments, '$'.self::REQUEST_PARAMETER);
        }

        return implode(', ', $arguments);
    }
}
