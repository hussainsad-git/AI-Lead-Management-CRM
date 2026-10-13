<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly bool $authFailure = false)
    {
        parent::__construct($message);
    }
}
