<?php

namespace App\Services\Reviews;

class AbsaException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable = true)
    {
        parent::__construct($errorCode);
    }
}
