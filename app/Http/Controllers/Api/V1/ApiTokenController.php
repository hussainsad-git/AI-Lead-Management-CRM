<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Personal API tokens. Managed only from a browser session — a token cannot mint or list tokens. */
class ApiTokenController extends ApiController
{
    private function guard(Request $request): void
    {
        abort_unless($this->user()->hasPermission('api.tokens'), 403);
        abort_if($request->attributes->get('api_token') !== null, 403, 'Manage API tokens from the web app.');
    }

    public function index(Request $request)
    {
        $this->guard($request);

        return $this->ok(ApiToken::where('user_id', $this->user()->id)->whereNull('revoked_at')->latest('id')->get()
            ->map(fn ($t) => $this->present($t)));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $this->guard($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730']]);
        abort_if(ApiToken::where('user_id', $this->user()->id)->whereNull('revoked_at')->count() >= 10, 422, 'At most 10 active tokens per user.');
        [$t, $plain] = ApiToken::issue($this->user(), $data['name'], ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null);
        $audit->log('api_token.created', $t, [], ['name' => $t->name, 'prefix' => $t->token_prefix]);

        return $this->ok(['token' => $plain, 'record' => $this->present($t), 'note' => 'Copy it now — it is not shown again.'], [], 201);
    }

    public function destroy(Request $request, ApiToken $token, AuditLogger $audit)
    {
        $this->guard($request);
        abort_unless($token->user_id === $this->user()->id, 404);
        $token->forceFill(['revoked_at' => now()])->save();
        $audit->log('api_token.revoked', $token, [], ['name' => $token->name, 'prefix' => $token->token_prefix]);

        return $this->ok(['revoked' => true]);
    }

    private function present(ApiToken $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'prefix' => $t->token_prefix.'…', 'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'last_used_ip' => $t->last_used_ip, 'expires_at' => $t->expires_at?->toIso8601ZuluString(), 'created_at' => $t->created_at->toIso8601ZuluString()];
    }
}
