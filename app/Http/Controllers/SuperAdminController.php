<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PaddleSubscription;
use App\Services\OpenAiCostEstimator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    public function index(Request $request, OpenAiCostEstimator $costEstimator): View
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

        $monthStart = now()->startOfMonth();
        $todayStart = now()->startOfDay();
        $usageRows = DB::table('agent_runs')
            ->join('agents', 'agents.id', '=', 'agent_runs.agent_id')
            ->where('agent_runs.provider', 'openai')
            ->where('agent_runs.created_at', '>=', $monthStart)
            ->where(function ($query): void {
                $query->where('agent_runs.input_tokens', '>', 0)
                    ->orWhere('agent_runs.output_tokens', '>', 0);
            })
            ->get([
                'agents.organization_id', 'agent_runs.model', 'agent_runs.route',
                'agent_runs.input_tokens', 'agent_runs.output_tokens',
                'agent_runs.model_usage', 'agent_runs.created_at',
            ]);
        $usageByOrganization = $usageRows->groupBy('organization_id');
        $defaultTarget = (float) config('openai_costs.monthly_target_usd', 5.0);

        foreach ($organizations as $organization) {
            $organizationRows = $usageByOrganization->get($organization->id, collect());
            $target = max(0.01, (float) data_get($organization->settings, 'ai_monthly_cost_target_usd', $defaultTarget));
            $organization->setAttribute('ai_usage', array_merge(
                $this->summarizeAiUsage($organizationRows, $costEstimator),
                [
                    'today_usd' => $this->summarizeAiUsage(
                        $organizationRows->filter(fn ($row) => Carbon::parse($row->created_at)->greaterThanOrEqualTo($todayStart)),
                        $costEstimator,
                    )['usd'],
                    'target_usd' => $target,
                ],
            ));
        }

        $monthlyAiUsage = $this->summarizeAiUsage($usageRows, $costEstimator);
        $todayAiUsage = $this->summarizeAiUsage(
            $usageRows->filter(fn ($row) => Carbon::parse($row->created_at)->greaterThanOrEqualTo($todayStart)),
            $costEstimator,
        );

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
            'ai_month_usd' => $monthlyAiUsage['usd'],
            'ai_today_usd' => $todayAiUsage['usd'],
            'ai_sol_fallbacks' => $monthlyAiUsage['sol_fallbacks'],
            'ai_unpriced_models' => $monthlyAiUsage['unpriced_models'],
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

    /**
     * @return array{usd: float, runs: int, requests: int, luna_requests: int, sol_requests: int, sol_fallbacks: int, unpriced_models: array<int, string>}
     */
    private function summarizeAiUsage(Collection $rows, OpenAiCostEstimator $estimator): array
    {
        $summary = [
            'usd' => 0.0,
            'runs' => $rows->count(),
            'requests' => 0,
            'luna_requests' => 0,
            'sol_requests' => 0,
            'sol_fallbacks' => $rows->where('route', 'primary_to_fallback')->count(),
            'unpriced_models' => [],
        ];

        foreach ($rows as $row) {
            $estimate = $estimator->estimate($row);
            $summary['usd'] += $estimate['usd'];
            $summary['requests'] += $estimate['requests'];
            $summary['luna_requests'] += $estimate['luna_requests'];
            $summary['sol_requests'] += $estimate['sol_requests'];
            $summary['unpriced_models'] = array_merge($summary['unpriced_models'], $estimate['unpriced_models']);
        }

        $summary['unpriced_models'] = array_values(array_unique($summary['unpriced_models']));

        return $summary;
    }
}
