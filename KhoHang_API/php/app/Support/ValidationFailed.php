<?php

namespace App\Support;

class ValidationFailed extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('Validation failed');
    }
}
