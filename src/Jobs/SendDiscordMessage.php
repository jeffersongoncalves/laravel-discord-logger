<?php

namespace JeffersonGoncalves\DiscordLogger\Jobs;

use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

class SendDiscordMessage extends DiscordJob
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $webhook,
        public array $payload,
    ) {}

    public function handle(DiscordWebhook $transport): void
    {
        $this->deliver($transport, $this->webhook, $this->payload);
    }
}
