<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditHistoryController
{
    public function index(Request $request): View
    {
        $query = DB::table('audit_runs')->where('expires_at', '>', now());

        $search = trim((string) $request->query('domain', ''));
        if ($search !== '') {
            $search = mb_substr($search, 0, 150);
            $query->where('url', 'like', '%'.$search.'%');
        }

        $runs = $query->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('audit-history', compact('runs', 'search'));
    }
}
