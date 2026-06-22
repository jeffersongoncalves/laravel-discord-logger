<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;

beforeEach(function () {
    // Send inline so we can assert the actual webhook calls.
    config()->set('discord-logger.queue.enabled', false);
    config()->set('discord-logger.deduplication.summary', false);
    RateLimiter::clear('discord-logger:rl:global');
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
