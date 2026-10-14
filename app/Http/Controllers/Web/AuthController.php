<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email', 'max:191'], 'password' => ['required', 'string', 'max:200']]);
        $user = User::where('email', mb_strtolower($credentials['email']))->first();

        // Constant-ish work whether or not the user exists; generic error either way.
        $valid = $user ? Hash::check($credentials['password'], $user->password) : Hash::check($credentials['password'], '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$ZmFrZWhhc2hmYWtlaGFzaGZha2VoYXNoZmFrZWg');
        if (! $valid || ! $user->is_active || ! $user->organization?->is_active) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }
        if ($user->totp_confirmed_at) {
            // Password accepted; hold the login until the second factor is verified (no user is signed in yet).
            $request->session()->regenerate();
            $request->session()->put('2fa', ['user_id' => $user->id, 'remember' => $request->boolean('remember'), 'expires' => time() + 300]);

            return redirect('/login/2fa');
        }

        return $this->complete($request, $user, $request->boolean('remember'));
    }

    public function showTwoFactor(Request $request)
    {
        $pending = $request->session()->get('2fa');
        if (! $pending || $pending['expires'] < time()) {
            $request->session()->forget('2fa');

            return redirect('/login')->withErrors(['email' => 'Please sign in again.']);
        }

        return view('auth.two-factor');
    }

    public function verifyTwoFactor(Request $request, \App\Services\TwoFactorService $twoFactor)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = $request->session()->get('2fa');
        $user = $pending && $pending['expires'] >= time() ? User::find($pending['user_id']) : null;
        if (! $user || ! $user->is_active || ! $user->totp_confirmed_at) {
            $request->session()->forget('2fa');

            return redirect('/login')->withErrors(['email' => 'Please sign in again.']);
        }
        app(\App\Support\Tenancy\CurrentOrganization::class)->set($user->organization); // audit rows need the tenant before login completes
        if (! $twoFactor->verifyLogin($user, $data['code'])) {
            app(\App\Services\AuditLogger::class)->log('auth.2fa_failed', $user);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $request->session()->forget('2fa');

        return $this->complete($request, $user, (bool) $pending['remember']);
    }

    public function cancelTwoFactor(Request $request)
    {
        $request->session()->forget('2fa');

        return redirect('/login');
    }

    private function complete(Request $request, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        app(\App\Support\Tenancy\CurrentOrganization::class)->set($user->organization);
        app(\App\Services\AuditLogger::class)->log('auth.login', $user, [], ['two_factor' => (bool) $user->totp_confirmed_at]);

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
