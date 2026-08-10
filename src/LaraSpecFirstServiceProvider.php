<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst;

use Gcob\LaraSpecFirst\Configuration\ConfigurationMerger;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Entry point of the package into a Laravel application.
 *
 * Registered automatically through Composer package discovery, declared in
 * composer.json under extra.laravel.providers.
 */
class LaraSpecFirstServiceProvider extends ServiceProvider
{
    private const string CONFIG_FILE = __DIR__.'/../config/lara-spec-first.php';

    /**
     * Bind the package's services into the container.
     *
     * Nothing here may reach for the reader or the parser: the runtime never
     * sees a specification, so what belongs in this method is configuration and
     * the wiring the build-time commands will resolve.
     *
     * @see docs/CODE-GENERATION.md — "The runtime never sees the spec"
     */
    public function register(): void
    {
        $this->mergeConfigDeeply(self::CONFIG_FILE, 'lara-spec-first');

        // Constructed with the configured hosts rather than reading config
        // itself, so the guard stays a plain object a unit test can build.
        $this->app->bind(RemoteReferenceGuard::class, static function (Application $app): RemoteReferenceGuard {
            /** @var list<string> $hosts */
            $hosts = $app->make(Repository::class)->get('lara-spec-first.remote_references.allowed_hosts', []);

            return new RemoteReferenceGuard($hosts);
        });
    }

    /**
     * Merge the package's defaults under whatever the application published.
     *
     * Deeply, for the reasons {@see ConfigurationMerger} carries.
     */
    private function mergeConfigDeeply(string $path, string $key): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $path;

        $config = $this->app->make(Repository::class);

        /** @var array<string, mixed> $published */
        $published = $config->get($key, []);

        $config->set($key, ConfigurationMerger::defaultsUnder($defaults, $published));
    }

    /**
     * Boot the package once every provider has been registered.
     *
     * Contract-driven route registration will happen here, loading generated
     * PHP rather than reading a specification.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_FILE => $this->app->configPath('lara-spec-first.php')], 'lara-spec-first-config');
        }
    }
}
