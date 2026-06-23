<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\DiscordLogger\Converters\SimpleRecordConverter;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDeduplicationSummary;
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

it('adds allowed_mentions parse=roles for a role mention', function () {
    config()->set('discord-logger.mentions.ERROR', '<@&123456789012345678>');

    Log::channel('discord')->error('ping the role');

    Http::assertSent(function ($request) {
        return str_contains($request['content'] ?? '', '<@&123456789012345678>')
            && ($request['allowed_mentions']['parse'] ?? []) === ['roles'];
    });
});

it('adds allowed_mentions parse=users for a user mention', function () {
    config()->set('discord-logger.mentions.ERROR', '<@123456789012345678>');

    Log::channel('discord')->error('ping the user');

    Http::assertSent(function ($request) {
        return str_contains($request['content'] ?? '', '<@123456789012345678>')
            && ($request['allowed_mentions']['parse'] ?? []) === ['users'];
    });
});

it('falls back to the rich converter when the configured converter is invalid', function () {
    config()->set('discord-logger.converter', 'This\\Class\\Does\\Not\\Exist');

    Log::channel('discord')->error('invalid converter');

    Http::assertSent(fn ($request) => isset($request['embeds']) && $request['embeds'] !== []);
});

it('records the failure to the fallback channel when delivery throws', function () {
    $path = tempnam(sys_get_temp_dir(), 'discord-fallback-').'.log';

    config()->set('logging.channels.fallbacktest', [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
    ]);
    config()->set('discord-logger.fallback_channel', 'fallbacktest');

    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    Log::channel('discord')->error('boom');

    expect(file_get_contents($path))->toContain('Discord logger delivery failed');

    @unlink($path);
});

it('does not recurse when the fallback channel is the discord channel itself', function () {
    // A failed send routed back into the same channel must hit the recursion
    // guard (self::$handling) and return, not loop forever.
    config()->set('discord-logger.fallback_channel', 'discord');

    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    Log::channel('discord')->error('boom');
})->throwsNoExceptions();

it('schedules the dedup summary with a delay equal to the window', function () {
    config()->set('discord-logger.queue.enabled', true);
    config()->set('discord-logger.deduplication.enabled', true);
    config()->set('discord-logger.deduplication.summary', true);
    config()->set('discord-logger.deduplication.window', 123);
    Bus::fake();

    Log::channel('discord')->error('delayed summary');

    Bus::assertDispatched(
        SendDeduplicationSummary::class,
        fn (SendDeduplicationSummary $job) => $job->delay === 123,
    );
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
