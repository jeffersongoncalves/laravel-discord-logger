<?php

namespace JeffersonGoncalves\DiscordLogger\Handlers;

use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\DiscordLogger\Converters\Converter;
use JeffersonGoncalves\DiscordLogger\Converters\RichRecordConverter;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDeduplicationSummary;
use JeffersonGoncalves\DiscordLogger\Support\Deduplicator;
use JeffersonGoncalves\DiscordLogger\Support\DiscordRateLimiter;
use JeffersonGoncalves\DiscordLogger\Support\Fingerprinter;
use JeffersonGoncalves\DiscordLogger\Support\MessageDispatcher;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Throwable;

class DiscordHandler extends AbstractProcessingHandler
{
    /** Guards against logging-while-logging recursion (a failed send that logs back here). */
    private static bool $handling = false;

    private Fingerprinter $fingerprinter;

    private Deduplicator $deduplicator;

    private DiscordRateLimiter $rateLimiter;

    private MessageDispatcher $dispatcher;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        parent::__construct(
            Monolog::toMonologLevel($config['level'] ?? 'debug'),
            $config['bubble'] ?? true,
        );

        $this->fingerprinter = new Fingerprinter($config);
        $this->deduplicator = new Deduplicator($config);
        $this->rateLimiter = new DiscordRateLimiter($config);
        $this->dispatcher = new MessageDispatcher($config);
    }

    protected function write(LogRecord $record): void
    {
        // Logging is infrastructure — it must never throw back into the app, and
        // a failure here must never re-enter this handler.
        if (self::$handling) {
            return;
        }

        self::$handling = true;

        try {
            $this->process($record);
        } catch (Throwable $e) {
            $this->reportFailure($e);
        } finally {
            self::$handling = false;
        }
    }

    private function process(LogRecord $record): void
    {
        $level = $record->level->getName();
        $webhook = $this->webhook($level);

        // Missing/empty URL or disabled => silent no-op (local & testing friendly).
        if ($webhook === null) {
            return;
        }

        $fingerprint = $this->fingerprinter->fingerprint($record);

        $decision = $this->deduplicator->decide($fingerprint);
        if (! $decision->send) {
            return; // A repeat inside the dedup window — counted, not sent.
        }

        if (! $this->rateLimiter->allow($fingerprint)) {
            return; // Over the volume cap — drop.
        }

        $converter = $this->converter();
        $payload = $this->withMentions($converter->convert($record), $level);

        $this->dispatcher->send($webhook, $payload);

        $this->scheduleSummary($webhook, $record, $fingerprint, $converter);
    }

    /**
     * Inject a mention (and matching allowed_mentions) for the given level so
     * critical errors actually alert, not just appear silently.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withMentions(array $payload, string $level): array
    {
        $mention = $this->config['mentions'][$level] ?? null;

        if (! is_string($mention) || trim($mention) === '') {
            return $payload;
        }

        $content = trim(($payload['content'] ?? '').' '.$mention);
        $payload['content'] = $content;

        $parse = [];
        if (str_contains($mention, '@everyone') || str_contains($mention, '@here')) {
            $parse[] = 'everyone';
        }
        if (str_contains($mention, '<@&')) {
            $parse[] = 'roles';
        }
        if (preg_match('/<@\d+>/', $mention)) {
            $parse[] = 'users';
        }

        $payload['allowed_mentions'] = ['parse' => array_values(array_unique($parse))];

        return $payload;
    }

    private function scheduleSummary(string $webhook, LogRecord $record, string $fingerprint, Converter $converter): void
    {
        if (($this->config['deduplication']['enabled'] ?? true) !== true) {
            return;
        }

        if (($this->config['deduplication']['summary'] ?? true) !== true) {
            return;
        }

        // The summary relies on a delayed job — only meaningful with a real queue.
        if (($this->config['queue']['enabled'] ?? true) !== true) {
            return;
        }

        $level = $record->level->getName();
        $title = $converter instanceof RichRecordConverter
            ? $converter->title($record)
            : $level;

        $job = new SendDeduplicationSummary(
            webhook: $webhook,
            fingerprint: $fingerprint,
            title: $title,
            color: (int) ($this->config['colors'][$level] ?? 0x607D8B),
            config: $this->config,
        );

        $this->dispatcher->queue(
            $job->delay((int) ($this->config['deduplication']['window'] ?? 300)),
        );
    }

    /**
     * Resolve the webhook for a level: a per-level override wins, otherwise the
     * channel's default URL. Returns null when disabled or no URL is set.
     */
    private function webhook(string $level): ?string
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        $url = $this->config['webhooks'][$level] ?? $this->config['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function converter(): Converter
    {
        $class = $this->config['converter'] ?? RichRecordConverter::class;

        if (! is_string($class) || ! is_a($class, Converter::class, true)) {
            $class = RichRecordConverter::class;
        }

        return new $class($this->config);
    }

    private function reportFailure(Throwable $e): void
    {
        $channel = $this->config['fallback_channel'] ?? null;

        if (! is_string($channel) || $channel === '') {
            return; // No fallback configured — stay silent rather than risk a loop.
        }

        // Safe: $handling is still true here, so this cannot re-enter the handler.
        Log::channel($channel)->warning('Discord logger delivery failed: '.$e->getMessage());
    }
}
