<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\EvalCaseSeeder;
use Database\Seeders\LegatusDemoSeeder;
use Illuminate\Console\Command;

class BootstrapDemoTenant extends Command
{
    protected $signature = 'legatus:bootstrap-demo-tenant';

    protected $description = 'Idempotently prepare the complete local Legatus demo workspace and evaluation cases';

    public function handle(): int
    {
        $legacy = User::where('email', 'demo@nia.ai')->first();
        if ($legacy && ! User::where('email', 'demo@legatus.ai')->exists()) {
            $legacy->update(['email' => 'demo@legatus.ai']);
        }

        app(LegatusDemoSeeder::class)->run();
        app(EvalCaseSeeder::class)->run();
        $this->info('Legatus demo workspace ready: demo@legatus.ai');

        return self::SUCCESS;
    }
}
