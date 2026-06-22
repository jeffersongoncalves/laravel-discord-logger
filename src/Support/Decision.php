<?php

namespace JeffersonGoncalves\DiscordLogger\Support;

/**
 * Result of the deduplication check for a single record.
 */
class Decision
{
    public function __construct(
        public readonly bool $send,
        public readonly bool $first,
        public readonly int $occurrences,
    ) {}
}
