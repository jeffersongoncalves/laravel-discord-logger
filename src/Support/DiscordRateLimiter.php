<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Two-tier rate limiter: a global cap on total volume plus a per-fingerprint
 * cap that guards against a single looping error flooding the channel.
 *
 * Counters live in the same cache store as the deduplicator (config `store`),
 * so dedup and rate limiting always agree on where state lives.
 */
class DiscordRateLimiter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function allow(string $fingerprint): bool
    {
        if (($this->config['rate_limit']['enabled'] ?? true) !== true) {
            return true;
        }

        $global = (array) ($this->config['rate_limit']['global'] ?? []);
        $perFp = (array) ($this->config['rate_limit']['per_fingerprint'] ?? []);

        $fpBucket = 'fp:'.$fingerprint;
        $fpMax = (int) ($perFp['max'] ?? 1);

        // Check per-fingerprint first: a looping error blocked here must not
        // also burn through the global budget meant for other errors.
        if (! $this->within($fpBucket, $fpMax, (int) ($perFp['per_seconds'] ?? 300))) {
            return false;
        }

        if ($this->within('global', (int) ($global['max'] ?? 30), (int) ($global['per_seconds'] ?? 60))) {
            return true;
        }

        // The global cap rejected this attempt after the fingerprint slot was
        // reserved — nothing was actually sent, so release the reservation.
        // Otherwise a fingerprint that merely got unlucky on global timing
        // stays blocked for its whole window despite never having delivered.
        $this->release($fpBucket, $fpMax);

        return false;
    }

    private function within(string $bucket, int $max, int $perSeconds): bool
    {
        if ($max <= 0) {
            return true;
        }

        $store = $this->store();
        $key = $this->key($bucket);

        // First hit in the window starts the counter with a fixed TTL.
        if ($store->add($key, 1, $perSeconds)) {
            return true;
        }

        return (int) $store->increment($key) <= $max;
    }

    private function release(string $bucket, int $max): void
    {
        if ($max <= 0) {
            return;
        }

        $store = $this->store();
        $key = $this->key($bucket);

        if ((int) $store->decrement($key) < 1) {
            $store->forget($key);
        }
    }

    private function key(string $bucket): string
    {
        return 'discord-logger:rl:'.$bucket;
    }

    private function store(): Repository
    {
        return Cache::store($this->config['store'] ?? null);
    }
}
