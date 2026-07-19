<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;

class TenantContext
{
    public function organization(?User $user = null): Organization
    {
        $user ??= auth()->user();
        if (! $user) {
            throw new \RuntimeException('No authenticated user.');
        }

        $selectedId = session('legatus_organization_id');
        $organization = $selectedId ? $user->organizations()->whereKey($selectedId)->first() : null;
        $organization ??= $user->organizations()->orderBy('organizations.id')->firstOrFail();
        session(['legatus_organization_id' => $organization->id]);

        return $organization;
    }

    public function agent(?User $user = null): Agent
    {
        return $this->organization($user)->agents()->orderBy('agents.id')->firstOrFail();
    }

    public function role(?User $user = null): string
    {
        $user ??= auth()->user();
        $org = $this->organization($user);

        return $org->users()->whereKey($user->id)->firstOrFail()->pivot->role;
    }

    public function authorize(array $roles): void
    {
        abort_unless(in_array($this->role(), $roles, true), 403);
    }
}
