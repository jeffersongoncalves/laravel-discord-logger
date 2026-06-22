## Laravel Discord Logger

### Overview
Laravel Discord Logger is a Monolog channel that sends application logs to Discord via webhooks. It is built for production: it deduplicates repeated errors, groups them by a configurable fingerprint, rate-limits delivery, sends asynchronously through a queued job, and silently no-ops when no webhook URL is configured.

**Namespace:** `JeffersonGoncalves\DiscordLogger`
**Service Provider:** `DiscordLoggerServiceProvider` (auto-discovered)

### Key Concepts
- **No-op safety:** If the channel `url` is empty or `enabled` is false, the handler does nothing and never throws — local/testing environments stay quiet.
- **Deduplication:** Repeats of the same fingerprint inside `deduplication.window` collapse to a single message, then an optional "occurred N×" summary job fires when the window closes.
- **Grouping/fingerprint:** `grouping.strategy` = `message` | `level_message` | `exception` | a callback. `grouping.normalize` collapses ids/UUIDs/hashes.
- **Rate limiting:** Two tiers — `rate_limit.global` and `rate_limit.per_fingerprint` — backed by the configured cache `store`.
- **Async delivery:** A queued `SendDiscordMessage` job honours Discord's 429 `Retry-After`; 4xx fails fast.
- **Per-level webhooks & mentions:** Route a level to a dedicated channel (`webhooks`) and ping with `mentions` (e.g. `@here` on `EMERGENCY`).
- **Redaction:** Context keys matching `redact` are masked before sending.

### Setup
Add a custom channel in `config/logging.php`:

@verbatim
<code-snippet name="logging-channel" lang="php">
'discord' => [
    'driver' => 'custom',
    'via'    => \JeffersonGoncalves\DiscordLogger\Logger::class,
    'level'  => env('LOG_DISCORD_LEVEL', 'error'),
    'url'    => env('LOG_DISCORD_WEBHOOK_URL'),
],
</code-snippet>
@endverbatim

### Usage

@verbatim
<code-snippet name="logging" lang="php">
use Illuminate\Support\Facades\Log;

Log::channel('discord')->error('Payment failed', ['order_id' => 4711]);

// Verify the webhook from the CLI:
// php artisan discord-logger:test --channel=discord
</code-snippet>
@endverbatim

### Architecture
- `Logger` — the channel factory referenced by `via`; builds a Monolog logger with `DiscordHandler`.
- `Handlers\DiscordHandler` — orchestrates: resolve webhook → fingerprint → dedup → rate-limit → convert → dispatch. Wrapped in a recursion guard and try/catch (optionally logs failures to `fallback_channel`).
- `Support\Fingerprinter` / `Deduplicator` / `DiscordRateLimiter` / `Redactor` / `MessageDispatcher`.
- `Converters\RichRecordConverter` (embeds) and `SimpleRecordConverter` (plain content); implement `Converter` for custom output.
- `Jobs\SendDiscordMessage` and `Jobs\SendDeduplicationSummary`.
- `Transport\DiscordWebhook` — the HTTP call.

### Conventions
- State (dedup + rate-limit counters) lives in the cache store named by config `store`; use Redis in production.
- The handler never throws; failures are swallowed or sent to `fallback_channel`.
- Config file: `config/discord-logger.php` (publish with `php artisan discord-logger:install`).
