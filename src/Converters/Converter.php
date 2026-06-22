<?php

namespace JeffersonGoncalves\DiscordLogger\Converters;

use Monolog\LogRecord;

interface Converter
{
    /**
     * Build a Discord webhook payload from a log record.
     *
     * @return array<string, mixed>
     */
    public function convert(LogRecord $record): array;
}
