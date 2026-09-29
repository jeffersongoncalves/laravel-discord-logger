<?php

namespace JeffersonGoncalves\DiscordLogger\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DiscordWebhook
{
    /**
     * A `files` key (filename => contents) switches to multipart: Discord then
     * expects the JSON body in a `payload_json` part next to `files[n]` parts.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, array $payload): Response
    {
        $files = $payload['files'] ?? [];
        unset($payload['files']);

        if (! is_array($files) || $files === []) {
            return Http::asJson()
                ->timeout(10)
                ->post($url, $payload);
        }

        $request = Http::timeout(10);

        foreach (array_keys($files) as $i => $name) {
            $request->attach("files[{$i}]", (string) $files[$name], (string) $name, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $request->post($url, [
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}
