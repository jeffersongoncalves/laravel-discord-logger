<?php

namespace JeffersonGoncalves\DiscordLogger\Tests;

use JeffersonGoncalves\DiscordLogger\DiscordLoggerServiceProvider;
use JeffersonGoncalves\DiscordLogger\Logger;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DiscordLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app['config']->set('logging.channels.discord', [
            'driver' => 'custom',
            'via' => Logger::class,
            'level' => 'debug',
            'url' => 'https://discord.com/api/webhooks/test/token',
        ]);
    }
}
