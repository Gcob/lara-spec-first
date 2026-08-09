<?php

use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Illuminate\Support\Facades\Route;

// A diagnostic landing page for `composer serve`. It is development
// scaffolding, not a feature of the package, and ships nowhere.
//
// It answers the two questions that actually bite while working on this
// package: is my service provider really loaded, and which Laravel am I
// resolved against? The default Laravel welcome page answers neither.
Route::get('/', function () {
    return response()->json([
        'package' => 'gcob/lara-spec-first',
        'provider_loaded' => array_key_exists(
            LaraSpecFirstServiceProvider::class,
            app()->getLoadedProviders()
        ),
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
    ], options: JSON_PRETTY_PRINT);
});
