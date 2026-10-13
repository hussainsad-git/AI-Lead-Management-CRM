<?php

namespace App\Services\Meta;

use RuntimeException;

class MetaException extends RuntimeException
{
    public function __construct(string $message, public ?int $graphCode = null, public ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    /** 190 = invalid/expired token; 10/200-299 = missing permission. */
    public function isAuthError(): bool
    {
        return $this->graphCode === 190 || $this->graphCode === 10 || ($this->graphCode >= 200 && $this->graphCode < 300);
    }

    /** 4, 17, 32, 613, 80000-80014 = rate limiting. */
    public function isRateLimit(): bool
    {
        return in_array($this->graphCode, [4, 17, 32, 613], true) || ($this->graphCode >= 80000 && $this->graphCode <= 80014);
    }
}
