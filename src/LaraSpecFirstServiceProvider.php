<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst;

use Gcob\LaraSpecFirst\Configuration\ConfigurationMerger;
use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
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
     * @see docs/guide/code-generation.md — "The runtime never sees the spec"
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
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_FILE => $this->app->configPath('lara-spec-first.php')], 'lara-spec-first-config');
        }

        $this->loadGeneratedRoutes();
    }

    /**
     * Register the contract's routes by loading the PHP the build emitted.
     *
     * No specification is read here, at boot or ever: this method requires one
     * generated file and knows nothing about how it was produced. That is the
     * whole of the runtime's involvement with routing.
     *
     * **A missing file is silence, not an exception**, and the reason is
     * structural rather than lenient. `spec:build` is an Artisan command of this
     * package, so a provider that threw when the generated tree was absent
     * would make the application unbootable exactly when the command that
     * creates it needs to run — a fresh clone could never produce its own
     * routes. It is also a legitimate state on that fresh clone, since
     * .gitignore decides what a project commits. Reporting it belongs to
     * `spec:doctor`, which is where drift is caught before a deploy rather than
     * during one.
     *
     * **An unusable setting is a different matter and does throw**, because
     * nothing can be looked for without a path. But not in the console, and that
     * exemption is the same reasoning as the paragraph above rather than a
     * softening of it: throwing everywhere would take `config:clear`,
     * `spec:build` and `spec:doctor` down with the application, leaving a cached
     * broken configuration with no way out but deleting a cache file by hand. A
     * request fails loudly; the commands that repair the installation stay
     * reachable.
     *
     * `loadRoutesFrom()` rather than a plain require, because it is what skips
     * the file when the application's routes are already cached.
     *
     * @see docs/guide/code-generation.md — "The runtime never sees the spec"
     * @see docs/guide/code-generation.md — "Which generated code is committed"
     */
    private function loadGeneratedRoutes(): void
    {
        $configured = $this->app->make(Repository::class)->get(GeneratedRoutesLocator::SETTING);

        try {
            $locator = GeneratedRoutesLocator::fromConfiguration($this->app->basePath(), $configured);
        } catch (UnusableSettingException $refusal) {
            if ($this->app->runningInConsole()) {
                return;
            }

            throw $refusal;
        }

        if (! $locator->exists()) {
            return;
        }

        $this->loadRoutesFrom($locator->path());
    }
}
