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
     */
    public function register(): void
    {
        // magik is coming soon
    }

    /**
     * Boot the package once every provider has been registered.
     *
     * Contract-driven route registration will happen here.
     */
    public function boot(): void
    {
        // magik is coming soon
    }
}
