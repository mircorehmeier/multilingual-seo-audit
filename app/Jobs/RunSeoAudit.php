<?php

namespace App\Jobs;

use App\Services\SiteAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunSeoAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public string $runId)
    {
        $this->onQueue('audits');
    }

    public function handle(SiteAuditor $auditor): void
    {
        $run = DB::table('audit_runs')->where('id', $this->runId)->first();
        if (! $run || $run->status !== 'queued') {
            return;
        }

        DB::table('audit_runs')->where('id', $this->runId)->update([
            'status' => 'running', 'updated_at' => now(),
        ]);

        try {
            $result = $auditor->audit(
                $run->url,
                (int) $run->max_pages,
                $run->environment,
                function (int $done): void {
                    DB::table('audit_runs')->where('id', $this->runId)->update([
                        'pages_done' => $done, 'updated_at' => now(),
                    ]);
                }
            );
            // Only the last result is retained, compressed on private storage.
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            Storage::disk('local')->put('seo-audits/'.$this->runId.'.json.gz', gzencode($json, 6));
            DB::table('audit_runs')->where('id', $this->runId)->update([
                'status' => 'completed',
                'pages_done' => count($result['pages'] ?? []),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->markFailure('Audit processing failed; check the Laravel logs.');
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailure('The audit worker stopped before completing. Review Laravel logs and worker timeout settings.');
    }

    private function markFailure(string $message): void
    {
        DB::table('audit_runs')->where('id', $this->runId)
            ->whereNotIn('status', ['completed', 'expired'])
            ->update(['status' => 'failed', 'error' => $message, 'updated_at' => now()]);
    }
}
