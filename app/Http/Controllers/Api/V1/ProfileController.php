<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends ApiController
{
    public function show()
    {
        $u = $this->user()->load('roles:id,name', 'team:id,name');

        return $this->ok(['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'timezone' => $u->getRawOriginal('timezone'),
            'locale' => $u->locale, 'roles' => $u->roles->pluck('name'), 'team' => $u->team?->name, 'locales' => config('locales'),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'notifications' => [
                'email_enabled' => \App\Services\NotificationService::emailEnabled(),
                'types' => collect(config('leadflow.notification_types'))->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0],
                    'email' => (bool) ($u->notification_prefs['email'][$k] ?? $t[1])])->values(),
            ]]);
    }

    public function twoFactorStatus()
    {
        $u = $this->user();

        return $this->ok(['enabled' => (bool) $u->totp_confirmed_at, 'enabled_at' => $u->totp_confirmed_at?->toIso8601ZuluString(),
            'recovery_codes_left' => count((array) $u->recovery_codes), 'required' => (bool) app(\App\Services\OrganizationSettings::class)->get('require_2fa')]);
    }

    public function twoFactorBegin(Request $request, \App\Services\TwoFactorService $tf)
    {
        $this->browserOnly($request);
        $d = $tf->begin($this->user());

        return $this->ok($d + ['qr_svg' => \App\Support\Qr::svg($d['uri'])]);
    }

    public function twoFactorConfirm(Request $request, \App\Services\TwoFactorService $tf)
    {
        $this->browserOnly($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        return $this->ok(['enabled' => true, 'recovery_codes' => $tf->confirm($this->user(), $data['code'])]);
    }

    public function twoFactorDisable(Request $request, \App\Services\TwoFactorService $tf)
    {
        $this->browserOnly($request);
        $data = $request->validate(['current_password' => ['required', 'string']]);
        if (! Hash::check($data['current_password'], $this->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        abort_if(app(\App\Services\OrganizationSettings::class)->get('require_2fa'), 422, 'Your organization requires two-factor authentication; an administrator can reset it.');
        $tf->disable($this->user());

        return $this->ok(['enabled' => false]);
    }

    public function twoFactorRecoveryCodes(Request $request, \App\Services\TwoFactorService $tf)
    {
        $this->browserOnly($request);
        $data = $request->validate(['current_password' => ['required', 'string']]);
        if (! Hash::check($data['current_password'], $this->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        abort_unless($this->user()->totp_confirmed_at, 409);

        return $this->ok(['recovery_codes' => $tf->regenerateRecoveryCodes($this->user())]);
    }

    private function browserOnly(Request $request): void
    {
        abort_if($request->attributes->get('api_token') !== null, 403, 'Manage two-factor authentication from the web app.');
    }

    public function notifications(Request $request)
    {
        $types = array_keys(config('leadflow.notification_types'));
        $data = $request->validate(['email' => ['required', 'array'], 'email.*' => ['boolean']]);
        $email = collect($data['email'])->only($types)->map(fn ($v) => (bool) $v)->all();
        $u = $this->user();
        $u->forceFill(['notification_prefs' => array_merge((array) $u->notification_prefs, ['email' => $email])])->save();

        return $this->ok(['saved' => true]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'timezone:all'], 'locale' => ['nullable', 'in:'.implode(',', array_keys(config('locales')))],
        ]);
        $u = $this->user();
        $u->forceFill(['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'timezone' => $data['timezone'] ?? null, 'locale' => $data['locale'] ?? null])->save();
        session(['locale' => $u->locale]);

        return $this->ok(['saved' => true]);
    }

    public function password(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
        $u = $this->user();
        if (! Hash::check($data['current_password'], $u->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        $u->forceFill(['password' => $data['password']])->save();
        // Sign out other devices; keep this session (rotated).
        DB::table('sessions')->where('user_id', $u->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();
        $audit->log('user.password_changed', $u);

        return $this->ok(['saved' => true]);
    }
}
