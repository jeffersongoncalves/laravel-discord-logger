<?php

use JeffersonGoncalves\DiscordLogger\Support\Redactor;

it('masks matching keys recursively', function () {
    $redactor = new Redactor(['password', 'token']);

    $out = $redactor->scrub([
        'user' => 'alice',
        'password' => 'hunter2',
        'nested' => [
            'api_token' => 'abc',
            'keep' => 1,
        ],
    ]);

    expect($out['password'])->toBe('[REDACTED]')
        ->and($out['nested']['api_token'])->toBe('[REDACTED]')
        ->and($out['nested']['keep'])->toBe(1)
        ->and($out['user'])->toBe('alice');
});

it('returns data untouched when no keys configured', function () {
    $redactor = new Redactor([]);

    expect($redactor->scrub(['password' => 'x']))->toBe(['password' => 'x']);
});

it('builds from config with a custom placeholder', function () {
    $redactor = Redactor::fromConfig([
        'redact' => ['secret'],
        'redact_placeholder' => '***',
    ]);

    expect($redactor->scrub(['secret' => 'v']))->toBe(['secret' => '***']);
});

it('recurses into objects carried in the context', function () {
    $redactor = new Redactor(['password']);

    $dto = (object) ['user' => 'bob', 'password' => 'hunter2'];

    $out = $redactor->scrub(['payload' => $dto]);

    expect($out['payload'])->toBe(['user' => 'bob', 'password' => '[REDACTED]']);
});

it('redacts secret values by pattern even under an innocuous key', function () {
    $redactor = new Redactor(
        keys: [],
        valuePatterns: ['/Bearer\s+[A-Za-z0-9._-]+/i'],
    );

    $out = $redactor->scrub(['url' => 'GET /x with Authorization: Bearer abc.def.ghi']);

    expect($out['url'])->toContain('[REDACTED]')
        ->and($out['url'])->not->toContain('abc.def.ghi');
});

it('scrubs a bare string value via patterns', function () {
    $redactor = new Redactor(
        keys: [],
        valuePatterns: ['/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/'],
    );

    $jwt = 'eyJhbGc.eyJzdWI.signature';

    expect($redactor->scrubString("token is {$jwt}"))
        ->toBe('token is [REDACTED]');
});

it('leaves strings untouched when no value patterns are configured', function () {
    $redactor = new Redactor(['password']);

    expect($redactor->scrubString('Bearer abc.def.ghi'))->toBe('Bearer abc.def.ghi');
});

it('redacts Authorization: Basic credentials via the default pattern', function () {
    $redactor = Redactor::fromConfig(config('discord-logger'));

    expect($redactor->scrubString('Authorization: Basic dXNlcjpodW50ZXIy'))
        ->toBe('Authorization: [REDACTED]')
        ->not->toContain('dXNlcjpodW50ZXIy');
});

it('redacts a sensitive key/value pair inside serialized JSON text and keeps the key', function () {
    $redactor = Redactor::fromConfig(config('discord-logger'));

    expect($redactor->scrubString('request failed {"api_token":"abc123"}'))
        ->toBe('request failed {"api_token":"[REDACTED]"}');
});

it('redacts a sensitive query string parameter and keeps the key', function () {
    $redactor = Redactor::fromConfig(config('discord-logger'));

    expect($redactor->scrubString('GET https://example.com?token=abc123&id=1'))
        ->toBe('GET https://example.com?token=[REDACTED]&id=1');
});

it('redacts a short Basic credential via the default pattern', function () {
    $redactor = Redactor::fromConfig(config('discord-logger'));

    expect($redactor->scrubString('Authorization: Basic YTpi'))
        ->toBe('Authorization: [REDACTED]')
        ->not->toContain('YTpi');
});

it('redacts a serialized JSON value containing an escaped quote without leaving a remainder', function () {
    $redactor = Redactor::fromConfig(config('discord-logger'));

    $out = $redactor->scrubString('request failed {"api_token":"abc\"def"}');

    expect($out)->toBe('request failed {"api_token":"[REDACTED]"}')
        ->not->toContain('abc')
        ->not->toContain('def');
});

it('ignores a numbered capture group that is not the named safe group', function () {
    $redactor = new Redactor(
        keys: [],
        valuePatterns: ['/(secret_\d+)=\w+/i'],
    );

    $out = $redactor->scrubString('leaked secret_1=hunter2');

    expect($out)->toBe('leaked [REDACTED]')
        ->not->toContain('secret_1')
        ->not->toContain('hunter2');
});
