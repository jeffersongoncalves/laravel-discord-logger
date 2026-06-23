<?php

namespace JeffersonGoncalves\DiscordLogger\Converters;

use JeffersonGoncalves\DiscordLogger\Support\Redactor;
use Monolog\LogRecord;

/**
 * Plain-content message (no embeds). Useful for simple channels or relays.
 */
class SimpleRecordConverter implements Converter
{
    private Redactor $redactor;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        $this->redactor = Redactor::fromConfig($config);
    }

    public function convert(LogRecord $record): array
    {
        $level = $record->level->getName();
        $emoji = $this->config['emojis'][$level] ?? '';

        // The message is raw text — redact value patterns before it ships.
        $message = $this->redactor->scrubString($record->message);

        $content = trim("{$emoji} **{$level}** {$message}");

        return array_filter([
            'username' => $this->config['from']['name'] ?? null,
            'avatar_url' => $this->config['from']['avatar_url'] ?? null,
            'content' => mb_substr($content, 0, 2000),
        ], fn ($v) => $v !== null);
    }
}
