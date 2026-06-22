<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Collapses repeats of the same fingerprint inside a time window down to a
 * single notification, counting the silenced occurrences.
 */
class Deduplicator
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function decide(string $fingerprint): Decision
    {
        if (($this->config['deduplication']['enabled'] ?? true) !== true) {
            return new Decision(send: true, first: true, occurrences: 1);
        }

        $store = $this->store();
        $key = $this->key($fingerprint);
        $window = (int) ($this->config['deduplication']['window'] ?? 300);

        // add() is atomic: true only for the very first occurrence in the window.
        // Keep the counter alive a bit longer than the window so a summary job
        // scheduled at +window can still read it.
        if ($store->add($key, 1, $window + 60)) {
            return new Decision(send: true, first: true, occurrences: 1);
        }

        $count = (int) $store->increment($key);

        return new Decision(send: false, first: false, occurrences: max($count, 2));
    }

    public function occurrences(string $fingerprint): int
    {
        return (int) $this->store()->get($this->key($fingerprint), 1);
    }

    public function forget(string $fingerprint): void
    {
        $this->store()->forget($this->key($fingerprint));
    }

    public function key(string $fingerprint): string
    {
        return 'discord-logger:dedup:'.$fingerprint;
    }

    private function store(): Repository
    {
        return Cache::store($this->config['store'] ?? null);
    }
}
