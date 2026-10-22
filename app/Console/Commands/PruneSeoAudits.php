<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneSeoAudits extends Command
{
    protected $signature = 'seo-audit:prune';
    protected $description = 'Remove expired SEO audit data from private storage and database';

    public function handle(): int
    {
        DB::table('audit_runs')->where('expires_at', '<=', now())
            ->orderBy('created_at')->chunk(100, function ($runs): void {
                foreach ($runs as $run) {
                    Storage::disk('local')->delete('seo-audits/'.$run->id.'.json.gz');
                    DB::table('audit_runs')->where('id', $run->id)->delete();
                }
            });
        $this->info('Expired SEO audits removed.');
        return self::SUCCESS;
    }
}
