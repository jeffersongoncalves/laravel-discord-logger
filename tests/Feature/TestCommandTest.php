<?php

use Illuminate\Support\Facades\Http;

it('reports success when the webhook accepts the test message', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $this->artisan('discord-logger:test')->assertExitCode(0);

    Http::assertSentCount(1);
});

it('fails when the channel has no webhook url', function () {
    config()->set('logging.channels.discord.url', '');

    $this->artisan('discord-logger:test')->assertExitCode(1);
});

it('fails when discord rejects the test message', function () {
    Http::fake(['*' => Http::response('bad', 400)]);

    $this->artisan('discord-logger:test')->assertExitCode(1);
});
