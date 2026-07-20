<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Organization;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request, TenantContext $tenant): View
    {
        $activeWorkspace = $tenant->organization($request->user());
        $workspaces = $request->user()->organizations()
            ->with(['agents' => fn ($query) => $query->orderBy('agents.id')])
            ->orderBy('organizations.id')
            ->get();

        return view('workspaces.index', compact('workspaces', 'activeWorkspace'));
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $request->merge(['business_name' => $this->trimUnicodeWhitespace($request->input('business_name'))]);
        $data = $request->validate([
            'business_name' => ['bail', 'required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                    $fail('Use valid Unicode text for the business name.');

                    return;
                }
                if (preg_match('/[\p{Cc}]/u', $value) === 1) {
                    $fail('Business names cannot contain control characters.');
                }
            }],
        ]);
        $user = $request->user();

        $workspace = DB::transaction(function () use ($data, $user): Organization {
            $workspace = Organization::create([
                'name' => $data['business_name'],
                'slug' => $this->uniqueOrganizationSlug($data['business_name']),
                'plan' => 'build-week',
                'settings' => [],
            ]);
            $workspace->users()->attach($user, ['role' => 'owner']);
            $workspace->agents()->create([
                'name' => 'AI Assistant',
                'slug' => $this->uniqueAgentSlug($data['business_name']),
                'business_name' => $data['business_name'],
                'channels' => ['web'],
                'settings' => [
                    'handoff_threshold' => .72,
                    'discount_limit' => 10,
                    'delivery_policy' => null,
                ],
            ]);

            return $workspace;
        });

        $tenant->activate((int) $workspace->id, $user);
        $request->session()->regenerate();

        return to_route('onboarding')->with('status', 'New business workspace created. Complete its guided setup.');
    }

    public function switch(Request $request, int $workspace, TenantContext $tenant): RedirectResponse
    {
        $organization = $tenant->activate($workspace, $request->user());
        $request->session()->regenerate();

        return to_route('dashboard')->with('status', "Switched to {$organization->name}.");
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        do {
            $slug = $base.'-'.Str::lower(Str::random(8));
        } while (Organization::where('slug', $slug)->exists());

        return $slug;
    }

    private function uniqueAgentSlug(string $businessName): string
    {
        $base = Str::slug($businessName) ?: 'business';

        do {
            $slug = $base.'-agent-'.Str::lower(Str::random(8));
        } while (Agent::where('slug', $slug)->exists());

        return $slug;
    }

    private function trimUnicodeWhitespace(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? $value;
    }
}
