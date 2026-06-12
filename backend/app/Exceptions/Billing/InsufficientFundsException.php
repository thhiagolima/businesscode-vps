<?php

namespace App\Exceptions\Billing;

class InsufficientFundsException extends \RuntimeException
{
    public function __construct(
        public readonly int $required_cents,
        public readonly int $available_cents
    ) {
        parent::__construct("Insufficient funds: required {$required_cents}c, available {$available_cents}c");
    }
}
