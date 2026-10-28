<?php

namespace App\Update;

use RuntimeException;

/**
 * Why an update check or update was refused. `reason` is a short key (lang `update.error.<reason>`); the message is
 * that text, written for people and built only from our own words and numbers, never from foreign answers.
 */
final class UpdateException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     * @param  string|null  $message  an already rendered text of ours (the updater container's answer, redacted)
     */
    public function __construct(public readonly string $reason, array $params = [], ?string $message = null)
    {
        parent::__construct($message ?? __("update.error.{$reason}", $params));
    }
}
