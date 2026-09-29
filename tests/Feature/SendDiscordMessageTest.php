<?php

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\DiscordLogger\Jobs\SendDiscordMessage;
use JeffersonGoncalves\DiscordLogger\Logger;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

const WEBHOOK = 'https://discord.com/api/webhooks/123456/sEcReT-ToKeN_abc';

function queuedAt(int $attempt): array
{
    $job = new SendDiscordMessage(WEBHOOK, ['content' => 'x']);

    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->allows(['attempts' => $attempt]);
    $job->setJob($queueJob);

    return [$job, $queueJob];
}

function discordUnreachable(): void
{
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Resolving timed out after 10001 milliseconds for '.WEBHOOK);
    });
}

function fallbackLog(): string
{
    $path = tempnam(sys_get_temp_dir(), 'discord-fallback-').'.log';

    config()->set('logging.channels.fallbacktest', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('discord-logger.fallback_channel', 'fallbacktest');

    return $path;
}

it('releases the job with backoff on a 429', function () {
    Http::fake(['*' => Http::response(['retry_after' => 3], 429)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(3);

    $job->handle(app(DiscordWebhook::class));
});

it('releases the job using the Retry-After header on a 429', function () {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(7);

    $job->handle(app(DiscordWebhook::class));
});

it('fails fast on a 4xx client error', function () {
    Http::fake(['*' => Http::response('not found', 404)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('fail')->once();

    $job->handle(app(DiscordWebhook::class));
});

it('succeeds on a 2xx', function () {
    Http::fake(['*' => Http::response('', 204)]);

    (new SendDiscordMessage(WEBHOOK, ['content' => 'x']))->handle(app(DiscordWebhook::class));

    Http::assertSentCount(1);
});

it('retries an unreachable Discord with increasing backoff instead of throwing', function (int $attempt, int $delay) {
    discordUnreachable();
    [$job, $queueJob] = queuedAt($attempt);

    $queueJob->shouldReceive('release')->once()->with($delay);

    $job->handle(app(DiscordWebhook::class));
})->with([
    'attempt 1' => [1, 10],
    'attempt 2' => [2, 30],
    'attempt 4' => [4, 120],
]);

it('retries a Discord 5xx with backoff', function () {
    Http::fake(['*' => Http::response('', 503)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(10);

    $job->handle(app(DiscordWebhook::class));
});

it('gives up after the last attempt: fallback only, token masked, job deleted', function () {
    $path = fallbackLog();
    discordUnreachable();
    [$job, $queueJob] = queuedAt(5);

    $queueJob->shouldReceive('delete')->once();
    $queueJob->shouldNotReceive('release');

    $job->handle(app(DiscordWebhook::class));

    expect(file_get_contents($path))
        ->toContain('Discord logger delivery failed: cURL error 28')
        ->toContain('discord.com/api/webhooks/123456/[REDACTED]')
        ->toContain('gave up after 5 attempts')
        ->not->toContain('sEcReT-ToKeN_abc');

    @unlink($path);
});

it('gives up on a 429 at the last attempt instead of releasing past $tries', function () {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);
    [$job, $queueJob] = queuedAt(5);

    $queueJob->shouldReceive('delete')->once();
    $queueJob->shouldNotReceive('release');

    $job->handle(app(DiscordWebhook::class));
});

it('never writes the failure to a fallback that reaches Discord', function (array $channel) {
    config()->set('logging.channels.loopy', $channel);
    config()->set('discord-logger.fallback_channel', 'loopy');
    discordUnreachable();
    Log::spy();

    [$job, $queueJob] = queuedAt(5);
    $queueJob->shouldReceive('delete')->once();

    $job->handle(app(DiscordWebhook::class));

    Log::shouldNotHaveReceived('channel');
})->with([
    'the discord channel' => [['driver' => 'custom', 'via' => Logger::class, 'url' => WEBHOOK]],
    'a stack containing it' => [['driver' => 'stack', 'channels' => ['single', 'discord']]],
]);
