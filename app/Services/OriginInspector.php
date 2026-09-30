<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OriginInspector
{
    private const TIMEOUT = 6;

    public function __construct(private readonly UrlGuard $urls)
    {
    }

    public function inspect(string $startUrl, string $preferredOrigin): array
    {
        $variants = $this->variants($startUrl, $preferredOrigin);
        $results = [];
        $issues = [];
        $directNonpreferred = [];

        foreach ($variants as $variant) {
            try {
                $safe = $this->urls->assertPublic($variant);
                [$response, $finalUrl, $chain] = $this->fetch($safe);
                $finalOrigin = $this->urls->origin($finalUrl);
                $variantOrigin = $this->urls->origin($safe);
                $status = $response->status();

                $relation = $variantOrigin === $preferredOrigin
                    ? 'preferred'
                    : ($finalOrigin === $preferredOrigin && $chain !== []
                        ? 'redirects-to-preferred'
                        : (($status >= 200 && $status < 300) ? 'direct-nonpreferred' : 'other'));

                $results[] = [
                    'url' => $safe,
                    'status' => $status,
                    'finalUrl' => $finalUrl,
                    'finalOrigin' => $finalOrigin,
                    'redirects' => count($chain),
                    'chain' => $chain,
                    'relation' => $relation,
                    'error' => null,
                ];

                if ($relation === 'direct-nonpreferred') {
                    $directNonpreferred[$variantOrigin] = $variantOrigin;
                }
            } catch (Throwable $exception) {
                $results[] = [
                    'url' => $variant,
                    'status' => 0,
                    'finalUrl' => $variant,
                    'finalOrigin' => null,
                    'redirects' => 0,
                    'chain' => [],
                    'relation' => 'unreachable',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($directNonpreferred !== []) {
            $origins = array_values($directNonpreferred);
            $examples = implode(', ', array_slice($origins, 0, 3));
            $extra = count($origins) > 3 ? ' +'.(count($origins) - 3).' more' : '';

            $issues[] = [
                'code' => 'origin_variant_direct_200',
                'severity' => 'warning',
                'message' => count($origins).' non-preferred origin'.(count($origins) === 1 ? '' : 's').
                    ' serve HTTP 2xx without redirecting to '.$preferredOrigin.': '.$examples.$extra.'.',
            ];
        }

        return [
            'preferredOrigin' => $preferredOrigin,
            'variants' => $results,
            'issues' => $this->uniqueIssues($issues),
        ];
    }

    private function variants(string $startUrl, string $preferredOrigin): array
    {
        $preferredScheme = strtolower((string) parse_url($preferredOrigin, PHP_URL_SCHEME));
        $preferredHost = strtolower((string) parse_url($preferredOrigin, PHP_URL_HOST));
        $preferredPort = parse_url($preferredOrigin, PHP_URL_PORT);
        $startScheme = strtolower((string) parse_url($startUrl, PHP_URL_SCHEME));
        $startHost = strtolower((string) parse_url($startUrl, PHP_URL_HOST));
        $portSuffix = $preferredPort ? ':'.$preferredPort : '';

        $variants = [];
        $add = static function (array &$target, string $url): void {
            $target[$url] = $url;
        };

        $add($variants, $preferredScheme.'://'.$preferredHost.$portSuffix.'/');
        $add($variants, $startScheme.'://'.$startHost.$portSuffix.'/');

        $alternateScheme = $preferredScheme === 'https' ? 'http' : 'https';
        $add($variants, $alternateScheme.'://'.$preferredHost.$portSuffix.'/');

        if (! filter_var($preferredHost, FILTER_VALIDATE_IP)) {
            $alternateHost = str_starts_with($preferredHost, 'www.')
                ? substr($preferredHost, 4)
                : 'www.'.$preferredHost;

            if ($alternateHost !== '') {
                $add($variants, $preferredScheme.'://'.$alternateHost.$portSuffix.'/');
                $add($variants, $alternateScheme.'://'.$alternateHost.$portSuffix.'/');
            }
        }

        return array_values($variants);
    }

    private function fetch(string $url): array
    {
        $response = Http::withHeaders([
                'User-Agent' => 'MultilingualSEOAudit/'.config('audit.version').' (+https://github.com/mircorehmeier/multilingual-seo-audit)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
            ])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)
            ->timeout(self::TIMEOUT)
            ->get($url);

        $current = $url;
        $chain = [];

        for ($hop = 0; $hop < 5; $hop++) {
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $current, $chain];
            }

            $location = trim($response->header('Location'));
            if ($location === '') {
                return [$response, $current, $chain];
            }

            $resolved = $this->urls->resolve($current, $location);
            if ($resolved === null) {
                throw new \RuntimeException('Redirect target is invalid.');
            }

            $target = $this->urls->assertPublic($resolved);
            $chain[] = [
                'from' => $current,
                'status' => $response->status(),
                'to' => $target,
            ];
            $current = $target;

            $response = Http::withHeaders([
                    'User-Agent' => 'MultilingualSEOAudit/'.config('audit.version').' (+https://github.com/mircorehmeier/multilingual-seo-audit)',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(self::TIMEOUT)
                ->get($current);
        }

        throw new \RuntimeException('Too many redirects while checking origin variants.');
    }

    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            $key = $issue['code'].'|'.$issue['message'];
            $unique[$key] = $issue;
        }

        return array_values($unique);
    }
}
