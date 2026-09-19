<?php

use Illuminate\Support\Facades\Cache;
use JeffersonGoncalves\DiscordLogger\Support\Deduplicator;

beforeEach(function () {
    Cache::flush();
});

it('sends the first occurrence and marks it as first', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    $decision = $dedup->decide('fp-1');

    expect($decision->send)->toBeTrue()
        ->and($decision->first)->toBeTrue()
        ->and($decision->occurrences)->toBe(1);
});

it('silences repeats within the window and counts them', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    $dedup->decide('fp-1');
    $second = $dedup->decide('fp-1');
    $third = $dedup->decide('fp-1');

    expect($second->send)->toBeFalse()
        ->and($second->occurrences)->toBe(2)
        ->and($third->send)->toBeFalse()
        ->and($third->occurrences)->toBe(3);
});

it('always sends when deduplication is disabled', function () {
    $dedup = new Deduplicator(['deduplication' => ['enabled' => false]]);

    $dedup->decide('fp-1');
    $decision = $dedup->decide('fp-1');

    expect($decision->send)->toBeTrue()
        ->and($decision->occurrences)->toBe(1);
});

it('reports occurrences seen so far for a fingerprint', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    $dedup->decide('fp-1');
    $dedup->decide('fp-1');
    $dedup->decide('fp-1');

    expect($dedup->occurrences('fp-1'))->toBe(3);
});

it('defaults occurrences to 1 for an unseen fingerprint', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    expect($dedup->occurrences('never-seen'))->toBe(1);
});

it('forgets a fingerprint so the next occurrence counts as first again', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    $dedup->decide('fp-1');
    $dedup->decide('fp-1');
    $dedup->forget('fp-1');

    $decision = $dedup->decide('fp-1');

    expect($decision->send)->toBeTrue()
        ->and($decision->first)->toBeTrue();
});

it('keeps independent counters per fingerprint', function () {
    $dedup = new Deduplicator(['deduplication' => ['window' => 300]]);

    $dedup->decide('fp-a');
    $dedup->decide('fp-b');
    $second = $dedup->decide('fp-a');

    expect($second->send)->toBeFalse()
        ->and($dedup->occurrences('fp-b'))->toBe(1);
});
