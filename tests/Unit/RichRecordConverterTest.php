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

it('truncates a context field to Discord 1024-char limit while keeping it', function () {
    // Between 1024 and 6000: small enough to survive the embed clamp, large
    // enough to blow the per-field limit if not truncated -> HTTP 400.
    $converter = new RichRecordConverter(config('discord-logger'));

    $payload = $converter->convert(record('mid', ['blob' => str_repeat('A', 2000)]));

    $fields = $payload['embeds'][0]['fields'];

    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        expect(mb_strlen($field['name']))->toBeLessThanOrEqual(256)
            ->and(mb_strlen($field['value']))->toBeLessThanOrEqual(1024);
    }
});

it('keeps accented characters readable in the context field', function () {
    $converter = new RichRecordConverter(config('discord-logger'));

    $payload = $converter->convert(record('pedido', ['user' => 'Não logado', 'bad' => "a\xB1b"]));

    $context = collect($payload['embeds'][0]['fields'])->firstWhere('name', 'Context')['value'];

    expect($context)
        ->toContain('Não logado')
        ->not->toContain('\\u00')
        ->toContain('"bad": "a�b"');
});

it('redacts secret value patterns in the message', function () {
    $config = config('discord-logger');
    $config['redact_value_patterns'] = ['/Bearer\s+[A-Za-z0-9._-]+/i'];

    $converter = new RichRecordConverter($config);

    $payload = $converter->convert(record('auth failed Bearer abc.def.ghi'));

    expect($payload['embeds'][0]['description'])
        ->toContain('[REDACTED]')
        ->not->toContain('abc.def.ghi');
});

it('includes a stacktrace when the mode is full', function () {
    $config = config('discord-logger');
    $config['stacktrace'] = 'full';

    $converter = new RichRecordConverter($config);

    $payload = $converter->convert(record('boom', ['exception' => new RuntimeException('x')]));

    $names = array_column($payload['embeds'][0]['fields'], 'name');

    expect($names)->toContain('Stacktrace');
});

it('omits the stacktrace when the mode is none', function () {
    $config = config('discord-logger');
    $config['stacktrace'] = 'none';

    $converter = new RichRecordConverter($config);

    $payload = $converter->convert(record('boom', ['exception' => new RuntimeException('x')]));

    $names = array_column($payload['embeds'][0]['fields'], 'name');

    expect($names)->not->toContain('Stacktrace')
        ->and($names)->toContain('Exception');
});

it('attaches the full redacted exception when it does not fit in the embed', function () {
    $config = config('discord-logger');
    $config['redact_value_patterns'] = ['/SHHSECRET/'];

    $converter = new RichRecordConverter($config);

    $throw = function (string $token) {
        throw new RuntimeException('boom', previous: new LogicException('root cause'));
    };

    try {
        $throw('SHHSECRET');
    } catch (RuntimeException $e) {
        $payload = $converter->convert(record('boom', ['exception' => $e]));
    }

    $file = $payload['files']['stacktrace.txt'] ?? '';

    expect(mb_strlen($file))->toBeGreaterThan(1000)
        ->and($file)
        ->toContain('RuntimeException: boom')
        ->toContain('LogicException: root cause')
        ->toContain('phpunit') // vendor frame: smart mode trims the embed, never the file
        ->not->toContain('SHHSECRET');
});

it('attaches nothing when disabled, in none mode or without an exception', function (array $overrides, array $context) {
    $converter = new RichRecordConverter(array_replace(config('discord-logger'), $overrides));

    $payload = $converter->convert(record('boom', $context));

    expect($payload)->not->toHaveKey('files');
})->with([
    'attach disabled' => [['attach_stacktrace' => false], ['exception' => new RuntimeException('x')]],
    'stacktrace none' => [['stacktrace' => 'none'], ['exception' => new RuntimeException('x')]],
    'no exception' => [[], ['order' => 7]],
]);

it('drops vendor frames from the stacktrace in smart mode', function () {
    $config = config('discord-logger');
    $config['stacktrace'] = 'smart';

    $converter = new RichRecordConverter($config);

    $payload = $converter->convert(record('boom', ['exception' => new RuntimeException('x')]));

    $trace = collect($payload['embeds'][0]['fields'])->firstWhere('name', 'Stacktrace')['value'] ?? '';

    expect($trace)->not->toContain('/vendor/');
});

it('redacts secret value patterns in the stacktrace', function () {
    $config = config('discord-logger');
    $config['stacktrace'] = 'full';
    // Short token: getTraceAsString() truncates string args to 15 chars.
    $config['redact_value_patterns'] = ['/SHHSECRET/'];

    $converter = new RichRecordConverter($config);

    // Force the secret to appear in the trace via the call args.
    $throw = function (string $token) {
        throw new RuntimeException('boom');
    };

    try {
        $throw('SHHSECRET');
    } catch (RuntimeException $e) {
        $payload = $converter->convert(record('boom', ['exception' => $e]));
    }

    $trace = collect($payload['embeds'][0]['fields'])->firstWhere('name', 'Stacktrace')['value'] ?? '';

    expect($trace)->not->toContain('SHHSECRET');
});
