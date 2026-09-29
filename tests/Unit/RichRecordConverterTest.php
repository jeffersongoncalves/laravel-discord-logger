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

it('drops vendor frames from the stacktrace in smart mode', function () {
    $config = config('discord-logger');
    $config['stacktrace'] = 'smart';

    $converter = new RichRecordConverter($config);

    $payload = $converter->convert(record('boom', ['exception' => new RuntimeException('x')]));

    $trace = collect($payload['embeds'][0]['fields'])->firstWhere('name', 'Stacktrace')['value'] ?? '';

    // The real trace comes from Pest itself, so it's full of vendor frames in
    // whatever separator this OS uses — both must be filtered.
    expect($trace)->not->toBe('')
        ->not->toContain('/vendor/')
        ->not->toContain('\\vendor\\');
});

it('recognises vendor frames on Linux and Windows paths', function (string $line, bool $vendor) {
    $isVendorFrame = new ReflectionMethod(RichRecordConverter::class, 'isVendorFrame');

    expect($isVendorFrame->invoke(null, $line))->toBe($vendor);
})->with([
    'linux vendor' => ['#3 /var/www/app/vendor/laravel/framework/src/Foo.php(12): bar()', true],
    'windows vendor' => ['#3 C:\\www\\app\\vendor\\laravel\\framework\\src\\Foo.php(12): bar()', true],
    'linux app' => ['#1 /var/www/app/app/Http/Controllers/OrderController.php(40): charge()', false],
    'windows app' => ['#1 C:\\www\\app\\app\\Http\\Controllers\\OrderController.php(40): charge()', false],
    'vendor as a name, not a directory' => ['#2 /var/www/app/app/Models/Vendor.php(9): save()', false],
]);
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
