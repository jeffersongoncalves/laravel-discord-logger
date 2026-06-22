<?php

namespace JeffersonGoncalves\DiscordLogger\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DiscordWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, array $payload): Response
    {
        return Http::asJson()
            ->timeout(10)
            ->post($url, $payload);
    }
}
