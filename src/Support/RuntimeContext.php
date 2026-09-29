<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
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
    /** @var array<string, mixed>|null */
    private static ?array $job = null;

    public static function listen(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            self::$job = [
                'name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
                'id' => $event->job->getJobId(),
            ];
        });

        // Cleared on success and at the start of each worker loop — never on
        // failure: the worker reports a job's exception AFTER JobFailed fires,
        // and that log is the one that most needs to say which job it came from.
        // ponytail: a failed *sync* job keeps its context until the next job/loop.
        $events->listen([JobProcessed::class, Looping::class], function (): void {
            self::$job = null;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function capture(): array
    {
        return array_filter([
            'request' => $this->attempt(fn () => $this->request()),
            'job' => self::$job,
            'command' => self::$job === null ? $this->attempt(fn () => $this->command()) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(): ?array
    {
        $request = request();

        // In the console `request()` is a synthetic instance — only report it
        // when a real HTTP request is being handled (or routed, under tests).
        if (app()->runningInConsole() && $request->route() === null) {
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
     * @return array<string, string>|null
     */
    private function command(): ?array
    {
        if (! app()->runningInConsole()) {
            return null;
        }

        $argv = $_SERVER['argv'] ?? null;

        return is_array($argv) && $argv !== []
            ? ['command' => implode(' ', array_map('strval', $argv))]
            : null;
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
