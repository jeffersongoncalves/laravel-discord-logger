<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    config()->set('discord-logger.queue.enabled', false);
    config()->set('discord-logger.deduplication.enabled', false);
    config()->set('discord-logger.rate_limit.enabled', false);
    Cache::flush();
    Http::fake();
    event(new Looping('sync', 'default')); // reset any job context left behind
});

afterEach(function () {
    event(new Looping('sync', 'default'));
    event('Laravel\Octane\Events\RequestTerminated');
});

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

it('names the Livewire component a /livewire/update request targeted', function () {
    Route::post('/livewire/update', function () {
        Log::channel('discord')->error('component blew up');

        return 'ok';
    })->name('livewire.update');

    $snapshot = fn (string $name) => json_encode(['data' => [], 'memo' => ['name' => $name, 'path' => 'financeiro/boletos']]);

    $this->postJson('/livewire/update', ['components' => [
        ['snapshot' => $snapshot('boletos.table'), 'updates' => [], 'calls' => []],
        ['snapshot' => $snapshot('boletos.filters'), 'updates' => [], 'calls' => []],
    ]])->assertOk();

    expect(sentFields()['Livewire'] ?? '')
        ->toContain('**component:** `boletos.table, boletos.filters`')
        ->toContain('**path:** `financeiro/boletos`');
});

it('omits Livewire for a regular request', function () {
    Route::post('/orders', function () {
        Log::channel('discord')->error('plain post');

        return 'ok';
    });

    $this->postJson('/orders', ['components' => 'not livewire'])->assertOk();

    expect(sentFields())->toHaveKey('Request')->not->toHaveKey('Livewire');
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
    $job = fakeJob();

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    Log::channel('discord')->error('after the job');

    expect(sentFields())->not->toHaveKey('Job')->toHaveKey('Command');
});

function syncJob(): SyncJob
{
    return new SyncJob(app(), json_encode(['displayName' => 'App\\Jobs\\SendReceipt', 'job' => 'x']), 'sync', 'default');
}

it('restores the outer job after a nested sync job finishes', function () {
    $outer = fakeJob();
    $inner = syncJob();

    event(new JobProcessing('redis', $outer));
    event(new JobProcessing('sync', $inner));
    event(new JobProcessed('sync', $inner));

    Log::channel('discord')->error('back in the outer job');

    expect(sentFields()['Job'])
        ->toContain('App\\Jobs\\ChargeOrder')
        ->not->toContain('SendReceipt');
});

it('restores the outer job after a nested sync job fails and the parent catches it', function () {
    $inner = syncJob();

    event(new JobProcessing('redis', fakeJob()));
    event(new JobProcessing('sync', $inner));
    event(new JobExceptionOccurred('sync', $inner, new RuntimeException('x')));

    Log::channel('discord')->error('parent carries on');

    expect(sentFields()['Job'])
        ->toContain('App\\Jobs\\ChargeOrder')
        ->not->toContain('SendReceipt');
});

it('drops a failed top-level sync job once its exception leaves it', function () {
    $job = syncJob();

    event(new JobProcessing('sync', $job));
    event(new JobExceptionOccurred('sync', $job, new RuntimeException('x')));

    Log::channel('discord')->error('caller carries on');

    expect(sentFields())->not->toHaveKey('Job')->toHaveKey('Command');
});

it('drops a failed worker job when the next worker job starts', function () {
    $failed = fakeJob();
    $next = Mockery::mock(Job::class);
    $next->allows([
        'resolveName' => 'App\\Jobs\\NextJob',
        'getQueue' => 'default',
        'attempts' => 1,
        'getJobId' => 'job-43',
        'payload' => [],
    ]);

    event(new JobProcessing('redis', $failed));
    event(new JobFailed('redis', $failed, new RuntimeException('x')));
    event(new JobProcessing('redis', $next));
    event(new JobProcessed('redis', $next));

    Log::channel('discord')->error('between jobs');

    expect(sentFields())->not->toHaveKey('Job');
});

it('treats an Octane request as HTTP even under the CLI SAPI, before routing', function () {
    event('Laravel\Octane\Events\RequestReceived');

    Log::channel('discord')->error('from a middleware');

    expect(sentFields())->toHaveKey('Request')->not->toHaveKey('Command');
});

it('reports the command name Artisan resolved, never its arguments', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', '--password', 'hunter2', 'user:create', 'positional-secret'];
    event(new CommandStarting('user:create', new ArrayInput([]), new NullOutput));

    try {
        Log::channel('discord')->error('from a command');
    } finally {
        event(new CommandFinished('user:create', new ArrayInput([]), new NullOutput, 0));
        $_SERVER['argv'] = $argv;
    }

    expect(sentFields()['Command'])->toBe('**command:** `user:create`');
});

it('falls back to the script name alone, never parsing argv', function () {
    $argv = $_SERVER['argv'];
    // An option value before the command name: parsing argv would pick "hunter2".
    $_SERVER['argv'] = ['artisan', '--password', 'hunter2', 'user:create'];

    try {
        Log::channel('discord')->error('outside artisan events');
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(sentFields()['Command'])->toBe('**command:** `artisan`');
});

it('omits runtime context when disabled', function () {
    config()->set('discord-logger.runtime_context', false);

    Log::channel('discord')->error('quiet');

    expect(sentFields())->not->toHaveKey('Command');
});

class SessionDiscordContext
{
    public function __invoke(): array
    {
        return [
            'Empresa' => 'ACME Ltda',
            'Usuário' => ['id' => 293, 'name' => 'Maria', 'password' => 'hunter2'],
        ];
    }
}

it('adds the fields of a custom context resolver, redacted', function () {
    config()->set('discord-logger.context_resolver', SessionDiscordContext::class);

    Log::channel('discord')->error('with custom context');

    expect(sentFields())
        ->toHaveKey('Command')
        ->and(sentFields()['Empresa'])->toBe('`ACME Ltda`')
        ->and(sentFields()['Usuário'])
        ->toContain('**id:** `293`')
        ->toContain('**name:** `Maria`')
        ->not->toContain('hunter2');
});

it('keeps the custom context with runtime context off, and survives a failing resolver', function () {
    config()->set('discord-logger.runtime_context', false);
    config()->set('discord-logger.context_resolver', SessionDiscordContext::class);

    Log::channel('discord')->error('custom only');

    expect(sentFields())->toHaveKey('Empresa')->not->toHaveKey('Command');

    Http::fake();
    app()->bind(SessionDiscordContext::class, fn () => fn () => throw new RuntimeException('session gone'));

    Log::channel('discord')->error('resolver throws');

    expect(sentFields())->not->toHaveKey('Empresa');
});

it('renders Laravel Context data as an Extra field', function () {
    Context::add('tenant', 'acme');

    Log::channel('discord')->error('with context');

    expect(sentFields()['Extra'] ?? '')->toContain('acme');
});
