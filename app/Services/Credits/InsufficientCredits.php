<?php

namespace App\Services\Credits;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $requiredNanos, public readonly int $availableNanos)
    {
        parent::__construct('Not enough credits for this generation.');
    }
}
