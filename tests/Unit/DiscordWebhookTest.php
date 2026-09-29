<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\DiscordLogger\Transport\DiscordWebhook;

beforeEach(fn () => Http::fake());

it('posts plain JSON when there is nothing to attach', function () {
    (new DiscordWebhook)->send('https://discord.test/hook', ['content' => 'hi']);

    Http::assertSent(fn (Request $request) => $request->isJson() && $request['content'] === 'hi');
});

it('sends files as multipart with the JSON body in payload_json', function () {
    (new DiscordWebhook)->send('https://discord.test/hook', [
        'embeds' => [['title' => 'Não']],
        'files' => ['stacktrace.txt' => 'full trace'],
    ]);

    Http::assertSent(function (Request $request) {
        $json = collect($request->data())->firstWhere('name', 'payload_json')['contents'] ?? '';

        return $request->isMultipart()
            && $request->hasFile('files[0]', 'full trace', 'stacktrace.txt')
            && $json === '{"embeds":[{"title":"Não"}]}';
    });
});
