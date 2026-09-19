<?php

use JeffersonGoncalves\DiscordLogger\Converters\SimpleRecordConverter;
use Monolog\Level;
use Monolog\LogRecord;

function simpleRecord(string $message): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'discord',
        level: Level::Error,
        message: $message,
    );
}

it('builds plain content with level and emoji', function () {
    $converter = new SimpleRecordConverter([
        'emojis' => ['ERROR' => '🔥'],
    ]);

    $payload = $converter->convert(simpleRecord('Something broke'));

    expect($payload['content'])->toBe('🔥 **ERROR** Something broke')
        ->and($payload)->not->toHaveKey('embeds');
});

it('includes username and avatar_url when configured', function () {
    $converter = new SimpleRecordConverter([
        'from' => ['name' => 'MyApp', 'avatar_url' => 'https://example.com/a.png'],
    ]);

    $payload = $converter->convert(simpleRecord('hi'));

    expect($payload['username'])->toBe('MyApp')
        ->and($payload['avatar_url'])->toBe('https://example.com/a.png');
});

it('omits username and avatar_url when not configured', function () {
    $converter = new SimpleRecordConverter([]);

    $payload = $converter->convert(simpleRecord('hi'));

    expect($payload)->not->toHaveKey('username')
        ->and($payload)->not->toHaveKey('avatar_url');
});

it('truncates content to the 2000-char Discord limit', function () {
    $converter = new SimpleRecordConverter([]);

    $payload = $converter->convert(simpleRecord(str_repeat('a', 3000)));

    expect(mb_strlen($payload['content']))->toBe(2000);
});

it('redacts secret values by pattern before sending', function () {
    $converter = new SimpleRecordConverter([
        'redact_value_patterns' => ['/Bearer\s+\S+/i'],
    ]);

    $payload = $converter->convert(simpleRecord('failed with Bearer abc.def.ghi'));

    expect($payload['content'])->toContain('[REDACTED]')
        ->and($payload['content'])->not->toContain('abc.def.ghi');
});
