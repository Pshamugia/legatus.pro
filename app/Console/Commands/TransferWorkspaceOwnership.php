<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TransferWorkspaceOwnership extends Command
{
    protected $signature = 'legatus:transfer-workspace
        {from-email : Current owner email}
        {to-email : New owner email}
        {--business= : Exact organization name}
        {--keep-source : Keep the old account as a workspace member}';

    protected $description = 'Safely transfer a workspace to another registered user and preserve all tenant data';

    public function handle(): int
    {
        $fromEmail = strtolower(trim((string) $this->argument('from-email')));
        $toEmail = strtolower(trim((string) $this->argument('to-email')));
        $business = trim((string) $this->option('business'));
        $source = User::whereRaw('LOWER(email) = ?', [$fromEmail])->first();
        $target = User::whereRaw('LOWER(email) = ?', [$toEmail])->first();

        if (! $source || ! $target) {
            $this->error('Both source and target users must already exist.');
            return self::FAILURE;
        }

        $query = Organization::query()->whereHas('users', fn ($query) => $query
            ->whereKey($source->id)->where('organization_user.role', 'owner'));
        if ($business !== '') {
            $query->where('name', $business);
        }
        $organizations = $query->get();
        if ($organizations->count() !== 1) {
            $this->error('Expected exactly one matching workspace; found '.$organizations->count().'. No changes made.');
            return self::FAILURE;
        }

        $organization = $organizations->firstOrFail();
        DB::transaction(function () use ($organization, $source, $target): void {
            $organization->users()->syncWithoutDetaching([$target->id => ['role' => 'owner']]);
            $organization->users()->updateExistingPivot($target->id, ['role' => 'owner']);

            if ($this->option('keep-source')) {
                $organization->users()->updateExistingPivot($source->id, ['role' => 'admin']);
            } else {
                $organization->users()->detach($source->id);
            }

            if ($organization->billingAccessGrants()->active()->doesntExist()) {
                $organization->billingAccessGrants()->create([
                    'granted_by_user_id' => $target->id,
                    'kind' => 'complimentary',
                    'reason' => 'Owner business',
                    'expires_at' => null,
                ]);
            }
        });

        $this->info("Workspace transferred successfully: {$organization->name} (#{$organization->id})");
        $this->line("New owner: {$target->email}");
        $this->line('Complimentary access: Lifetime');

        return self::SUCCESS;
    }
}
