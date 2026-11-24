<?php

namespace App\Services;

class QuotaExceeded extends \RuntimeException
{
    public function __construct(string $message, public ?float $retryAfter = null)
    {
        parent::__construct($message);
    }
}
