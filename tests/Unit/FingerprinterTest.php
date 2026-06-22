<?php

use JeffersonGoncalves\DiscordLogger\Support\Fingerprinter;
use Monolog\Level;
use Monolog\LogRecord;

function record(string $message, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'discord',
        level: Level::Error,
        message: $message,
        context: $context,
    );
}

it('groups normalized messages to the same fingerprint', function () {
    $fp = new Fingerprinter(['grouping' => ['strategy' => 'message', 'normalize' => true]]);

    expect($fp->fingerprint(record('Order 100 failed')))
        ->toBe($fp->fingerprint(record('Order 999 failed')));
});

it('keeps distinct messages on distinct fingerprints', function () {
    $fp = new Fingerprinter(['grouping' => ['strategy' => 'message', 'normalize' => false]]);

    expect($fp->fingerprint(record('alpha')))
        ->not->toBe($fp->fingerprint(record('beta')));
});

it('fingerprints exceptions by class, file and line', function () {
    $fp = new Fingerprinter(['grouping' => ['strategy' => 'exception']]);

    $exception = new RuntimeException('boom');
    $a = $fp->fingerprint(record('m1', ['exception' => $exception]));
    $b = $fp->fingerprint(record('m2', ['exception' => $exception]));

    expect($a)->toBe($b);
});

it('honours a custom callback', function () {
    $fp = new Fingerprinter(['grouping' => [
        'callback' => fn (LogRecord $r) => 'static-key',
    ]]);

    expect($fp->fingerprint(record('whatever')))
        ->toBe($fp->fingerprint(record('different')));
});
