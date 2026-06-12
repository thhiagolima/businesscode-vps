<?php

namespace App\Exceptions;

use RuntimeException;

class AiNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'IA (Grok) não configurada.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

