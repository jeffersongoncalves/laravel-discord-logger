<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reports success when the webhook accepts the test message', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $this->artisan('discord-logger:test')->assertExitCode(0);

    Http::assertSentCount(1);
});

it('sends a sample exception with the stacktrace attached', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $this->artisan('discord-logger:test', ['--exception' => true])
        ->expectsOutputToContain('stacktrace.txt attached')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        $json = collect($request->data())->firstWhere('name', 'payload_json')['contents'] ?? '';

        return $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'files[0]' && $part['filename'] === 'stacktrace.txt')
            && str_contains($json, 'Discord Logger test exception');
    });
});

it('fails when the channel has no webhook url', function () {
    config()->set('logging.channels.discord.url', '');

    $this->artisan('discord-logger:test')->assertExitCode(1);
});

it('fails when discord rejects the test message', function () {
    Http::fake(['*' => Http::response('bad', 400)]);

    $this->artisan('discord-logger:test')->assertExitCode(1);
});
