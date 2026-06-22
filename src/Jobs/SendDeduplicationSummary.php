<?php

namespace JeffersonGoncalves\DiscordLogger\Jobs;

use JeffersonGoncalves\DiscordLogger\Support\Deduplicator;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

/**
 * Runs when a dedup window closes. If the error fired more than once, it posts a
 * single roll-up ("occurred N times") instead of N separate notifications.
 */
class SendDeduplicationSummary extends DiscordJob
{
    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $webhook,
        public string $fingerprint,
        public string $title,
        public int $color,
        public array $config,
    ) {}

    public function handle(DiscordWebhook $transport): void
    {
        // Build from the carried config: Deduplicator requires `array $config`,
        // which the container cannot autowire on the queue worker.
        $deduplicator = new Deduplicator($this->config);

        $occurrences = $deduplicator->occurrences($this->fingerprint);
        $deduplicator->forget($this->fingerprint);

        if ($occurrences < 2) {
            return; // No repeats — the first message already said it all.
        }

        $window = (int) ($this->config['deduplication']['window'] ?? 300);

        $payload = [
            'username' => $this->config['from']['name'] ?? 'Laravel Logger',
            'avatar_url' => $this->config['from']['avatar_url'] ?? null,
            'embeds' => [[
                'title' => '🔁 '.$this->title,
                'description' => "Repeated **{$occurrences}×** within the last {$window}s (grouped).",
                'color' => $this->color,
            ]],
        ];

        $transport->send($this->webhook, $payload);
    }
}
