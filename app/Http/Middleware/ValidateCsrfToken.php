<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

class ValidateCsrfToken extends Middleware
{
    /** Bearer-token requests (set by AuthenticateApiToken) are not cookie-authenticated, so CSRF does not apply. */
    protected function inExceptArray($request): bool
    {
        return $request->attributes->get('api_token') !== null || parent::inExceptArray($request);
    }
}
