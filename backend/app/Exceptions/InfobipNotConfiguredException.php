<?php

namespace App\Exceptions;

use RuntimeException;

class InfobipNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Infobip não configurado.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

