<?php

namespace JeffersonGoncalves\DiscordLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JeffersonGoncalves\DiscordLogger\Support\Fallback;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;
use Throwable;

abstract class DiscordJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** Seconds before each retry while Discord is unreachable or erroring. */
    protected const BACKOFF = [10, 30, 60, 120, 300];

    /**
     * Send and handle every outcome here. This never throws: the worker reports
     * a job's exception through the app's log stack — which usually includes the
     * Discord channel — so a throw would post a message about failing to post
     * the previous one (a loop), with the webhook URL in it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(DiscordWebhook $transport, string $webhook, array $payload): void
    {
        try {
            $response = $transport->send($webhook, $payload);
        } catch (Throwable $e) {
            // DNS/connect/timeout (ConnectionException) or anything unexpected.
            $this->retryOrGiveUp($e->getMessage());

            return;
        }

        if ($response->status() === 429) {
            // Discord throttles webhooks hard — honour Retry-After.
            $retryAfter = $response->header('Retry-After') ?: $response->json('retry_after') ?: 2;

            $this->retryOrGiveUp('Discord rate limit (HTTP 429)', max(1, (int) ceil((float) $retryAfter)));

            return;
        }

        if ($response->serverError()) {
            $this->retryOrGiveUp("Discord responded with HTTP {$response->status()}");

            return;
        }

        // 4xx (webhook deleted, bad token, invalid payload) — retrying can't help,
        // but say why: Discord's message is the only clue to what broke.
        if ($response->clientError()) {
            Fallback::report(
                (array) config('discord-logger', []),
                "Discord responded with HTTP {$response->status()}: ".($response->json('message') ?? 'no message'),
            );

            $this->fail();
        }
    }

    /**
     * Retry with backoff while attempts remain; after the last one, record the
     * failure where it cannot loop and drop the job — releasing past $tries
     * would make the worker throw MaxAttemptsExceededException instead.
     */
    private function retryOrGiveUp(string $reason, ?int $delay = null): void
    {
        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($delay ?? static::BACKOFF[min($attempt, count(static::BACKOFF)) - 1]);

            return;
        }

        Fallback::report((array) config('discord-logger', []), "{$reason} (gave up after {$attempt} attempts)");

        $this->delete();
    }
}
