<?php

namespace App\Services\WhatsApp;

use RuntimeException;

class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $metaCode = null, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public function isAuthError(): bool
    {
        return $this->metaCode === 190 || in_array($this->httpStatus, [401], true);
    }

    /** 131047: more than 24h since the customer last replied — only templates may be sent. */
    public function isWindowClosed(): bool
    {
        return $this->metaCode === 131047;
    }
}
