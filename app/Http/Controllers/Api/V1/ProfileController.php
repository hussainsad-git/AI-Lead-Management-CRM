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
            'timezones' => \DateTimeZone::listIdentifiers()]);
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
