<?php

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

it('releases the job with backoff on a 429', function () {
    Http::fake(['*' => Http::response(['retry_after' => 3], 429)]);

    $job = new SendDiscordMessage('https://discord.com/api/webhooks/x/y', ['content' => 'x']);

    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('release')->once()->with(3);
    $job->setJob($queueJob);

    $job->handle(app(DiscordWebhook::class));
});

it('fails fast on a 4xx client error', function () {
    Http::fake(['*' => Http::response('not found', 404)]);

    $job = new SendDiscordMessage('https://discord.com/api/webhooks/x/y', ['content' => 'x']);

    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->shouldReceive('fail')->once();
    $job->setJob($queueJob);

    $job->handle(app(DiscordWebhook::class));
});

it('succeeds on a 2xx', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $job = new SendDiscordMessage('https://discord.com/api/webhooks/x/y', ['content' => 'x']);

    $job->handle(app(DiscordWebhook::class));

    Http::assertSentCount(1);
});
