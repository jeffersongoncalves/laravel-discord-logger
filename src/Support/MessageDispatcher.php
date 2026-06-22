<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use JeffersonGoncalves\DiscordLogger\Jobs\DiscordJob;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

/**
 * Routes outbound messages either through the queue (default) or inline.
 */
class MessageDispatcher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $webhook, array $payload): void
    {
        if (($this->config['queue']['enabled'] ?? true) === true) {
            $this->queue(new SendDiscordMessage($webhook, $payload));

            return;
        }

        // Inline, best-effort. Logging must never break the request.
        app(DiscordWebhook::class)->send($webhook, $payload);
    }

    public function queue(DiscordJob $job): void
    {
        if ($connection = $this->config['queue']['connection'] ?? null) {
            $job->onConnection($connection);
        }

        if ($queue = $this->config['queue']['queue'] ?? null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
