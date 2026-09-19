<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;
use JeffersonGoncalves\DiscordLogger\Support\MessageDispatcher;

it('queues delivery by default', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher([]);
    $dispatcher->send('https://discord.com/api/webhooks/x/y', ['content' => 'hi']);

    Bus::assertDispatched(SendDiscordMessage::class);
});

it('sends inline when queueing is disabled', function () {
    Http::fake();

    $dispatcher = new MessageDispatcher(['queue' => ['enabled' => false]]);
    $dispatcher->send('https://discord.com/api/webhooks/x/y', ['content' => 'hi']);

    Http::assertSentCount(1);
});

it('throws when an inline send fails, so the caller can report it', function () {
    Http::fake(['*' => Http::response('bad', 400)]);

    $dispatcher = new MessageDispatcher(['queue' => ['enabled' => false]]);

    expect(fn () => $dispatcher->send('https://discord.com/api/webhooks/x/y', ['content' => 'hi']))
        ->toThrow(RequestException::class);
});

it('dispatches onto the configured connection and queue', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher(['queue' => [
        'enabled' => true,
        'connection' => 'redis',
        'queue' => 'discord-alerts',
    ]]);
    $dispatcher->send('https://discord.com/api/webhooks/x/y', ['content' => 'hi']);

    Bus::assertDispatched(SendDiscordMessage::class, function (SendDiscordMessage $job) {
        return $job->connection === 'redis' && $job->queue === 'discord-alerts';
    });
});

it('queues a pre-built job as-is via queue()', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher([]);
    $job = new SendDiscordMessage('https://discord.com/api/webhooks/x/y', ['content' => 'hi']);

    $dispatcher->queue($job);

    Bus::assertDispatched(SendDiscordMessage::class);
});
