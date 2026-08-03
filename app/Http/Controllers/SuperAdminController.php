<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PaddleSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    public function index(Request $request): View
    {
        $environment = config('paddle.environment');
        $search = trim((string) $request->query('search', ''));
        $organizations = Organization::query()
            ->with([
                'users' => fn ($query) => $query->orderBy('organization_user.created_at'),
                'agents:id,organization_id,name,business_name,is_active',
                'paddleSubscriptions' => fn ($query) => $query
                    ->where('environment', $environment)
                    ->latest('paddle_occurred_at'),
                'billingAccessGrants' => fn ($query) => $query->active()->latest(),
            ])
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhereHas('users', fn ($users) => $users
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"));
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $currentSubscriptions = PaddleSubscription::where('environment', $environment)
            ->orderByDesc('paddle_occurred_at')
            ->orderByDesc('id')
            ->get()
            ->unique('organization_id');
        $metrics = [
            'businesses' => Organization::count(),
            'new_this_month' => Organization::where('created_at', '>=', now()->startOfMonth())->count(),
            'active' => $currentSubscriptions->where('status', 'active')->filter(fn ($subscription) => ! $subscription->current_period_ends_at?->isPast())->count(),
            'trialing' => $currentSubscriptions->where('status', 'trialing')->filter(fn ($subscription) => ! $subscription->trial_ends_at?->isPast())->count(),
            'complimentary' => Organization::whereHas('billingAccessGrants', fn ($query) => $query->active())->count(),
            'attention' => $currentSubscriptions->filter(fn ($subscription) => in_array($subscription->status, ['past_due', 'paused', 'canceled'], true)
                || ($subscription->status === 'active' && $subscription->current_period_ends_at?->isPast())
                || ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isPast()))->count(),
        ];

        return view('super-admin.index', compact('organizations', 'metrics', 'environment', 'search'));
    }

    public function grantAccess(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'duration' => ['required', 'in:1_month,3_months,6_months,12_months,lifetime'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $expiresAt = match ($data['duration']) {
            '1_month' => now()->addMonth(), '3_months' => now()->addMonths(3),
            '6_months' => now()->addMonths(6), '12_months' => now()->addYear(), 'lifetime' => null,
        };

        DB::transaction(function () use ($request, $organization, $data, $expiresAt): void {
            $organization->billingAccessGrants()->active()->update([
                'revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id,
            ]);
            $organization->billingAccessGrants()->create([
                'granted_by_user_id' => $request->user()->id,
                'kind' => 'complimentary',
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
                'expires_at' => $expiresAt,
            ]);
        });

        return back()->with('status', "Complimentary access granted to {$organization->name}.");
    }

    public function revokeAccess(Request $request, Organization $organization): RedirectResponse
    {
        $organization->billingAccessGrants()->active()->update([
            'revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', "Complimentary access revoked for {$organization->name}.");
    }
}
