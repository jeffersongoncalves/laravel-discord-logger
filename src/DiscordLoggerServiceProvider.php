<?php

namespace JeffersonGoncalves\DiscordLogger;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DiscordLoggerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-discord-logger')
            ->hasConfigFile();
    }
}
