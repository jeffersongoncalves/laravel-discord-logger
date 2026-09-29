<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
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

    public static function listen(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
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

        // Success: pop back to the parent job. Failure pops nothing — the worker
        // reports a job's exception AFTER every job event has fired, and that log
        // is the one that most needs to say which job it came from. Leftovers are
        // popped with their parent, or reset at the next request/worker loop.
        // ponytail: a failed nested job whose exception the parent catches keeps
        // tagging the parent's later logs until the parent finishes.
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => self::pop($event->job));

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
     * The script and command name only — never the arguments: a positional or
     * space-separated value (`--password hunter2`) can't be redacted reliably.
     *
     * @return array<string, string>|null
     */
    private function command(): ?array
    {
        if (! app()->runningInConsole()) {
            return null;
        }

        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv) || $argv === []) {
            return null;
        }

        $argv = array_map('strval', $argv);
        $name = current(array_filter(array_slice($argv, 1), fn ($arg) => ! str_starts_with($arg, '-')));

        return ['command' => trim(basename($argv[0]).' '.($name ?: ''))];
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
