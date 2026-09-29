<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('discord-logger.queue.enabled', false);
    config()->set('discord-logger.deduplication.enabled', false);
    config()->set('discord-logger.rate_limit.enabled', false);
    Cache::flush();
    Http::fake();
    event(new Looping('sync', 'default')); // reset any job context left behind
});

afterEach(fn () => event(new Looping('sync', 'default')));

/** @return array<string, string> field name => value of the single sent embed */
function sentFields(): array
{
    $fields = [];

    Http::assertSent(function (Request $request) use (&$fields) {
        $fields = array_column($request['embeds'][0]['fields'] ?? [], 'value', 'name');

        return true;
    });

    return $fields;
}

function fakeJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->allows([
        'resolveName' => 'App\\Jobs\\ChargeOrder',
        'getQueue' => 'payments',
        'attempts' => 3,
        'getJobId' => 'job-42',
        'payload' => [], // read by Laravel's own Context listener
    ]);

    return $job;
}

it('adds the HTTP request the log came from, redacted', function () {
    Route::get('/orders/{id}', function () {
        Log::channel('discord')->error('checkout failed');

        return 'ok';
    })->name('orders.show');

    $this->get('/orders/7?token=abc123')->assertOk();

    $request = sentFields()['Request'];

    expect($request)
        ->toContain('**method:** `GET`')
        ->toContain('**route:** `orders.show`')
        ->toContain('/orders/7')
        ->not->toContain('abc123');
});

it('adds the queued job the log came from and keeps it through a failure', function () {
    $job = fakeJob();

    event(new JobProcessing('redis', $job));
    event(new JobExceptionOccurred('redis', $job, new RuntimeException('x')));
    event(new JobFailed('redis', $job, new RuntimeException('x')));

    // The worker reports the job's exception only after JobFailed fired.
    Log::channel('discord')->error('charge failed');

    expect(sentFields())
        ->toHaveKey('Job')
        ->not->toHaveKey('Command')
        ->and(sentFields()['Job'])
        ->toContain('App\\Jobs\\ChargeOrder')
        ->toContain('**queue:** `payments`')
        ->toContain('**attempts:** `3`');
});

it('forgets the job once it was processed', function () {
    event(new JobProcessing('redis', fakeJob()));
    event(new JobProcessed('redis', fakeJob()));

    Log::channel('discord')->error('after the job');

    expect(sentFields())->not->toHaveKey('Job')->toHaveKey('Command');
});

it('omits runtime context when disabled', function () {
    config()->set('discord-logger.runtime_context', false);

    Log::channel('discord')->error('quiet');

    expect(sentFields())->not->toHaveKey('Command');
});

it('renders Laravel Context data as an Extra field', function () {
    Context::add('tenant', 'acme');

    Log::channel('discord')->error('with context');

    expect(sentFields()['Extra'] ?? '')->toContain('acme');
});
