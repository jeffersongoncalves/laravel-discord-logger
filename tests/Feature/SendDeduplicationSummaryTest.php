<?php

use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDeduplicationSummary;
use JeffersonGoncalves\DiscordLogger\Support\Deduplicator;

it('resolves and posts the roll-up through the container without binding errors', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $config = config('discord-logger');
    $config['deduplication']['enabled'] = true;

    $fingerprint = 'dedup-fingerprint';

    // Prime two occurrences so the roll-up actually fires.
    $dedup = new Deduplicator($config);
    $dedup->decide($fingerprint);
    $dedup->decide($fingerprint);

    $job = new SendDeduplicationSummary(
        webhook: 'https://discord.com/api/webhooks/x/y',
        fingerprint: $fingerprint,
        title: 'Boom',
        color: 0xF44336,
        config: $config,
    );

    // app()->call mirrors how the queue worker invokes handle(): Deduplicator
    // requires `array $config` and used to throw BindingResolutionException here.
    app()->call([$job, 'handle']);

    Http::assertSentCount(1);
});

it('skips the roll-up when the error did not repeat', function () {
    Http::fake();

    $config = config('discord-logger');
    $config['deduplication']['enabled'] = true;

    $fingerprint = 'single-occurrence';
    (new Deduplicator($config))->decide($fingerprint); // count = 1

    $job = new SendDeduplicationSummary(
        webhook: 'https://discord.com/api/webhooks/x/y',
        fingerprint: $fingerprint,
        title: 'Boom',
        color: 0xF44336,
        config: $config,
    );

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});
