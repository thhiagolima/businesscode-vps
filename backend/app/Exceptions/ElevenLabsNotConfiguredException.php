<?php

namespace App\Exceptions;

use RuntimeException;

class ElevenLabsNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'ElevenLabs não configurado.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

