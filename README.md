<div class="filament-hidden">

![Laravel Discord Logger](https://raw.githubusercontent.com/jeffersongoncalves/laravel-discord-logger/master/art/jeffersongoncalves-laravel-discord-logger.png)

</div>

# Laravel Discord Logger

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-discord-logger.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-discord-logger)
[![Tests](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-discord-logger/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-discord-logger/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-discord-logger/phpstan.yml?branch=master&label=phpstan&style=flat-square)](https://github.com/jeffersongoncalves/laravel-discord-logger/actions/workflows/phpstan.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-discord-logger/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-discord-logger/actions/workflows/fix-php-code-style-issues.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-discord-logger.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-discord-logger)
[![License](https://img.shields.io/packagist/l/jeffersongoncalves/laravel-discord-logger.svg?style=flat-square)](LICENSE.md)

Send Laravel logs to Discord — built for production. A Monolog channel with **deduplication**, **configurable error grouping**, **rate limiting**, **async delivery** with 429 backoff, and a **graceful no-op** when the webhook URL is missing.

## Installation

```bash
composer require jeffersongoncalves/laravel-discord-logger
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="laravel-discord-logger-config"
```

## Configuration

Add a channel to `config/logging.php`:

```php
'discord' => [
    'driver' => 'custom',
    'via'    => \JeffersonGoncalves\DiscordLogger\Logger::class,
    'level'  => env('LOG_DISCORD_LEVEL', 'error'),
    'url'    => env('LOG_DISCORD_WEBHOOK_URL'),
],
```

Stack it onto your default channel so errors fan out:

```php
'stack' => [
    'driver'   => 'stack',
    'channels' => ['single', 'discord'],
    'ignore_exceptions' => false,
],
```

Set the webhook (leave empty in local/testing — nothing will be sent, nothing will break):

```dotenv
LOG_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/yyy
```

## How the improvements work

### Error grouping (fingerprint)

`config/discord-logger.php` → `grouping.strategy`:

- `message` — group by message text
- `level_message` — group by level + message
- `exception` *(default)* — group by exception class + file + line
- a `callback` `fn (\Monolog\LogRecord $record): string` for full control

With `grouping.normalize` on, volatile tokens (numbers, UUIDs, hashes) are stripped before hashing, so `User 123 not found` and `User 456 not found` collapse together.

### Deduplication

Within `deduplication.window` seconds, only the **first** occurrence of a fingerprint is sent. Repeats are counted silently. When the window closes, a single summary (`🔁 occurred N×`) is delivered — turn it off with `deduplication.summary => false`.

### Rate limiting

- `rate_limit.global` — hard cap on total messages app-wide
- `rate_limit.per_fingerprint` — extra guard against a single looping error

### Async delivery

Delivery runs through a queued job by default (`queue.enabled`). Discord 429s are retried respecting `Retry-After`; bad webhooks (4xx) fail fast instead of looping. Set `queue.enabled => false` to send inline (best-effort, errors swallowed).

### State store

Dedup + rate-limit counters live in the cache store named by `store` (null = default). **Use Redis in production** for atomic counters.

## Testing

```bash
composer test
```

## Credits

Inspired by [`marvinlabs/laravel-discord-logger`](https://github.com/vpratfr/laravel-discord-logger).

## License

The MIT License (MIT). See [License File](LICENSE.md).
