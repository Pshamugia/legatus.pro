<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        if (! config('legatus.demo_login_enabled') && in_array(Str::lower($data['email']), ['demo@legatus.ai', 'operator@legatus.ai'], true)) {
            return back()->withErrors(['email' => 'Demo administrator login is disabled in this environment.'])->onlyInput('email');
        }
        if (! Auth::attempt($data, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email or password is incorrect.'])->onlyInput('email');
        }

        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function register()
    {
        abort_unless(config('legatus.registration_enabled'), 404);

        return view('auth.register');
    }

    public function store(Request $r)
    {
        abort_unless(config('legatus.registration_enabled'), 404);
        $data = $r->validate(['name' => 'required|max:100', 'email' => 'required|email|unique:users', 'password' => 'required|min:8|confirmed', 'business_name' => 'required|max:120']);
        DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
            $org = Organization::create(['name' => $data['business_name'], 'slug' => Str::slug($data['business_name']).'-'.Str::lower(Str::random(5))]);
            $org->users()->attach($user, ['role' => 'owner']);
            $org->agents()->create(['name' => 'AI Assistant', 'slug' => Str::slug($data['business_name']).'-legatus-'.Str::lower(Str::random(4)), 'business_name' => $data['business_name'], 'channels' => ['web'], 'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10, 'delivery_policy' => null]]);
            Auth::login($user);
        });
        $r->session()->regenerate();

        return redirect()->route('onboarding');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
