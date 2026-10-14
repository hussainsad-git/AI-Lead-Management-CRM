<?php

namespace App\Http\Middleware;

use App\Services\OrganizationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** When the organization requires 2FA, users without it can only reach their profile (to enrol) and sign out. */
class EnforceTwoFactor
{
    private const ALLOWED = ['profile', 'logout', 'locale', 'branding/*', 'api/v1/profile', 'api/v1/profile/*', 'api/v1/notifications*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->totp_confirmed_at && ! $request->attributes->get('api_token') && app(OrganizationSettings::class)->get('require_2fa') && ! $request->is(...self::ALLOWED)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => ['code' => 'two_factor_required', 'message' => 'Your organization requires two-factor authentication. Set it up on your profile.']], 403);
            }

            return redirect('/profile?setup=2fa');
        }

        return $next($request);
    }
}
