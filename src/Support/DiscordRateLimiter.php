<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-tier rate limiter: a global cap on total volume plus a per-fingerprint
 * cap that guards against a single looping error flooding the channel.
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

        if (! $this->within('discord-logger:rl:global', $global['max'] ?? 30, $global['per_seconds'] ?? 60)) {
            return false;
        }

        return $this->within(
            'discord-logger:rl:fp:'.$fingerprint,
            $perFp['max'] ?? 1,
            $perFp['per_seconds'] ?? 300,
        );
    }

    private function within(string $key, int $max, int $perSeconds): bool
    {
        if ($max <= 0) {
            return true;
        }

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, $perSeconds);

        return true;
    }
}
