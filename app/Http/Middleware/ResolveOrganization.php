<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant from the authenticated user. Deactivated users or organizations are
 * logged out immediately. Client-supplied organization identifiers are never consulted.
 */
class ResolveOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $org = $user?->organization;
        if (! $user || ! $user->is_active || ! $org || ! $org->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, 'Your account is not active.');
        }
        $this->current->set($org);
        $locale = $user->locale ?: $org->default_locale ?: 'en';
        if (array_key_exists($locale, config('locales'))) {
            app()->setLocale($locale);
        }
        if ($user->timezone === null) {
            $user->setAttribute('timezone', $org->timezone);
        }

        return $next($request);
    }
}
