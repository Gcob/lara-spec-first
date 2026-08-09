<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst;

use Illuminate\Support\ServiceProvider;

/**
 * Entry point of the package into a Laravel application.
 *
 * Registered automatically through Composer package discovery, declared in
 * composer.json under extra.laravel.providers.
 */
class LaraSpecFirstServiceProvider extends ServiceProvider
{
    /**
     * Bind the package's services into the container.
     *
     * Empty on purpose, and it will stay that way for the reading side: the
     * runtime never sees a specification, so nothing here may reach for the
     * reader or the parser. What lands here is what boot() needs to register
     * generated routes.
     *
     * @see docs/CODE-GENERATION.md — "The runtime never sees the spec"
     */
    public function register(): void {}

    /**
     * Boot the package once every provider has been registered.
     *
     * Contract-driven route registration will happen here.
     */
    public function boot(): void {}
}
