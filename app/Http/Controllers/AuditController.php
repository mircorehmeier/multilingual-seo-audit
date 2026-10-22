<?php

namespace App\Http\Controllers;

use App\Services\SiteAuditor;
use App\Services\CompletedAuditArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AuditController
{
    public function __invoke(Request $request, SiteAuditor $auditor, CompletedAuditArchive $archive): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'maxPages' => ['nullable', 'integer', 'min:1', 'max:100'],
            'environment' => ['nullable', 'string', 'in:live,staging'],
        ]);

        try {
            $result = $auditor->audit(
                $validated['url'],
                (int) ($validated['maxPages'] ?? 25),
                (string) ($validated['environment'] ?? 'live'),
            );

            // Saving is best effort: an archive failure must not lose a finished audit.
            try {
                $result['savedAuditId'] = $archive->store(
                    $result,
                    (string) ($result['startUrl'] ?? $validated['url']),
                    (int) ($validated['maxPages'] ?? 25),
                    (string) ($validated['environment'] ?? 'live'),
                );
            } catch (Throwable $archiveError) {
                report($archiveError);
                $result['archiveWarning'] = 'Audit completed, but could not be saved to private history.';
            }

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
