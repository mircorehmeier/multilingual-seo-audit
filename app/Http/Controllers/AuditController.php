<?php

namespace App\Http\Controllers;

use App\Services\SiteAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AuditController
{
    public function __invoke(Request $request, SiteAuditor $auditor): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'maxPages' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $result = $auditor->audit(
                $validated['url'],
                (int) ($validated['maxPages'] ?? 25),
            );

            return response()->json($result);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => 'The audit could not be completed. Please try again.',
            ], 500);
        }
    }
}
