<?php

namespace App\Http\Controllers;

use App\Jobs\RunSeoAudit;
use App\Services\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AsyncAuditController
{
    public function start(Request $request, UrlGuard $urls): JsonResponse
    {
        if (config('queue.default') !== 'database') {
            return response()->json(['error' => 'Background queue is not configured. Set QUEUE_CONNECTION=database and run the queue worker.'], 503);
        }
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'maxPages' => ['required', 'integer', 'min:1', 'max:1000'],
            'environment' => ['nullable', 'in:live,staging'],
        ]);
        $url = trim($validated['url']);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        try {
            $url = $urls->assertPublic($url);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        // Limit queued/running load: these crawls can be resource intensive.
        $active = DB::table('audit_runs')->whereIn('status', ['queued', 'running'])
            ->where('expires_at', '>', now())->count();
        if ($active >= 5) {
            return response()->json(['error' => 'Too many pending audits. Try again after existing audits complete.'], 429);
        }

        // Small retention window; old reports are not stored permanently.
        $id = (string) Str::uuid();
        DB::table('audit_runs')->insert([
            'id' => $id,
            'url' => $url,
            'environment' => $validated['environment'] ?? 'live',
            'max_pages' => (int) $validated['maxPages'],
            'status' => 'queued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RunSeoAudit::dispatch($id);

        return response()->json(['id' => $id, 'status' => 'queued'], 202);
    }

    public function status(string $id): JsonResponse
    {
        $run = DB::table('audit_runs')->where('id', $id)
            ->where('expires_at', '>', now())->first();
        if (! $run) {
            return response()->json(['error' => 'Audit not found or expired.'], 404);
        }
        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'pagesDone' => $run->pages_done,
            'maxPages' => $run->max_pages,
            'error' => $run->error,
        ]);
    }

    public function result(string $id): JsonResponse|Response
    {
        $run = DB::table('audit_runs')->where('id', $id)
            ->where('expires_at', '>', now())->first();
        if (! $run) {
            return response()->json(['error' => 'Audit not found or expired.'], 404);
        }
        if ($run->status !== 'completed') {
            return response()->json(['error' => 'Audit is not complete.'], 409);
        }
        $path = 'seo-audits/'.$id.'.json.gz';
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Audit result file is unavailable.'], 404);
        }
        $contents = gzdecode(Storage::disk('local')->get($path));
        if ($contents === false) {
            return response()->json(['error' => 'Audit result is corrupted.'], 500);
        }
        return response($contents, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
