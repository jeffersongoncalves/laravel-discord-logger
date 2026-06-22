<?php

namespace JeffersonGoncalves\DiscordLogger\Handlers;

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
        // Logging is infrastructure — it must never throw back into the app.
        try {
            $this->process($record);
        } catch (Throwable) {
            // Swallow: a broken logger should not take down the request.
        }
    }

    private function process(LogRecord $record): void
    {
        $webhook = $this->webhook();

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
        $this->dispatcher->send($webhook, $converter->convert($record));

        $this->scheduleSummary($webhook, $record, $fingerprint, $converter);
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

    private function webhook(): ?string
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        $url = $this->config['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function converter(): Converter
    {
        $class = $this->config['converter'] ?? RichRecordConverter::class;

        return new $class($this->config);
    }
}
