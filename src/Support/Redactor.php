<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

/**
 * Masks sensitive values in log context before they reach Discord.
 */
class Redactor
{
    /**
     * @param  array<int, string>  $keys  case-insensitive key fragments to redact
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly string $placeholder = '[REDACTED]',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $redact = (array) ($config['redact'] ?? []);

        return new self(
            keys: array_map('strval', $redact),
            placeholder: (string) ($config['redact_placeholder'] ?? '[REDACTED]'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        if ($this->keys === []) {
            return $data;
        }

        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->matches($key)) {
                $scrubbed[$key] = $this->placeholder;

                continue;
            }

            $scrubbed[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $scrubbed;
    }

    private function matches(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $needle) {
            if ($needle !== '' && str_contains($key, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
