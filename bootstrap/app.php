<?php

use App\Exceptions\DomainRuleException;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$apiError = fn (string $code, string $message, int $status, array $details = []) => response()->json(
    ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null])], $status
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Session-authenticated JSON API used by the application UI (CSRF-protected).
            Route::middleware(['web', 'auth', 'org', '2fa', 'throttle:api'])
                ->prefix('api/v1')->name('api.')
                ->group(base_path('routes/api.php'));
            // Public, signature-verified provider webhooks: no session, no CSRF.
            Route::middleware(['throttle:webhooks'])->prefix('webhooks')->group(function () {
                Route::get('whatsapp/{key}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->where('key', '[A-Za-z0-9]{40}');
                Route::post('whatsapp/{key}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'receive'])->where('key', '[A-Za-z0-9]{40}');
                Route::get('meta/{key}', [\App\Http\Controllers\MetaWebhookController::class, 'verify'])->where('key', '[A-Za-z0-9]{40}');
                Route::post('meta/{key}', [\App\Http\Controllers\MetaWebhookController::class, 'receive'])->where('key', '[A-Za-z0-9]{40}');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['org' => ResolveOrganization::class, 'perm' => RequirePermission::class, '2fa' => \App\Http\Middleware\EnforceTwoFactor::class]);
        // Bearer API tokens: resolved before the session stack; CSRF is skipped only for token-authenticated requests.
        $middleware->web(prepend: [\App\Http\Middleware\AuthenticateApiToken::class]);
        $middleware->replace(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\ValidateCsrfToken::class);
        $middleware->append(SecurityHeaders::class);
        // Tenant must be bound before route-model binding so {lead}, {contact}, ... resolve inside the user's organization.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, ResolveOrganization::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        $exceptions->dontReport([DomainRuleException::class]);
        $exceptions->report(fn (Throwable $e) => \App\Support\Ops\ErrorMonitor::report($e));

        $exceptions->render(function (Throwable $e, Request $r) use ($apiError) {
            if (! $r->is('api/*')) {
                if ($e instanceof DomainRuleException) {
                    return back()->withErrors(['error' => $e->getMessage()]);
                }

                return null;
            }

            return match (true) {
                $e instanceof ValidationException => $apiError('validation_failed', 'Some fields need attention.', 422, $e->errors()),
                $e instanceof DomainRuleException => $apiError($e->errorCode, $e->getMessage(), $e->status, $e->details),
                $e instanceof AuthenticationException => $apiError('unauthenticated', 'Please sign in again.', 401),
                $e instanceof AuthorizationException => $apiError('forbidden', 'You do not have permission to perform this action.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $apiError('not_found', 'The requested record was not found.', 404),
                $e instanceof TokenMismatchException => $apiError('csrf_mismatch', 'Your session expired. Reload the page.', 419),
                $e instanceof ThrottleRequestsException => $apiError('rate_limited', 'Too many requests. Try again shortly.', 429),
                $e instanceof HttpExceptionInterface => $apiError(match ($e->getStatusCode()) {
                    401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 419 => 'csrf_mismatch', 429 => 'rate_limited', default => 'http_error',
                }, $e->getStatusCode() >= 500 ? 'Server error.' : ($e->getMessage() ?: 'Request failed.'), $e->getStatusCode()),
                default => config('app.debug') ? null : $apiError('server_error', 'Something went wrong. The error has been logged.', 500),
            };
        });
    })->create();
