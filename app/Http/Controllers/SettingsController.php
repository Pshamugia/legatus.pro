<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\Request;

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
        $data = $r->validate([
            'agent_name' => 'required|max:80',
            'tone' => 'required|max:100',
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
        $agent->update(['name' => $data['agent_name'], 'tone' => $data['tone'], 'settings' => array_merge($agent->settings ?? [], [
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
        ])]);

        return back()->with('success', 'AI employee settings updated.');
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
        abort_if($user->is(auth()->user()), 422, 'You cannot remove yourself.');
        $tenant->organization()->users()->detach($user);

        return back()->with('success', 'Team member removed.');
    }
}
