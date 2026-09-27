<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteAuditor
{
    private const USER_AGENT = 'MultilingualSEOAudit/0.2 (+https://github.com/mircorehmeier/multilingual-seo-audit)';
    private const CONCURRENCY = 6;
    private const TIMEOUT = 8;
    private const MAX_BODY_BYTES = 2_500_000;

    public function __construct(
        private readonly UrlGuard $urls,
        private readonly HtmlAuditor $html,
    ) {
    }

    public function audit(string $startInput, int $requestedMaxPages = 25): array
    {
        $startInput = trim($startInput);

        if (! preg_match('#^https?://#i', $startInput)) {
            $startInput = 'https://'.$startInput;
        }

        $startUrl = $this->urls->assertPublic($startInput);
        $maxPages = max(1, min(100, $requestedMaxPages));

        $queue = [$startUrl];
        $queued = [$startUrl => true];
        $visited = [];
        $pages = [];
        $crawlOrigin = $this->urls->origin($startUrl);

        while ($queue !== [] && count($pages) < $maxPages) {
            $batch = [];

            while (
                $queue !== []
                && count($batch) < self::CONCURRENCY
                && count($pages) + count($batch) < $maxPages
            ) {
                $candidate = array_shift($queue);

                if (isset($visited[$candidate])) {
                    continue;
                }

                $visited[$candidate] = true;
                $batch[] = $candidate;
            }

            if ($batch === []) {
                continue;
            }

            $responses = Http::pool(
                fn (Pool $pool) => array_map(
                    fn (string $url) => $pool
                        ->withHeaders([
                            'User-Agent' => self::USER_AGENT,
                            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                        ])
                        ->withOptions(['allow_redirects' => false])
                        ->connectTimeout(3)
                        ->timeout(self::TIMEOUT)
                        ->get($url),
                    $batch,
                ),
                concurrency: self::CONCURRENCY,
            );

            foreach ($batch as $index => $requestedUrl) {
                if (count($pages) >= $maxPages) {
                    break;
                }

                $response = $responses[$index] ?? null;

                if ($response instanceof Throwable || ! $response instanceof Response) {
                    $pages[] = $this->failedPage(
                        $requestedUrl,
                        $response instanceof Throwable ? $response->getMessage() : 'Request failed.',
                    );
                    continue;
                }

                try {
                    [$response, $finalUrl] = $this->followRedirects($requestedUrl, $response);
                } catch (Throwable $exception) {
                    $pages[] = $this->failedPage($requestedUrl, $exception->getMessage());
                    continue;
                }

                $contentType = strtolower($response->header('Content-Type'));

                if (
                    ! str_contains($contentType, 'text/html')
                    && ! str_contains($contentType, 'application/xhtml+xml')
                ) {
                    continue;
                }

                $body = $response->body();

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    $page = $this->failedPage($finalUrl, 'HTML response exceeds the 2.5 MB audit limit.');
                    $page['status'] = $response->status();
                    $page['contentType'] = $contentType;
                    $pages[] = $page;
                    continue;
                }

                $page = $this->html->parse(
                    $finalUrl,
                    $response->status(),
                    $contentType,
                    $body,
                );

                if ($response->status() >= 400) {
                    $this->issue($page, 'http_error', 'error', 'HTTP '.$response->status().'.');
                } elseif ($response->status() >= 300) {
                    $this->issue($page, 'http_redirect', 'warning', 'HTTP '.$response->status().'.');
                }

                if ($pages === []) {
                    $crawlOrigin = $this->urls->origin($finalUrl);
                }

                $pages[] = $page;

                foreach ($page['links'] as $link) {
                    if (
                        ! $this->urls->sameOrigin($crawlOrigin, $link)
                        || isset($queued[$link])
                        || isset($visited[$link])
                    ) {
                        continue;
                    }

                    $queued[$link] = true;
                    $queue[] = $link;
                }
            }
        }

        $this->addCrossPageChecks($pages);

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $languages = [];
        $hreflangCodes = [];

        foreach ($pages as $page) {
            if (! empty($page['lang'])) {
                $languages[strtolower($page['lang'])] = true;
            }

            foreach ($page['hreflangs'] as $entry) {
                $hreflangCodes[$entry['lang']] = true;
            }

            foreach ($page['issues'] as $issue) {
                $counts[$issue['severity']]++;
            }
        }

        $languageList = array_keys($languages);
        $hreflangList = array_keys($hreflangCodes);
        sort($languageList);
        sort($hreflangList);

        return [
            'startUrl' => $startUrl,
            'origin' => $crawlOrigin,
            'auditedAt' => now()->toIso8601String(),
            'maxPages' => $maxPages,
            'pages' => $pages,
            'summary' => [
                'pages' => count($pages),
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'info' => $counts['info'],
                'languages' => $languageList,
                'hreflangCodes' => $hreflangList,
            ],
        ];
    }

    private function followRedirects(string $url, Response $response): array
    {
        $currentUrl = $url;

        for ($hop = 0; $hop < 5; $hop++) {
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $currentUrl];
            }

            $location = trim($response->header('Location'));

            if ($location === '') {
                return [$response, $currentUrl];
            }

            $resolved = $this->urls->resolve($currentUrl, $location);

            if ($resolved === null) {
                throw new \RuntimeException('Redirect target is invalid.');
            }

            $currentUrl = $this->urls->assertPublic($resolved);

            $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(self::TIMEOUT)
                ->get($currentUrl);
        }

        throw new \RuntimeException('Too many redirects.');
    }

    private function addCrossPageChecks(array &$pages): void
    {
        $byUrl = [];
        $titles = [];
        $descriptions = [];

        foreach ($pages as $index => $page) {
            try {
                $byUrl[$this->urls->normalize($page['url'])] = $index;
            } catch (Throwable) {
                //
            }

            if ($page['title'] !== '') {
                $titles[mb_strtolower($page['title'])][] = $index;
            }

            if ($page['description'] !== '') {
                $descriptions[mb_strtolower($page['description'])][] = $index;
            }
        }

        foreach ($titles as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $this->issue(
                        $pages[$index],
                        'title_duplicate',
                        'warning',
                        'Duplicate title across '.count($indexes).' audited pages.',
                    );
                }
            }
        }

        foreach ($descriptions as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $this->issue(
                        $pages[$index],
                        'description_duplicate',
                        'warning',
                        'Duplicate meta description across '.count($indexes).' audited pages.',
                    );
                }
            }
        }

        foreach ($pages as $index => $page) {
            try {
                $sourceUrl = $this->urls->normalize($page['url']);
            } catch (Throwable) {
                continue;
            }

            foreach ($page['hreflangs'] as $alternate) {
                try {
                    $targetUrl = $this->urls->normalize($alternate['href']);
                } catch (Throwable) {
                    continue;
                }

                if ($targetUrl === $sourceUrl || ! isset($byUrl[$targetUrl])) {
                    continue;
                }

                $target = $pages[$byUrl[$targetUrl]];
                $reciprocal = false;

                foreach ($target['hreflangs'] as $entry) {
                    try {
                        if ($this->urls->normalize($entry['href']) === $sourceUrl) {
                            $reciprocal = true;
                            break;
                        }
                    } catch (Throwable) {
                        //
                    }
                }

                if (! $reciprocal) {
                    $this->issue(
                        $pages[$index],
                        'hreflang_not_reciprocal',
                        'warning',
                        $alternate['lang'].' alternate does not link back from '.$target['url'].'.',
                    );
                }
            }
        }
    }

    private function failedPage(string $url, string $message): array
    {
        return [
            'url' => $url,
            'status' => 0,
            'contentType' => '',
            'title' => '',
            'description' => '',
            'canonical' => null,
            'lang' => null,
            'robots' => null,
            'hreflangs' => [],
            'openGraph' => [
                'title' => null,
                'description' => null,
                'image' => null,
                'url' => null,
            ],
            'twitter' => [
                'card' => null,
                'title' => null,
                'description' => null,
                'image' => null,
            ],
            'structuredData' => [
                'scripts' => 0,
                'valid' => 0,
                'invalid' => 0,
                'types' => [],
            ],
            'issues' => [[
                'code' => 'fetch_failed',
                'severity' => 'error',
                'message' => $message,
            ]],
            'links' => [],
        ];
    }

    private function issue(array &$page, string $code, string $severity, string $message): void
    {
        foreach ($page['issues'] as $issue) {
            if ($issue['code'] === $code && $issue['message'] === $message) {
                return;
            }
        }

        $page['issues'][] = compact('code', 'severity', 'message');
    }
}
