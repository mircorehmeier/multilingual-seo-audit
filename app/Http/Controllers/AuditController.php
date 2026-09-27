<?php

namespace App\Http\Controllers;

use App\Services\SiteAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController
{
    public function __invoke(Request $request, SiteAuditor $auditor): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'maxPages' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $auditor->audit(
            $validated['url'],
            (int) ($validated['maxPages'] ?? 25),
        );

        return response()->json($result);
    }
}
