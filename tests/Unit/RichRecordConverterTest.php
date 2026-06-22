<?php

use JeffersonGoncalves\DiscordLogger\Converters\RichRecordConverter;

function embedSize(array $embed): int
{
    $size = mb_strlen((string) ($embed['title'] ?? '')) + mb_strlen((string) ($embed['description'] ?? ''));

    foreach ($embed['fields'] ?? [] as $field) {
        $size += mb_strlen((string) $field['name']) + mb_strlen((string) $field['value']);
    }

    return $size;
}

it('keeps the embed within the 6000 character limit', function () {
    $converter = new RichRecordConverter(config('discord-logger'));

    $payload = $converter->convert(record('huge', ['blob' => str_repeat('A', 20000)]));

    expect(embedSize($payload['embeds'][0]))->toBeLessThanOrEqual(6000);
});

it('keeps small context fields', function () {
    $converter = new RichRecordConverter(config('discord-logger'));

    $payload = $converter->convert(record('small', ['order' => 7]));

    expect($payload['embeds'][0]['fields'])->not->toBeEmpty();
});
