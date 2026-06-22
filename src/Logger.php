<?php

namespace JeffersonGoncalves\DiscordLogger;

use JeffersonGoncalves\DiscordLogger\Handlers\DiscordHandler;
use Monolog\Logger as Monolog;

/**
 * Custom Laravel logging channel factory.
 *
 * config/logging.php:
 *
 *   'discord' => [
 *       'driver' => 'custom',
 *       'via'    => \JeffersonGoncalves\DiscordLogger\Logger::class,
 *       'level'  => 'error',
 *       'url'    => env('LOG_DISCORD_WEBHOOK_URL'),
 *   ],
 */
class Logger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Monolog
    {
        // Channel config (url, level, ...) is layered on top of the package config.
        $config = array_replace(
            (array) config('discord-logger', []),
            $config,
        );

        return new Monolog('discord', [new DiscordHandler($config)]);
    }
}
