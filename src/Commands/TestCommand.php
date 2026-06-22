<?php

namespace JeffersonGoncalves\DiscordLogger\Commands;

use Illuminate\Console\Command;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'discord-logger:test {--channel=discord : The logging channel to read the webhook URL from}';

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

        $payload = [
            'username' => config('discord-logger.from.name', config('app.name')),
            'avatar_url' => config('discord-logger.from.avatar_url'),
            'embeds' => [[
                'title' => '✅ Discord Logger test',
                'description' => 'If you can read this, your webhook is configured correctly.',
                'color' => (int) config('discord-logger.colors.INFO', 0x4CAF50),
            ]],
        ];

        try {
            $response = $transport->send($url, array_filter($payload, fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $this->components->error('Delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info('Test message delivered to Discord.');

            return self::SUCCESS;
        }

        $this->components->error("Discord responded with HTTP {$response->status()}.");

        return self::FAILURE;
    }
}
