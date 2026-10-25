<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $r, TenantContext $tenant)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        if (! config('legatus.demo_login_enabled') && in_array(Str::lower($data['email']), ['demo@legatus.ai', 'operator@legatus.ai'], true)) {
            return back()->withErrors(['email' => 'Demo administrator login is disabled in this environment.'])->onlyInput('email');
        }
        if (! Auth::attempt($data, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email or password is incorrect.'])->onlyInput('email');
        }

        $r->session()->regenerate();

        $home = in_array($tenant->role($r->user()), ['owner', 'admin'], true)
            ? route('onboarding')
            : route('inbox.index');

        return redirect()->intended($home);
    }

    public function register(Request $request)
    {
        abort_unless(config('legatus.registration_enabled'), 404);
        $request->session()->put('registration_form_issued_at', now()->timestamp);

        return view('auth.register', [
            'billingPeriod' => $this->billingPeriod($request->query('period')),
            'billingPackage' => $this->billingPackage($request->query('package')),
            'turnstileSiteKey' => config('legatus.registration_turnstile_site_key'),
        ]);
    }

    public function store(Request $r)
    {
        abort_unless(config('legatus.registration_enabled'), 404);
        $data = $r->validate(['name' => 'required|max:100', 'email' => 'required|email|unique:users', 'password' => 'required|min:8|confirmed', 'business_name' => 'required|max:120', 'billing_period' => 'nullable|in:monthly,six_months,yearly', 'billing_package' => 'nullable|in:chat,chat_social']);
        $this->validateRegistrationChallenge($r);
        DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
            $org = Organization::create(['name' => $data['business_name'], 'slug' => Str::slug($data['business_name']).'-'.Str::lower(Str::random(5))]);
            $org->users()->attach($user, ['role' => 'owner']);
            $org->agents()->create(['name' => 'AI Assistant', 'slug' => Str::slug($data['business_name']).'-legatus-'.Str::lower(Str::random(4)), 'business_name' => $data['business_name'], 'channels' => ['web'], 'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10, 'delivery_policy' => null]]);
            Auth::login($user);
        });
        $r->session()->regenerate();
        $r->session()->forget('registration_form_issued_at');

        $billingConfigured = config('paddle.billing_enforced')
            && filled(config('paddle.client_token'))
            && collect(config('paddle.prices'))->filter()->count() === 3;

        if (! $billingConfigured) {
            return redirect()->route('onboarding');
        }

        $billingSelection = [];
        if (isset($data['billing_period']) || isset($data['billing_package'])) {
            $billingSelection = [
                'period' => $this->billingPeriod($data['billing_period'] ?? null),
                'package' => $this->billingPackage($data['billing_package'] ?? null),
            ];
        }

        return redirect()->route('billing.index', $billingSelection);
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('landing');
    }

    private function billingPeriod(mixed $value): string
    {
        return in_array($value, ['monthly', 'six_months', 'yearly'], true) ? $value : 'monthly';
    }

    private function validateRegistrationChallenge(Request $request): void
    {
        $issuedAt = $request->session()->get('registration_form_issued_at');
        if (filled($request->input('company_website'))
            || ! is_numeric($issuedAt)
            || now()->timestamp - (int) $issuedAt < 3
            || now()->timestamp - (int) $issuedAt > 7200) {
            throw ValidationException::withMessages(['email' => 'Please reload the form and try again.']);
        }

        $siteKey = (string) config('legatus.registration_turnstile_site_key');
        $secretKey = (string) config('legatus.registration_turnstile_secret_key');
        if ($siteKey === '' && $secretKey === '') {
            return;
        }

        $token = $request->input('cf-turnstile-response');
        if ($siteKey === '' || $secretKey === '' || ! is_string($token) || $token === '' || strlen($token) > 2048) {
            throw ValidationException::withMessages(['email' => 'Please complete the security check and try again.']);
        }

        try {
            $result = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['email' => 'The security check is unavailable. Please try again shortly.']);
        }

        $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $result->successful()
            || $result->json('success') !== true
            || ! is_string($expectedHost)
            || strcasecmp((string) $result->json('hostname'), $expectedHost) !== 0) {
            throw ValidationException::withMessages(['email' => 'Please complete the security check and try again.']);
        }
    }

    private function billingPackage(mixed $value): string
    {
        return $value === 'chat_social' ? 'chat_social' : 'chat';
    }
}
