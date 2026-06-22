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

        if (! $this->within('global', (int) ($global['max'] ?? 30), (int) ($global['per_seconds'] ?? 60))) {
            return false;
        }

        return $this->within('fp:'.$fingerprint, (int) ($perFp['max'] ?? 1), (int) ($perFp['per_seconds'] ?? 300));
    }

    private function within(string $bucket, int $max, int $perSeconds): bool
    {
        if ($max <= 0) {
            return true;
        }

        $store = $this->store();
        $key = 'discord-logger:rl:'.$bucket;

        // First hit in the window starts the counter with a fixed TTL.
        if ($store->add($key, 1, $perSeconds)) {
            return true;
        }

        return (int) $store->increment($key) <= $max;
    }

    private function store(): Repository
    {
        return Cache::store($this->config['store'] ?? null);
    }
}
