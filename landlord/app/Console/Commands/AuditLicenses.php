<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuditLicenses extends Command
{
    protected $signature = 'licenses:audit';
    protected $description = 'Audit licenses to flag expired subscriptions';

    public function handle()
    {
        $this->info('Starting license audit...');
        Log::info('License audit started.');

        $now = Carbon::now();
        $expiredCount = 0;

        DB::table('licenses')
            ->where('status', '!=', 'expired')
            ->where('status', '!=', 'past_due')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->chunkById(100, function ($licenses) use (&$expiredCount) {
                foreach ($licenses as $license) {
                    DB::table('licenses')
                        ->where('id', $license->id)
                        ->update(['status' => 'expired', 'updated_at' => now()]);
                    
                    $expiredCount++;
                    Log::info("License {$license->id} for tenant {$license->tenant_id} marked as expired.");
                }
            });

        $this->info("License audit completed. {$expiredCount} licenses expired.");
        Log::info("License audit completed. {$expiredCount} licenses expired.");
        
        return 0;
    }
}
