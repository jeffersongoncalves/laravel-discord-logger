<?php

namespace JeffersonGoncalves\DiscordLogger\Converters;

use Monolog\LogRecord;
use Throwable;

/**
 * Renders a record as a Discord embed (colored, with context fields + stacktrace).
 */
class RichRecordConverter implements Converter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function convert(LogRecord $record): array
    {
        $level = $record->level->getName();

        $embed = [
            'title' => $this->title($record),
            'description' => $this->truncate($record->message, 4000),
            'color' => (int) ($this->config['colors'][$level] ?? 0x607D8B),
            'timestamp' => $record->datetime->format('c'),
            'fields' => $this->fields($record),
        ];

        return array_filter([
            'username' => $this->config['from']['name'] ?? null,
            'avatar_url' => $this->config['from']['avatar_url'] ?? null,
            'embeds' => [array_filter($embed, fn ($v) => $v !== [])],
        ], fn ($v) => $v !== null);
    }

    public function title(LogRecord $record): string
    {
        $level = $record->level->getName();
        $emoji = $this->config['emojis'][$level] ?? '';

        return trim($emoji.' '.$level.' · '.($this->config['from']['name'] ?? 'Log'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fields(LogRecord $record): array
    {
        $fields = [];

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $fields[] = [
                'name' => 'Exception',
                'value' => $this->truncate($exception::class.' @ '.$exception->getFile().':'.$exception->getLine(), 1024),
            ];

            $trace = $this->stacktrace($exception);
            if ($trace !== null) {
                $fields[] = [
                    'name' => 'Stacktrace',
                    'value' => $trace,
                ];
            }
        }

        $context = array_filter(
            $record->context,
            fn ($key) => $key !== 'exception',
            ARRAY_FILTER_USE_KEY,
        );

        if ($context !== []) {
            $fields[] = [
                'name' => 'Context',
                'value' => $this->code($this->json($context)),
            ];
        }

        return $fields;
    }

    private function stacktrace(Throwable $exception): ?string
    {
        $mode = $this->config['stacktrace'] ?? 'smart';

        if ($mode === 'none') {
            return null;
        }

        $trace = $exception->getTraceAsString();

        if ($mode === 'smart') {
            // Drop vendor frames to keep the embed focused and small.
            $lines = array_filter(
                explode("\n", $trace),
                fn ($line) => ! str_contains($line, '/vendor/'),
            );
            $trace = implode("\n", $lines);
        }

        return $this->code($this->truncate($trace, 1000));
    }

    private function code(string $value): string
    {
        return "```\n".$value."\n```";
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1).'…';
    }
}
