<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

/**
 * Masks sensitive values before they reach Discord.
 *
 * Two complementary strategies:
 *
 *   1. Key matching — when an array/object key contains one of the configured
 *      fragments (case-insensitive), the value is masked. This is the primary
 *      strategy and the only one enabled by default.
 *   2. Value patterns — optional regexes matched against scalar *values*, so a
 *      secret leaking under an innocuous key (e.g. `url`, `auth`) is still
 *      caught. Disabled unless `redact_value_patterns` is configured.
 *
 * Scrubbing recurses into nested arrays AND public properties of objects, so a
 * DTO carried in the log context cannot smuggle a secret through.
 */
class Redactor
{
    /**
     * @param  array<int, string>  $keys  case-insensitive key fragments to redact
     * @param  array<int, string>  $valuePatterns  regexes matched against scalar values
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly string $placeholder = '[REDACTED]',
        private readonly array $valuePatterns = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $redact = (array) ($config['redact'] ?? []);
        $patterns = (array) ($config['redact_value_patterns'] ?? []);

        return new self(
            keys: array_map('strval', $redact),
            placeholder: (string) ($config['redact_placeholder'] ?? '[REDACTED]'),
            valuePatterns: array_map('strval', $patterns),
        );
    }

    /**
     * Mask a single string value (e.g. an exception message or stacktrace) using
     * the configured value patterns. Returns it unchanged when no pattern matches
     * or none are configured.
     */
    public function scrubString(string $value): string
    {
        if ($this->valuePatterns === []) {
            return $value;
        }

        foreach ($this->valuePatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            $replaced = @preg_replace($pattern, $this->placeholder, $value);
            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        if ($this->keys === [] && $this->valuePatterns === []) {
            return $data;
        }

        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->matches($key)) {
                $scrubbed[$key] = $this->placeholder;

                continue;
            }

            $scrubbed[$key] = $this->scrubValue($value);
        }

        return $scrubbed;
    }

    private function scrubValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->scrub($value);
        }

        if (is_object($value)) {
            return $this->scrub(get_object_vars($value));
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        return $value;
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
