<?php

namespace JeffersonGoncalves\DiscordLogger\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use JeffersonGoncalves\DiscordLogger\Converters\RichRecordConverter;
use JeffersonGoncalves\DiscordLogger\Support\Redactor;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'discord-logger:test
        {--channel=discord : The logging channel to read the webhook URL from}
        {--exception : Send a sample exception through the real converter (embed + stacktrace.txt attachment)}';

    protected $description = 'Send a test message to the configured Discord webhook';

    public function handle(DiscordWebhook $transport): int
    {
        $channel = $this->option('channel');
        $channel = is_string($channel) ? $channel : 'discord';
        $url = config("logging.channels.{$channel}.url");

        if (! is_string($url) || trim($url) === '') {
            $this->components->error("No webhook URL set for logging channel [{$channel}].");

            return self::FAILURE;
        }

        $payload = $this->option('exception')
            ? $this->exceptionPayload($channel)
            : $this->messagePayload();

        try {
            $response = $transport->send($url, $payload);
        } catch (Throwable $e) {
            // The message carries the webhook URL — never print its token.
            $this->components->error('Delivery failed: '.Redactor::fromConfig((array) config('discord-logger', []))->scrubString($e->getMessage()));

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info('Test message delivered to Discord'.(isset($payload['files']) ? ', with stacktrace.txt attached.' : '.'));

            return self::SUCCESS;
        }

        $this->components->error("Discord responded with HTTP {$response->status()}.");

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(): array
    {
        return array_filter([
            'username' => config('discord-logger.from.name', config('app.name')),
            'avatar_url' => config('discord-logger.from.avatar_url'),
            'embeds' => [[
                'title' => '✅ Discord Logger test',
                'description' => 'If you can read this, your webhook is configured correctly.',
                'color' => (int) config('discord-logger.colors.INFO', 0x4CAF50),
            ]],
        ], fn ($v) => $v !== null);
    }

    /**
     * Same converter + config layering as a real log call (see Logger), minus
     * dedup/rate-limit/queue — so what arrives is what an actual error looks like.
     *
     * @return array<string, mixed>
     */
    private function exceptionPayload(string $channel): array
    {
        $config = array_replace(
            (array) config('discord-logger', []),
            (array) config("logging.channels.{$channel}", []),
        );

        $message = 'Discord Logger test exception';

        return (new RichRecordConverter($config))->convert(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: $channel,
            level: Level::Error,
            message: $message,
            context: ['exception' => new RuntimeException($message)],
        ));
    }
}
