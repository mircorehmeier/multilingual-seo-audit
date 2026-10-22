<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditHistoryController
{
    private function siteKey(string $url, string $environment): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\\./', '', $host) ?? $host;

        return $host.'|'.$environment;
    }

    private function latestPairs(): array
    {
        $runs = DB::table('audit_runs')->where('status', 'completed')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'url', 'environment', 'created_at']);

        $groups = [];
        foreach ($runs as $run) {
            $key = $this->siteKey($run->url, $run->environment);
            if (count($groups[$key] ?? []) < 2) {
                $groups[$key][] = $run;
            }
        }

        $pairs = [];
        foreach ($groups as $group) {
            if (count($group) === 2) {
                $pairs[$group[0]->id] = $group[1]->id;
            }
        }
        return $pairs;
    }

    public function index(Request $request): View
    {
        $query = DB::table('audit_runs')->where('expires_at', '>', now());
        $search = trim((string) $request->query('domain', ''));
        if ($search !== '') {
            $search = mb_substr($search, 0, 150);
            $query->where('url', 'like', '%'.$search.'%');
        }
        $runs = $query->orderByDesc('created_at')->paginate(30)->withQueryString();
        $progressPairs = $this->latestPairs();

        return view('audit-history', compact('runs', 'search', 'progressPairs'));
    }

    public function progress(string $id): RedirectResponse
    {
        $previous = $this->latestPairs()[$id] ?? null;
        if ($previous === null) {
            return redirect()->route('audit.history')
                ->with('notice', 'Progress requires two unexpired completed audits for the same site and environment. Only the latest audit can be compared with its immediate predecessor.');
        }

        return redirect('/?audit='.rawurlencode($id).'&previous='.rawurlencode($previous));
    }
}
