<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Persist synchronous reports in the same 7-day private archive as queued audits. */
class CompletedAuditArchive
{
    public function store(array $result, string $url, int $maxPages, string $environment): string
    {
        $id = (string) Str::uuid();
        $path = 'seo-audits/'.$id.'.json.gz';
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! Storage::disk('local')->put($path, gzencode($json, 6))) {
            throw new \RuntimeException('Could not write audit archive.');
        }
        try {
            DB::table('audit_runs')->insert([
                'id' => $id,
                'url' => $url,
                'environment' => $environment,
                'max_pages' => $maxPages,
                'pages_done' => count($result['pages'] ?? []),
                'status' => 'completed',
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return $id;
    }
}
