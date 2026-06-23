<?php

use JeffersonGoncalves\DiscordLogger\Converters\RichRecordConverter;

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled — or when the channel webhook URL is empty —
    | the handler becomes a no-op and never tries to reach Discord. This is what
    | keeps your local/testing environments quiet without throwing exceptions.
    |
    */
    'enabled' => env('DISCORD_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sender identity
    |--------------------------------------------------------------------------
    */
    'from' => [
        'name' => env('DISCORD_LOGGER_FROM_NAME', env('APP_NAME', 'Laravel Logger')),
        'avatar_url' => env('DISCORD_LOGGER_FROM_AVATAR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-level webhooks
    |--------------------------------------------------------------------------
    |
    | Optionally route specific levels to dedicated channels. Any level not
    | listed here falls back to the channel `url` from config/logging.php.
    |
    | e.g. 'CRITICAL' => env('DISCORD_LOGGER_WEBHOOK_ALERTS'),
    |
    */
    'webhooks' => [
        // 'EMERGENCY' => env('DISCORD_LOGGER_WEBHOOK_ALERTS'),
        // 'CRITICAL'  => env('DISCORD_LOGGER_WEBHOOK_ALERTS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mentions
    |--------------------------------------------------------------------------
    |
    | Prepend a mention to the message for a given level so important errors
    | actually ping someone. Use '@here', '@everyone', '<@&ROLE_ID>' (role) or
    | '<@USER_ID>' (user). allowed_mentions is set automatically.
    |
    */
    'mentions' => [
        // 'EMERGENCY' => '@here',
        // 'CRITICAL'  => '<@&123456789012345678>',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context redaction
    |--------------------------------------------------------------------------
    |
    | Case-insensitive key fragments whose values are masked before sending, so
    | secrets in the log context never leak into Discord.
    |
    | `redact_value_patterns` additionally matches against scalar *values* (in
    | context, the message text and the stacktrace), catching secrets that leak
    | under an innocuous key such as `url` or `auth`. They are full PCRE regexes.
    | Leave the array empty to disable value-based redaction entirely.
    |
    */
    'redact' => [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
    ],
    'redact_placeholder' => '[REDACTED]',
    'redact_value_patterns' => [
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',          // Authorization: Bearer <token>
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', // JWT (header.payload.signature)
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback channel
    |--------------------------------------------------------------------------
    |
    | If delivery to Discord throws, the error is swallowed (logging must never
    | break the app). Set a channel name here (e.g. 'single') to record those
    | failures somewhere instead of losing them silently. null = stay silent.
    |
    */
    'fallback_channel' => env('DISCORD_LOGGER_FALLBACK_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Converter
    |--------------------------------------------------------------------------
    |
    | Turns a Monolog record into a Discord webhook payload. Ship a custom class
    | implementing JeffersonGoncalves\DiscordLogger\Converters\Converter to fully
    | control the message shape.
    |
    */
    'converter' => RichRecordConverter::class,

    /*
    |--------------------------------------------------------------------------
    | Stacktrace rendering
    |--------------------------------------------------------------------------
    | smart | full | none
    */
    'stacktrace' => env('DISCORD_LOGGER_STACKTRACE', 'smart'),

    /*
    |--------------------------------------------------------------------------
    | State store
    |--------------------------------------------------------------------------
    |
    | Cache store used for deduplication + rate-limit counters. null = default
    | store. Redis is strongly recommended in production (atomic increments).
    |
    */
    'store' => env('DISCORD_LOGGER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Async delivery (queue)
    |--------------------------------------------------------------------------
    |
    | When enabled, delivery is pushed to a queued job so the request is never
    | blocked and Discord 429s are retried with backoff. Set enabled=false to
    | send inline (best-effort, errors swallowed).
    |
    */
    'queue' => [
        'enabled' => env('DISCORD_LOGGER_QUEUE', true),
        'connection' => env('DISCORD_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('DISCORD_LOGGER_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error grouping (fingerprint)
    |--------------------------------------------------------------------------
    |
    | How two log records are considered "the same error" for deduplication.
    |
    |   message        -> message text only
    |   level_message  -> level + message text
    |   exception      -> exception class + file + line (falls back to message)
    |
    | normalize: replace digits/UUIDs/hashes in the message before hashing so
    | "User 123 not found" and "User 456 not found" collapse together.
    |
    | callback: a callable(\Monolog\LogRecord $record): string for full control.
    |
    */
    'grouping' => [
        'strategy' => env('DISCORD_LOGGER_GROUPING', 'exception'),
        'normalize' => env('DISCORD_LOGGER_GROUPING_NORMALIZE', true),
        'callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Within `window` seconds, only the FIRST occurrence of a fingerprint is
    | sent. Repeats are counted silently. If `summary` is on, a follow-up
    | "occurred N times" message is delivered when the window closes.
    |
    */
    'deduplication' => [
        'enabled' => env('DISCORD_LOGGER_DEDUP', true),
        'window' => (int) env('DISCORD_LOGGER_DEDUP_WINDOW', 300),
        'summary' => env('DISCORD_LOGGER_DEDUP_SUMMARY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | global          -> hard cap on total messages across the whole app.
    | per_fingerprint -> cap on a single error signature (extra guard on top of
    |                    deduplication for long-lived/looping errors).
    |
    */
    'rate_limit' => [
        'enabled' => env('DISCORD_LOGGER_RATE_LIMIT', true),
        'global' => [
            'max' => (int) env('DISCORD_LOGGER_RATE_GLOBAL_MAX', 30),
            'per_seconds' => (int) env('DISCORD_LOGGER_RATE_GLOBAL_WINDOW', 60),
        ],
        'per_fingerprint' => [
            'max' => (int) env('DISCORD_LOGGER_RATE_FP_MAX', 1),
            'per_seconds' => (int) env('DISCORD_LOGGER_RATE_FP_WINDOW', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Level colors / emojis
    |--------------------------------------------------------------------------
    */
    'colors' => [
        'DEBUG' => 0x607D8B,
        'INFO' => 0x4CAF50,
        'NOTICE' => 0x2196F3,
        'WARNING' => 0xFF9800,
        'ERROR' => 0xF44336,
        'CRITICAL' => 0xE91E63,
        'ALERT' => 0x673AB7,
        'EMERGENCY' => 0x9C27B0,
    ],

    'emojis' => [
        'DEBUG' => '🐛',
        'INFO' => '💡',
        'NOTICE' => '📌',
        'WARNING' => '⚠️',
        'ERROR' => '🔥',
        'CRITICAL' => '💥',
        'ALERT' => '🚨',
        'EMERGENCY' => '💀',
    ],
];
