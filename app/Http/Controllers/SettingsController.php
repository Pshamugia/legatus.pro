<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantContext;
use App\Support\WidgetTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $organization = $tenant->organization();
        $agent = $tenant->agent();
        $members = $organization->users()->get();
        $role = $tenant->role();

        return view('settings', compact('organization', 'agent', 'members', 'role'));
    }

    public function update(Request $r, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $r->merge([
            'business_name' => $this->trimUnicodeWhitespace($r->input('business_name')),
            'agent_name' => $this->trimUnicodeWhitespace($r->input('agent_name')),
        ]);
        $data = $r->validate([
            'business_name' => $this->identityRules(120),
            'agent_name' => $this->identityRules(80),
            'widget_theme_preset' => ['sometimes', 'string', Rule::in(WidgetTheme::allowedPresets())],
            'widget_theme_primary' => ['bail', 'exclude_unless:widget_theme_preset,custom', 'required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'widget_theme_accent' => [
                'bail',
                'exclude_unless:widget_theme_preset,custom',
                'required',
                'string',
                'regex:/\A#[0-9A-Fa-f]{6}\z/',
                function (string $attribute, mixed $value, \Closure $fail) use ($r): void {
                    $primary = WidgetTheme::normalizeHex($r->input('widget_theme_primary'));
                    $accent = WidgetTheme::normalizeHex($value);

                    if ($primary !== null && $accent !== null && ! WidgetTheme::hasSufficientPairContrast($primary, $accent)) {
                        $fail('Choose primary and accent colors with at least a 3:1 contrast ratio.');
                    }
                },
            ],
            'tone' => ['required', 'string', 'max:100'],
            'handoff_threshold' => 'required|numeric|min:0|max:1',
            'discount_limit' => 'required|numeric|min:0|max:100',
            'business_hours' => 'nullable|max:300',
            'delivery_timezone' => 'required|timezone',
            'delivery_local_cities' => [
                'required',
                'string',
                'max:300',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $cities = collect(explode(',', (string) $value))->map(fn ($city) => trim($city))->filter();
                    if ($cities->isEmpty()) {
                        $fail('Add at least one delivery city.');
                    }
                },
            ],
            'delivery_cutoff' => ['required', 'date_format:H:i'],
            'delivery_local_days' => 'required|integer|min:1|max:10',
            'delivery_regional_min_days' => 'required|integer|min:1|max:30',
            'delivery_regional_max_days' => 'required|integer|min:1|max:30|gte:delivery_regional_min_days',
        ]);
        $agent = $tenant->agent();
        $currentTheme = $agent->widgetTheme();
        $widgetTheme = array_key_exists('widget_theme_preset', $data)
            ? WidgetTheme::configured(
                $data['widget_theme_preset'],
                $data['widget_theme_primary'] ?? $currentTheme['primary'],
                $data['widget_theme_accent'] ?? $currentTheme['accent'],
            )
            : null;
        DB::transaction(function () use ($agent, $data, $widgetTheme): void {
            // Keep the workspace switcher and public AI identity in sync while
            // preserving stable slugs, URLs, memberships and all prior settings.
            $agent->organization()->update(['name' => $data['business_name']]);
            $settings = array_merge($agent->settings ?? [], [
                'handoff_threshold' => (float) $data['handoff_threshold'],
                'discount_limit' => (float) $data['discount_limit'],
                'business_hours' => $data['business_hours'],
                'delivery_policy' => [
                    'timezone' => $data['delivery_timezone'],
                    'local_cities' => collect(explode(',', $data['delivery_local_cities']))->map(fn ($city) => trim($city))->filter()->values()->all(),
                    'cutoff' => $data['delivery_cutoff'],
                    'local_business_days' => (int) $data['delivery_local_days'],
                    'regional_min_business_days' => (int) $data['delivery_regional_min_days'],
                    'regional_max_business_days' => (int) $data['delivery_regional_max_days'],
                    'source_label' => 'Configured delivery policy',
                ],
            ]);

            if ($widgetTheme !== null) {
                $settings['widget_theme'] = $widgetTheme;
            }

            $agent->update([
                'business_name' => $data['business_name'],
                'name' => $data['agent_name'],
                'tone' => $data['tone'],
                'settings' => $settings,
            ]);
        });

        return back()->with('success', 'Business identity and AI assistant settings updated.');
    }

    private function identityRules(int $maximum): array
    {
        return ['bail', 'required', 'string', "max:{$maximum}", function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                $fail('Use valid Unicode text for this name.');

                return;
            }

            if (preg_match('/[\p{Cc}]/u', $value) === 1) {
                $fail('Names cannot contain control characters.');
            }
        }];
    }

    private function trimUnicodeWhitespace(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? $value;
    }

    public function addMember(Request $r, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $r->validate(['email' => 'required|email|exists:users,email', 'role' => 'required|in:admin,agent,viewer']);
        $user = User::where('email', $data['email'])->firstOrFail();
        $tenant->organization()->users()->syncWithoutDetaching([$user->id => ['role' => $data['role']]]);

        return back()->with('success', 'Team member added.');
    }

    public function removeMember(User $user, TenantContext $tenant)
    {
        $tenant->authorize(['owner']);
        abort_unless(
            $tenant->organization()->users()->where('users.id', $user->getKey())->exists(),
            404,
        );
        abort_if($user->is(auth()->user()), 422, 'You cannot remove yourself.');
        $tenant->organization()->users()->detach($user);

        return back()->with('success', 'Team member removed.');
    }
}
