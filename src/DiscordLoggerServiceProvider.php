<?php

namespace JeffersonGoncalves\DiscordLogger;

use Illuminate\Contracts\Events\Dispatcher;
use JeffersonGoncalves\DiscordLogger\Commands\TestCommand;
use JeffersonGoncalves\DiscordLogger\Support\RuntimeContext;
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

    public function packageBooted(): void
    {
        RuntimeContext::listen($this->app->make(Dispatcher::class));
    }
}
