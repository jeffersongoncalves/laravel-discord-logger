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
