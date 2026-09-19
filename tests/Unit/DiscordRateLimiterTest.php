<?php

use Illuminate\Support\Facades\Cache;
use JeffersonGoncalves\DiscordLogger\Support\DiscordRateLimiter;

beforeEach(function () {
    Cache::flush();
});

it('always allows when rate limiting is disabled', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => ['enabled' => false]]);

    expect($limiter->allow('fp-1'))->toBeTrue();
});

it('allows up to the global max within the window', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => [
        'global' => ['max' => 2, 'per_seconds' => 60],
        'per_fingerprint' => ['max' => 0],
    ]]);

    expect($limiter->allow('fp-a'))->toBeTrue()
        ->and($limiter->allow('fp-b'))->toBeTrue()
        ->and($limiter->allow('fp-c'))->toBeFalse();
});

it('allows up to the per-fingerprint max within the window', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => [
        'global' => ['max' => 100, 'per_seconds' => 60],
        'per_fingerprint' => ['max' => 1, 'per_seconds' => 300],
    ]]);

    expect($limiter->allow('fp-1'))->toBeTrue()
        ->and($limiter->allow('fp-1'))->toBeFalse();
});

it('treats a max of 0 as unlimited for that bucket', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => [
        'global' => ['max' => 0],
        'per_fingerprint' => ['max' => 0],
    ]]);

    foreach (range(1, 10) as $i) {
        expect($limiter->allow('fp-1'))->toBeTrue();
    }
});

it('does not consume the global budget when blocked by the per-fingerprint cap', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => [
        'global' => ['max' => 10, 'per_seconds' => 60],
        'per_fingerprint' => ['max' => 1, 'per_seconds' => 300],
    ]]);

    // Same fingerprint repeated: only the first should count against global.
    foreach (range(1, 5) as $i) {
        $limiter->allow('fp-1');
    }

    // Fresh fingerprints should still have the full remaining global budget —
    // the fp-1 repeats above must not have eaten into it.
    $allowed = 0;
    foreach (range(1, 9) as $i) {
        if ($limiter->allow('other-'.$i)) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(9);
});

it('keeps independent counters per fingerprint bucket', function () {
    $limiter = new DiscordRateLimiter(['rate_limit' => [
        'global' => ['max' => 100, 'per_seconds' => 60],
        'per_fingerprint' => ['max' => 1, 'per_seconds' => 300],
    ]]);

    expect($limiter->allow('fp-a'))->toBeTrue()
        ->and($limiter->allow('fp-b'))->toBeTrue()
        ->and($limiter->allow('fp-a'))->toBeFalse()
        ->and($limiter->allow('fp-b'))->toBeFalse();
});
