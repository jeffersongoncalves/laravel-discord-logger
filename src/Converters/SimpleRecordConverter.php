<?php

namespace JeffersonGoncalves\DiscordLogger\Converters;

use Monolog\LogRecord;

/**
 * Plain-content message (no embeds). Useful for simple channels or relays.
 */
class SimpleRecordConverter implements Converter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function convert(LogRecord $record): array
    {
        $level = $record->level->getName();
        $emoji = $this->config['emojis'][$level] ?? '';

        $content = trim("{$emoji} **{$level}** {$record->message}");

        return array_filter([
            'username' => $this->config['from']['name'] ?? null,
            'avatar_url' => $this->config['from']['avatar_url'] ?? null,
            'content' => mb_substr($content, 0, 2000),
        ], fn ($v) => $v !== null);
    }
}
