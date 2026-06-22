<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Monolog\LogRecord;
use Throwable;

/**
 * Decides what makes two log records "the same error" for grouping/dedup.
 */
class Fingerprinter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function fingerprint(LogRecord $record): string
    {
        $grouping = (array) ($this->config['grouping'] ?? []);

        $callback = $grouping['callback'] ?? null;
        if (is_callable($callback)) {
            return md5((string) $callback($record));
        }

        $raw = match ($grouping['strategy'] ?? 'exception') {
            'message' => $this->message($record),
            'level_message' => $record->level->getName().'|'.$this->message($record),
            default => $this->exception($record),
        };

        return md5($raw);
    }

    private function exception(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            return implode('|', [
                $exception::class,
                $exception->getFile(),
                (string) $exception->getLine(),
            ]);
        }

        // No exception attached — fall back to the (normalized) message.
        return $record->level->getName().'|'.$this->message($record);
    }

    private function message(LogRecord $record): string
    {
        $message = $record->message;

        if (($this->config['grouping']['normalize'] ?? true) === true) {
            $message = $this->normalize($message);
        }

        return $message;
    }

    /**
     * Collapse volatile tokens (ids, uuids, hashes, hex, numbers) so semantically
     * identical messages share a fingerprint.
     */
    private function normalize(string $message): string
    {
        $patterns = [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{uuid}',
            '/\b[0-9a-f]{16,}\b/i' => '{hash}',
            '/0x[0-9a-f]+/i' => '{hex}',
            '/\b\d+\b/' => '{n}',
        ];

        return (string) preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $message,
        );
    }
}
