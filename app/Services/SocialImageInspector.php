<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SocialImageInspector
{
    private const TIMEOUT = 6;
    private const MAX_BYTES_FOR_DIMENSIONS = 2_500_000;

    public function __construct(private readonly UrlGuard $urls)
    {
    }

    public function inspect(array $urls): array
    {
        $targets = [];

        foreach ($urls as $url) {
            try {
                $safe = $this->urls->assertPublic((string) $url);
                $targets[$safe] = $safe;
            } catch (Throwable) {
                $targets[(string) $url] = null;
            }
        }

        $safeTargets = array_values(array_filter($targets));
        $results = [];

        foreach (array_chunk($safeTargets, 12) as $batch) {
            $responses = Http::pool(
                fn (Pool $pool) => array_map(
                    fn (string $url) => $pool
                        ->withHeaders([
                            'User-Agent' => 'MultilingualSEOAudit/'.config('audit.version'),
                            'Accept' => 'image/*,*/*;q=0.1',
                            'Range' => 'bytes=0-65535',
                        ])
                        ->withOptions(['allow_redirects' => false])
                        ->connectTimeout(3)
                        ->timeout(self::TIMEOUT)
                        ->get($url),
                    $batch,
                ),
                concurrency: 6,
            );

            foreach ($batch as $index => $url) {
                $response = $responses[$index] ?? null;

                if ($response instanceof Throwable || ! $response instanceof Response) {
                    $results[$url] = [
                        'url' => $url,
                        'status' => 0,
                        'contentType' => null,
                        'width' => null,
                        'height' => null,
                        'bytesRead' => 0,
                        'error' => $response instanceof Throwable ? $response->getMessage() : 'Request failed.',
                    ];
                    continue;
                }

                $body = $response->body();
                $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0] ?? ''));
                $width = null;
                $height = null;

                if (
                    $body !== ''
                    && strlen($body) <= self::MAX_BYTES_FOR_DIMENSIONS
                    && str_starts_with($contentType, 'image/')
                    && function_exists('getimagesizefromstring')
                ) {
                    $size = @getimagesizefromstring($body);
                    if (is_array($size)) {
                        $width = $size[0] ?? null;
                        $height = $size[1] ?? null;
                    }
                }

                $results[$url] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'contentType' => $contentType !== '' ? $contentType : null,
                    'width' => $width,
                    'height' => $height,
                    'bytesRead' => strlen($body),
                    'error' => null,
                ];
            }
        }

        foreach ($targets as $original => $safe) {
            if ($safe === null) {
                $results[$original] = [
                    'url' => $original,
                    'status' => 0,
                    'contentType' => null,
                    'width' => null,
                    'height' => null,
                    'bytesRead' => 0,
                    'error' => 'URL is not a permitted public HTTP/HTTPS target.',
                ];
            } elseif ($original !== $safe && isset($results[$safe])) {
                $results[$original] = $results[$safe];
            }
        }

        return $results;
    }
}
