<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\DiscordLogger\Converters\SimpleRecordConverter;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;

beforeEach(function () {
    // Send inline so we can assert the actual webhook calls.
    config()->set('discord-logger.queue.enabled', false);
    config()->set('discord-logger.deduplication.summary', false);
    Cache::flush();
    Http::fake();
});

it('sends a log record to the discord webhook', function () {
    Log::channel('discord')->error('Something broke');

    Http::assertSentCount(1);
});

it('does nothing and never throws when the webhook url is empty', function () {
    config()->set('logging.channels.discord.url', '');

    Log::channel('discord')->error('No url configured');

    Http::assertNothingSent();
});

it('does nothing when disabled', function () {
    config()->set('discord-logger.enabled', false);

    Log::channel('discord')->error('Disabled');

    Http::assertNothingSent();
});

it('deduplicates repeated identical errors within the window', function () {
    Log::channel('discord')->error('Repeated error');
    Log::channel('discord')->error('Repeated error');
    Log::channel('discord')->error('Repeated error');

    Http::assertSentCount(1);
});

it('groups numerically-different messages via normalization', function () {
    config()->set('discord-logger.grouping.strategy', 'message');

    Log::channel('discord')->error('User 123 not found');
    Log::channel('discord')->error('User 456 not found');

    Http::assertSentCount(1);
});

it('sends distinct errors separately', function () {
    config()->set('discord-logger.grouping.strategy', 'message');
    config()->set('discord-logger.grouping.normalize', false);

    Log::channel('discord')->error('First problem');
    Log::channel('discord')->error('Second problem');

    Http::assertSentCount(2);
});

it('enforces the global rate limit', function () {
    config()->set('discord-logger.deduplication.enabled', false);
    config()->set('discord-logger.rate_limit.per_fingerprint.max', 0);
    config()->set('discord-logger.rate_limit.global.max', 2);

    Log::channel('discord')->error('a');
    Log::channel('discord')->error('b');
    Log::channel('discord')->error('c');

    Http::assertSentCount(2);
});

it('respects the configured log level', function () {
    config()->set('logging.channels.discord.level', 'error');

    Log::channel('discord')->info('ignored');
    Log::channel('discord')->error('captured');

    Http::assertSentCount(1);
});

it('dispatches a queued job when queue delivery is enabled', function () {
    config()->set('discord-logger.queue.enabled', true);
    Bus::fake();

    Log::channel('discord')->error('queued error');

    Bus::assertDispatched(SendDiscordMessage::class);
});

it('enforces the per-fingerprint rate limit independently of dedup', function () {
    config()->set('discord-logger.deduplication.enabled', false);
    config()->set('discord-logger.rate_limit.global.max', 100);
    config()->set('discord-logger.rate_limit.per_fingerprint.max', 1);
    config()->set('discord-logger.grouping.strategy', 'message');
    config()->set('discord-logger.grouping.normalize', false);

    Log::channel('discord')->error('looping error');
    Log::channel('discord')->error('looping error');

    Http::assertSentCount(1);
});

it('routes a level to its dedicated webhook', function () {
    $alerts = 'https://discord.com/api/webhooks/alerts/token';
    config()->set('discord-logger.webhooks.ERROR', $alerts);

    Log::channel('discord')->error('routed');

    Http::assertSent(fn ($request) => $request->url() === $alerts);
});

it('adds a mention and allowed_mentions for a configured level', function () {
    config()->set('discord-logger.mentions.ERROR', '@here');

    Log::channel('discord')->error('ping me');

    Http::assertSent(function ($request) {
        return str_contains($request['content'] ?? '', '@here')
            && ($request['allowed_mentions']['parse'] ?? []) === ['everyone'];
    });
});

it('redacts sensitive context keys before sending', function () {
    Log::channel('discord')->error('login attempt', [
        'user' => 'alice',
        'password' => 'hunter2',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, '[REDACTED]') && ! str_contains($body, 'hunter2');
    });
});

it('can use the simple converter', function () {
    config()->set('discord-logger.converter', SimpleRecordConverter::class);

    Log::channel('discord')->error('plain message');

    Http::assertSent(fn ($request) => str_contains($request['content'] ?? '', 'plain message'));
});

it('never throws when the transport fails and stays silent without a fallback', function () {
    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    Log::channel('discord')->error('boom');
})->throwsNoExceptions();
