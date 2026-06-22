<?php

namespace JeffersonGoncalves\DiscordLogger\Jobs;

use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

class SendDiscordMessage extends DiscordJob
{
    public int $tries = 5;

    public int $maxExceptions = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $webhook,
        public array $payload,
    ) {}

    public function handle(DiscordWebhook $transport): void
    {
        $response = $transport->send($this->webhook, $this->payload);

        // Discord throttles webhooks hard — honour Retry-After and back off.
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After')
                ?: $response->json('retry_after')
                ?: 2;

            $this->release(max(1, (int) ceil((float) $retryAfter)));

            return;
        }

        // 4xx (bad webhook, 404 deleted) — don't retry forever, just drop.
        if ($response->clientError()) {
            $this->fail();

            return;
        }

        $response->throw();
    }
}
