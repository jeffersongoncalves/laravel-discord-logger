<?php

namespace JeffersonGoncalves\DiscordLogger;

use JeffersonGoncalves\DiscordLogger\Commands\TestCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DiscordLoggerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-discord-logger')
            ->hasConfigFile()
            ->hasCommand(TestCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('jeffersongoncalves/laravel-discord-logger');
            });
    }
}
