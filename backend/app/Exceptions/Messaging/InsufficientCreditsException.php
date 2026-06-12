<?php
namespace App\Exceptions\Messaging;

class InsufficientCreditsException extends \RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct("Insufficient credits: required {$required}, balance {$balance}");
    }
}
