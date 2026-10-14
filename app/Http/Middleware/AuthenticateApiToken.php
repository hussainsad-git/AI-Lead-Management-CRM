<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer tokens for external clients on the same /api/v1 surface. Runs before the session/CSRF stack:
 * a valid token authenticates the request for this request only (no session login, no cookie), and marks
 * it so CSRF validation is skipped — a bearer header cannot be forged cross-site by a browser.
 * The token carries exactly its owner's permissions and organization; there is no way to widen them.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer && $request->is('api/*')) {
            $token = ApiToken::findValid($bearer);
            if (! $token || ! $token->user || ! $token->user->is_active) {
                return response()->json(['error' => ['code' => 'invalid_token', 'message' => 'The API token is invalid, expired or revoked.']], 401);
            }
            Auth::guard('web')->setUser($token->user);
            $request->attributes->set('api_token', $token);
            if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
                $token->forceFill(['last_used_at' => now(), 'last_used_ip' => mb_substr((string) $request->ip(), 0, 45)])->saveQuietly();
            }
        }

        return $next($request);
    }
}
