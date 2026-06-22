# Changelog

All notable changes to `laravel-discord-logger` will be documented in this file.

## v1.1.0 - 2026-06-22

### What's new

#### Correctness

- **Rate limiter** now uses the configured cache `store` (was the default store) — dedup and rate limiting finally share state.
- **Recursion guard** — a failed delivery that logs back can no longer re-enter the handler.
- Validate the configured `converter` implements `Converter` before instantiating.

#### Features

- **Per-level webhooks** — route a level (e.g. `CRITICAL`) to its own Discord channel.
- **Mentions per level** — `@here` / `@everyone` / role / user, with `allowed_mentions` set automatically so important errors actually ping.
- **Context redaction** — mask sensitive keys (`password`, `token`, …) before sending.
- **Embed size guard** — trims fields to stay under Discord's 6000-char limit (no more 400s).
- **`fallback_channel`** — record swallowed delivery failures instead of losing them.
- **Commands** — `discord-logger:test` (verify a webhook) and `discord-logger:install`.

#### Quality

- PHPStan bumped to **level 8** (clean).
- **30 tests** covering routing, mentions, redaction, rate limiting, 429 backoff, embed clamp, converters and commands.
- Repo hygiene: FUNDING, SECURITY, CONTRIBUTING, Dependabot and a Laravel Boost guideline.

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-discord-logger/compare/v1.0.0...v1.1.0

## v1.0.0 - 2026-06-22

First stable release.

- Deduplication of repeated errors within a configurable time window, with optional "occurred N×" summary
- Configurable error grouping/fingerprinting (`message`, `level_message`, `exception` or custom callback) with message normalization
- Two-tier rate limiting (global + per-fingerprint)
- Async delivery through a queued job honouring Discord 429 `Retry-After`
- Graceful no-op when the webhook URL is empty or disabled — never throws
