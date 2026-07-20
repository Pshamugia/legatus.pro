<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;

class TenantContext
{
    public const SESSION_KEY = 'legatus_organization_id';

    public function organization(?User $user = null): Organization
    {
        $user ??= auth()->user();
        if (! $user) {
            throw new \RuntimeException('No authenticated user.');
        }

        $selectedId = session(self::SESSION_KEY);
        $organization = $selectedId ? $user->organizations()->whereKey($selectedId)->first() : null;
        $organization ??= $user->organizations()->orderBy('organizations.id')->firstOrFail();
        session([self::SESSION_KEY => $organization->id]);

        return $organization;
    }

    public function activate(int $organizationId, ?User $user = null): Organization
    {
        $user ??= auth()->user();
        if (! $user) {
            throw new \RuntimeException('No authenticated user.');
        }

        // Query through the membership relation so a guessed organization ID is
        // indistinguishable from a missing workspace and can never become active.
        $organization = $user->organizations()->whereKey($organizationId)->firstOrFail();
        session([self::SESSION_KEY => $organization->id]);

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
