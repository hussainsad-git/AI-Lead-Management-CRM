<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('perm:leads.view_all|leads.view_own') — passes if the user has ANY listed permission. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyPermission(...explode('|', $permissions))) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
