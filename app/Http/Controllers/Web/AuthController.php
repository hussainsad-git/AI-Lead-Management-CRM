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

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        app(\App\Support\Tenancy\CurrentOrganization::class)->set($user->organization);
        app(\App\Services\AuditLogger::class)->log('auth.login', $user);

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
