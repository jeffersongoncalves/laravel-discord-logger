<?php

namespace JeffersonGoncalves\DiscordLogger\Converters;

use JeffersonGoncalves\DiscordLogger\Support\Redactor;
use Monolog\LogRecord;
use Throwable;

/**
 * Renders a record as a Discord embed (colored, with context fields + stacktrace).
 */
class RichRecordConverter implements Converter
{
    /** Discord hard limit for the combined character count of a single embed. */
    private const EMBED_MAX = 6000;

    /** Discord hard limit for a single embed field name. */
    private const FIELD_NAME_MAX = 256;

    /** Discord hard limit for a single embed field value. */
    private const FIELD_VALUE_MAX = 1024;

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

        $embed = [
            'title' => $this->title($record),
            // The message is raw user/exception text — redact before it ships.
            'description' => $this->truncate($this->redactor->scrubString($record->message), 4000),
            'color' => (int) ($this->config['colors'][$level] ?? 0x607D8B),
            'timestamp' => $record->datetime->format('c'),
            'fields' => $this->fields($record),
        ];

        $embed = $this->clamp($embed);

        return array_filter([
            'username' => $this->config['from']['name'] ?? null,
            'avatar_url' => $this->config['from']['avatar_url'] ?? null,
            'embeds' => [array_filter($embed, fn ($v) => $v !== [])],
        ], fn ($v) => $v !== null);
    }

    /**
     * Drop fields from the end until the embed fits within Discord's 6000-char
     * limit, so an overgrown stacktrace/context never triggers a 400.
     *
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function clamp(array $embed): array
    {
        $fixed = mb_strlen((string) $embed['title']) + mb_strlen((string) $embed['description']);

        /** @var array<int, array<string, mixed>> $fields */
        $fields = $embed['fields'];
        $kept = [];
        $running = $fixed;

        foreach ($fields as $field) {
            $size = mb_strlen((string) $field['name']) + mb_strlen((string) $field['value']);
            if ($running + $size > self::EMBED_MAX) {
                break;
            }
            $running += $size;
            $kept[] = $field;
        }

        $embed['fields'] = $kept;

        return $embed;
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
            $fields[] = $this->field(
                'Exception',
                $exception::class.' @ '.$exception->getFile().':'.$exception->getLine(),
            );

            $trace = $this->stacktrace($exception);
            if ($trace !== null) {
                $fields[] = $this->field('Stacktrace', $trace);
            }
        }

        $context = array_filter(
            $record->context,
            fn ($key) => $key !== 'exception',
            ARRAY_FILTER_USE_KEY,
        );

        if ($context !== []) {
            // Wrap the JSON in a code block, but keep the whole field value within
            // Discord's 1024-char-per-field limit (an oversized context = HTTP 400).
            $json = $this->truncate(
                $this->json($this->redactor->scrub($context)),
                self::FIELD_VALUE_MAX - $this->codeOverhead(),
            );

            $fields[] = $this->field('Context', $this->code($json));
        }

        return $fields;
    }

    /**
     * Build a field with both name and value clamped to Discord's per-field
     * limits so a single overgrown field never produces a 400.
     *
     * @return array{name: string, value: string}
     */
    private function field(string $name, string $value): array
    {
        return [
            'name' => $this->truncate($name, self::FIELD_NAME_MAX),
            'value' => $this->truncate($value, self::FIELD_VALUE_MAX),
        ];
    }

    /** Characters added by wrapping a value in a fenced code block. */
    private function codeOverhead(): int
    {
        return mb_strlen($this->code(''));
    }

    private function stacktrace(Throwable $exception): ?string
    {
        $mode = $this->config['stacktrace'] ?? 'smart';

        if ($mode === 'none') {
            return null;
        }

        // The trace can embed argument values / paths with secrets — redact it.
        $trace = $this->redactor->scrubString($exception->getTraceAsString());

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
     * @param  array<array-key, mixed>  $value
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
