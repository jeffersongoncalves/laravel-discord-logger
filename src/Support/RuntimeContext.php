<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Captures where a log call came from: the HTTP request, the queued job or the
 * artisan command being executed at log time.
 */
class RuntimeContext
{
    /**
     * Jobs being processed, innermost last. A stack, because a sync job
     * dispatched from another job runs nested inside it.
     *
     * @var list<array{ref: int, data: array<string, mixed>}>
     */
    private static array $jobs = [];

    /** An Octane request is in flight (Octane runs HTTP under the CLI SAPI). */
    private static bool $octaneRequest = false;

    /**
     * Artisan commands running, innermost last (Artisan::call() nests).
     *
     * @var list<string>
     */
    private static array $commands = [];

    public static function listen(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            // Only a sync job can run nested; any other job is top-level, so
            // whatever is still stacked (a worker job that failed) is over.
            if (! $event->job instanceof SyncJob) {
                self::$jobs = [];
            }

            self::$jobs[] = [
                'ref' => spl_object_id($event->job),
                'data' => [
                    'name' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'attempts' => $event->job->attempts(),
                    'id' => $event->job->getJobId(),
                ],
            ];
        });

        // Success: pop back to the parent job.
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => self::pop($event->job));

        // Failure of a sync job: its exception is rethrown to the caller, which
        // reports or handles it in its own scope — so pop now. A worker job's
        // failure pops nothing: the worker reports the exception AFTER every job
        // event fired, and that log must say which job failed. It is dropped
        // when the next worker job starts, or at the next request/worker loop.
        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            if ($event->job instanceof SyncJob) {
                self::pop($event->job);
            }
        });

        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            self::$commands[] = (string) $event->command;
        });
        $events->listen(CommandFinished::class, function (): void {
            array_pop(self::$commands);
        });

        $events->listen([Looping::class, RequestHandled::class], function (): void {
            self::$jobs = [];
        });

        // Class names as strings: no dependency on Octane, no-op without it.
        $events->listen('Laravel\Octane\Events\RequestReceived', function (): void {
            self::$octaneRequest = true;
            self::$jobs = [];
        });
        $events->listen('Laravel\Octane\Events\RequestTerminated', function (): void {
            self::$octaneRequest = false;
        });
    }

    private static function pop(Job $job): void
    {
        $ref = spl_object_id($job);
        $refs = array_column(self::$jobs, 'ref');
        $index = array_search($ref, $refs, true);

        if ($index !== false) {
            self::$jobs = array_slice(self::$jobs, 0, $index);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function capture(): array
    {
        $request = $this->attempt(fn () => $this->request());
        $job = self::$jobs === [] ? null : self::$jobs[array_key_last(self::$jobs)]['data'];

        return array_filter([
            'request' => $request,
            'livewire' => $request === null ? null : $this->attempt(fn () => $this->livewire()),
            'job' => $job,
            'command' => $request === null && $job === null ? $this->attempt(fn () => $this->command()) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(): ?array
    {
        $request = request();

        // In the console `request()` is a synthetic instance — only report it
        // for a real HTTP request: outside the CLI SAPI, inside an Octane
        // request, or once routed (HTTP tests run under the CLI too).
        $http = ! app()->runningInConsole() || self::$octaneRequest || $request->route() !== null;

        if (! $http) {
            return null;
        }

        $route = $request->route();

        return array_filter([
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => is_object($route) ? $route->getName() : null,
            // hasUser(): never trigger a user lookup (a DB query) from the log
            // path — only report a user that was already resolved.
            'user' => $this->attempt(fn () => auth()->hasUser() ? auth()->id() : null),
            'ip' => $request->ip(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A Livewire update request all goes to one URL (/livewire/update), so name
     * the component(s) it targeted, read from each component's snapshot. Detected
     * by payload, not route name: apps can move the update endpoint.
     *
     * @return array<string, string>|null
     */
    private function livewire(): ?array
    {
        $components = request()->input('components');

        if (! is_array($components)) {
            return null;
        }

        $names = [];
        $path = null;

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], true)
                : null;
            $name = $snapshot['memo']['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
                $path ??= is_string($snapshot['memo']['path'] ?? null) ? $snapshot['memo']['path'] : null;
            }
        }

        if ($names === []) {
            return null;
        }

        return array_filter([
            'component' => implode(', ', array_unique($names)),
            'path' => $path,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The command name as Artisan resolved it, else just the script name —
     * never parsed from argv: an option value (`--password hunter2 user:create`)
     * can't be told apart from the command name without the command's definition.
     *
     * @return array<string, string>|null
     */
    private function command(): ?array
    {
        if (! app()->runningInConsole()) {
            return null;
        }

        if (self::$commands !== []) {
            return ['command' => self::$commands[array_key_last(self::$commands)]];
        }

        $script = $_SERVER['argv'][0] ?? null;

        return is_string($script) && $script !== '' ? ['command' => basename($script)] : null;
    }

    /**
     * Any piece failing (DB down, auth misconfigured...) must never cost us the
     * log itself — drop that piece and keep going.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    private function attempt(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
