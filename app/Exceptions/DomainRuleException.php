<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule violation that is safe to show to the user (rendered as 409/422 JSON). */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation', public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
