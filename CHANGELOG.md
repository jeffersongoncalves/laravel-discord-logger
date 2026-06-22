# Changelog

All notable changes to `laravel-discord-logger` will be documented in this file.

## v1.0.0 - 2026-06-22

First stable release.

- Deduplication of repeated errors within a configurable time window, with optional "occurred N×" summary
- Configurable error grouping/fingerprinting (`message`, `level_message`, `exception` or custom callback) with message normalization
- Two-tier rate limiting (global + per-fingerprint)
- Async delivery through a queued job honouring Discord 429 `Retry-After`
- Graceful no-op when the webhook URL is empty or disabled — never throws
