<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\DiscordLogger\Logger;
use Throwable;

/**
 * Where delivery failures go: the configured fallback channel, redacted, and
 * never a channel that itself reaches Discord — a failure to deliver must not
 * turn into another delivery (a loop), nor leak the webhook token.
 */
class Fallback
{
    /**
     * @param  array<string, mixed>  $config  package config (discord-logger.*)
     */
    public static function report(array $config, string $message): void
    {
        $channel = $config['fallback_channel'] ?? null;

        if (! is_string($channel) || $channel === '' || self::reachesDiscord($channel)) {
            return;
        }

        try {
            Log::channel($channel)->warning(
                'Discord logger delivery failed: '.Redactor::fromConfig($config)->scrubString($message),
            );
        } catch (Throwable) {
            // The fallback itself is broken — nothing left to tell.
        }
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function reachesDiscord(string $channel, array $seen = []): bool
    {
        $config = config("logging.channels.{$channel}");

        if (! is_array($config) || isset($seen[$channel])) {
            return false;
        }

        if (($config['via'] ?? null) === Logger::class) {
            return true;
        }

        if (($config['driver'] ?? null) !== 'stack') {
            return false;
        }

        foreach ((array) ($config['channels'] ?? []) as $inner) {
            if (is_string($inner) && self::reachesDiscord($inner, $seen + [$channel => true])) {
                return true;
            }
        }

        return false;
    }
}
