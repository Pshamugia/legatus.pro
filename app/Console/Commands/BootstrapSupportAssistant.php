<?php

namespace App\Console\Commands;

use App\Services\LegatusSupportAssistant;
use Illuminate\Console\Command;

class BootstrapSupportAssistant extends Command
{
    protected $signature = 'legatus:bootstrap-support-assistant';

    protected $description = 'Idempotently prepare the official Legatus website support assistant';

    public function handle(LegatusSupportAssistant $support): int
    {
        $agent = $support->bootstrap();

        $this->info("Legatus website assistant ready: {$agent->slug}");

        return self::SUCCESS;
    }
}
