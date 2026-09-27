<?php

namespace App\Services;

use DOMDocument;
use DOMNodeList;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteAuditor
{
    private const USER_AGENT = 'MultilingualSEOAudit/0.4 (+https://github.com/mircorehmeier/multilingual-seo-audit)';
    private const CONCURRENCY = 6;
    private const TIMEOUT = 8;
    private const RESOURCE_TIMEOUT = 5;
    private const MAX_BODY_BYTES = 2_500_000;
    private const MAX_SITEMAP_BYTES = 5_000_000;
    private const MAX_SITEMAPS = 8;
    private const MAX_SITEMAP_URLS = 1000;

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
        $crawlOrigin = $this->urls->origin($startUrl);

        $site = $this->discoverSiteMetadata($crawlOrigin);
        $sitemapSeedUrls = $site['seedUrls'];
        unset($site['seedUrls']);

        $queue = [$startUrl];
        $queued = [$this->urlKey($startUrl) => true];
        $visited = [];
        $seenFinal = [];
        $redirectMap = [];
        $redirectedRequests = [];
        $pages = [];

        foreach ($sitemapSeedUrls as $seedUrl) {
            $key = $this->urlKey($seedUrl);
            if (! isset($queued[$key])) {
                $queued[$key] = true;
                $queue[] = $seedUrl;
            }
        }

        while ($queue !== [] && count($pages) < $maxPages) {
            $batch = [];

            while (
                $queue !== []
                && count($batch) < self::CONCURRENCY
                && count($pages) + count($batch) < $maxPages
            ) {
                $candidate = array_shift($queue);
                $candidateKey = $this->urlKey($candidate);

                if (isset($visited[$candidateKey])) {
                    continue;
                }

                $visited[$candidateKey] = true;
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
                    [$response, $finalUrl, $redirectChain] = $this->followRedirects($requestedUrl, $response);
                } catch (Throwable $exception) {
                    $pages[] = $this->failedPage($requestedUrl, $exception->getMessage());
                    continue;
                }

                if ($redirectChain !== []) {
                    $redirectedRequests[$this->urlKey($requestedUrl)] = [
                        'requestedUrl' => $requestedUrl,
                        'finalUrl' => $finalUrl,
                        'chain' => $redirectChain,
                    ];

                    foreach ($redirectChain as $hop) {
                        $redirectMap[$this->urlKey($hop['from'])] = [
                            'finalUrl' => $finalUrl,
                            'chain' => $redirectChain,
                        ];
                    }
                }

                $finalKey = $this->urlKey($finalUrl);
                if (isset($seenFinal[$finalKey])) {
                    continue;
                }

                $contentType = strtolower($response->header('Content-Type'));

                if (
                    ! str_contains($contentType, 'text/html')
                    && ! str_contains($contentType, 'application/xhtml+xml')
                ) {
                    $seenFinal[$finalKey] = true;
                    continue;
                }

                $body = $response->body();

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    $page = $this->failedPage($finalUrl, 'HTML response exceeds the 2.5 MB audit limit.');
                    $page['status'] = $response->status();
                    $page['contentType'] = $contentType;
                    $pages[] = $page;
                    $seenFinal[$finalKey] = true;
                    continue;
                }

                $page = $this->html->parse(
                    $finalUrl,
                    $response->status(),
                    $contentType,
                    $body,
                );

                $page['requestedUrl'] = $requestedUrl;
                $page['redirectChain'] = $redirectChain;

                if (count($redirectChain) > 1) {
                    $this->issue(
                        $page,
                        'redirect_chain',
                        'warning',
                        'Entry URL follows '.count($redirectChain).' redirects before reaching '.$finalUrl.'.',
                    );
                } elseif ($redirectChain !== []) {
                    $temporary = in_array($redirectChain[0]['status'], [302, 303, 307], true);
                    if ($temporary) {
                        $this->issue(
                            $page,
                            'temporary_redirect',
                            'info',
                            'Entry URL uses HTTP '.$redirectChain[0]['status'].' before reaching '.$finalUrl.'.',
                        );
                    }
                }

                $xRobotsTag = trim($response->header('X-Robots-Tag'));
                $page['xRobotsTag'] = $xRobotsTag !== '' ? $xRobotsTag : null;

                if ($this->directiveContains($page['xRobotsTag'], 'noindex')) {
                    $this->issue(
                        $page,
                        'x_robots_noindex',
                        'info',
                        'X-Robots-Tag contains noindex; this page is intended not to appear in search results.',
                    );
                }

                if ($response->status() >= 400) {
                    $this->issue($page, 'http_error', 'error', 'HTTP '.$response->status().'.');
                } elseif ($response->status() >= 300) {
                    $this->issue($page, 'http_redirect', 'warning', 'HTTP '.$response->status().'.');
                }

                if ($pages === []) {
                    $crawlOrigin = $this->urls->origin($finalUrl);
                }

                $pages[] = $page;
                $seenFinal[$finalKey] = true;

                $discovered = array_merge(
                    $page['links'],
                    array_column($page['hreflangs'], 'href'),
                );

                foreach ($discovered as $link) {
                    if (! $this->urls->sameOrigin($crawlOrigin, $link)) {
                        continue;
                    }

                    $key = $this->urlKey($link);
                    if (isset($queued[$key]) || isset($visited[$key])) {
                        continue;
                    }

                    $queued[$key] = true;
                    array_unshift($queue, $link);
                }
            }
        }

        $crossPage = $this->addCrossPageChecks(
            $pages,
            $crawlOrigin,
            $redirectMap,
            $sitemapSeedUrls,
        );

        $site['issues'] = array_merge($site['issues'], $crossPage['siteIssues']);

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $languages = [];
        $hreflangCodes = [];
        $noindexPages = 0;

        foreach ($site['issues'] as $issue) {
            $counts[$issue['severity']]++;
        }

        foreach ($pages as $page) {
            if (! empty($page['lang'])) {
                $languages[strtolower($page['lang'])] = true;
            }

            foreach ($page['hreflangs'] as $entry) {
                $hreflangCodes[$entry['lang']] = true;
            }

            if ($this->pageIsNoindex($page)) {
                $noindexPages++;
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
            'site' => [
                'robotsTxt' => $site['robotsTxt'],
                'sitemaps' => $site['sitemaps'],
                'sitemapUrlsDiscovered' => $site['sitemapUrlsDiscovered'],
                'crawlCoverage' => $crossPage['crawlCoverage'],
            ],
            'siteIssues' => $site['issues'],
            'pages' => $pages,
            'summary' => [
                'pages' => count($pages),
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'info' => $counts['info'],
                'languages' => $languageList,
                'hreflangCodes' => $hreflangList,
                'noindexPages' => $noindexPages,
                'sitemapUrls' => $site['sitemapUrlsDiscovered'],
                'siteIssues' => count($site['issues']),
                'redirects' => count($redirectedRequests),
                'redirectChains' => count(array_filter(
                    $redirectedRequests,
                    fn (array $entry) => count($entry['chain']) > 1,
                )),
                'orphanCandidates' => $crossPage['orphanCandidates'],
                'duplicateContentGroups' => $crossPage['duplicateContentGroups'],
                'internalLinksToRedirects' => $crossPage['internalLinksToRedirects'],
                'mixedSchemeLinks' => $crossPage['mixedSchemeLinks'],
            ],
        ];
    }

    private function discoverSiteMetadata(string $origin): array
    {
        $issues = [];
        $robotsUrl = $origin.'/robots.txt';
        $robotsStatus = 0;
        $sitemapCandidates = [];

        try {
            [$response, $finalRobotsUrl] = $this->fetchResource($robotsUrl, 'text/plain,*/*;q=0.2');
            $robotsStatus = $response->status();

            if ($robotsStatus >= 200 && $robotsStatus < 300) {
                foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
                    if (! preg_match('/^\s*sitemap\s*:\s*(\S+)\s*$/i', $line, $match)) {
                        continue;
                    }

                    $url = $this->urls->resolve($finalRobotsUrl, $match[1]);
                    if ($url !== null && $this->urls->sameOrigin($origin, $url)) {
                        $sitemapCandidates[$this->urlKey($url)] = ['url' => $url, 'declared' => true];
                    }
                }
            } elseif ($robotsStatus >= 500) {
                $issues[] = [
                    'code' => 'robots_unavailable',
                    'severity' => 'warning',
                    'message' => 'robots.txt returned HTTP '.$robotsStatus.'.',
                ];
            }
        } catch (Throwable $exception) {
            $issues[] = [
                'code' => 'robots_fetch_failed',
                'severity' => 'info',
                'message' => 'robots.txt could not be checked: '.$exception->getMessage(),
            ];
        }

        if ($sitemapCandidates === []) {
            $fallback = $origin.'/sitemap.xml';
            $sitemapCandidates[$this->urlKey($fallback)] = ['url' => $fallback, 'declared' => false];
        }

        $queue = array_values($sitemapCandidates);
        $processed = [];
        $validSitemaps = [];
        $pageUrls = [];

        while ($queue !== [] && count($processed) < self::MAX_SITEMAPS && count($pageUrls) < self::MAX_SITEMAP_URLS) {
            $candidate = array_shift($queue);
            $sitemapUrl = $candidate['url'];
            $key = $this->urlKey($sitemapUrl);

            if (isset($processed[$key])) {
                continue;
            }
            $processed[$key] = true;

            try {
                [$response, $finalUrl] = $this->fetchResource(
                    $sitemapUrl,
                    'application/xml,text/xml;q=0.9,text/plain;q=0.5,*/*;q=0.1',
                );
            } catch (Throwable $exception) {
                if ($candidate['declared']) {
                    $issues[] = [
                        'code' => 'sitemap_fetch_failed',
                        'severity' => 'warning',
                        'message' => 'Declared sitemap could not be fetched: '.$sitemapUrl.'.',
                    ];
                }
                continue;
            }

            if ($response->status() < 200 || $response->status() >= 300) {
                if ($candidate['declared']) {
                    $issues[] = [
                        'code' => 'sitemap_http_error',
                        'severity' => 'warning',
                        'message' => 'Declared sitemap returned HTTP '.$response->status().': '.$sitemapUrl.'.',
                    ];
                }
                continue;
            }

            $body = $response->body();
            $path = strtolower((string) parse_url($finalUrl, PHP_URL_PATH));
            if (str_ends_with($path, '.gz') && function_exists('gzdecode')) {
                $decoded = @gzdecode($body);
                if ($decoded !== false) {
                    $body = $decoded;
                }
            }

            if (strlen($body) > self::MAX_SITEMAP_BYTES) {
                $issues[] = [
                    'code' => 'sitemap_too_large',
                    'severity' => 'warning',
                    'message' => 'Sitemap exceeds the 5 MB audit parsing limit: '.$sitemapUrl.'.',
                ];
                continue;
            }

            $parsed = $this->parseSitemap($finalUrl, $body);
            if ($parsed === null) {
                if ($candidate['declared']) {
                    $issues[] = [
                        'code' => 'sitemap_invalid',
                        'severity' => 'warning',
                        'message' => 'Declared sitemap could not be parsed as a sitemap XML document: '.$sitemapUrl.'.',
                    ];
                }
                continue;
            }

            $validSitemaps[$this->urlKey($finalUrl)] = $finalUrl;

            if ($parsed['type'] === 'index') {
                foreach ($parsed['urls'] as $childUrl) {
                    if (! $this->urls->sameOrigin($origin, $childUrl)) {
                        continue;
                    }

                    $childKey = $this->urlKey($childUrl);
                    if (! isset($processed[$childKey])) {
                        $queue[] = ['url' => $childUrl, 'declared' => true];
                    }
                }
                continue;
            }

            foreach ($parsed['urls'] as $pageUrl) {
                if (! $this->urls->sameOrigin($origin, $pageUrl)) {
                    continue;
                }

                $pageUrls[$this->urlKey($pageUrl)] = $pageUrl;
                if (count($pageUrls) >= self::MAX_SITEMAP_URLS) {
                    break;
                }
            }
        }

        if ($validSitemaps === []) {
            $issues[] = [
                'code' => 'sitemap_not_detected',
                'severity' => 'info',
                'message' => 'No readable XML sitemap was detected in robots.txt or at /sitemap.xml.',
            ];
        }

        return [
            'robotsTxt' => [
                'url' => $robotsUrl,
                'status' => $robotsStatus,
            ],
            'sitemaps' => array_values($validSitemaps),
            'sitemapUrlsDiscovered' => count($pageUrls),
            'seedUrls' => array_values($pageUrls),
            'issues' => $issues,
        ];
    }

    private function parseSitemap(string $sitemapUrl, string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $dom->documentElement) {
            return null;
        }

        $root = strtolower($dom->documentElement->localName);
        $xpath = new DOMXPath($dom);

        if ($root === 'sitemapindex') {
            $nodes = $xpath->query('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]');
            return ['type' => 'index', 'urls' => $this->sitemapLocations($sitemapUrl, $nodes)];
        }

        if ($root === 'urlset') {
            $nodes = $xpath->query('/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]');
            return ['type' => 'urlset', 'urls' => $this->sitemapLocations($sitemapUrl, $nodes)];
        }

        return null;
    }

    private function sitemapLocations(string $baseUrl, DOMNodeList|false $nodes): array
    {
        if (! $nodes instanceof DOMNodeList) {
            return [];
        }

        $urls = [];
        foreach ($nodes as $node) {
            $resolved = $this->urls->resolve($baseUrl, trim($node->textContent ?? ''));
            if ($resolved !== null) {
                $urls[$this->urlKey($resolved)] = $resolved;
            }
        }

        return array_values($urls);
    }

    private function fetchResource(string $url, string $accept): array
    {
        $url = $this->urls->assertPublic($url);
        $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => $accept,
            ])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)
            ->timeout(self::RESOURCE_TIMEOUT)
            ->get($url);

        return $this->followRedirects($url, $response, $accept, self::RESOURCE_TIMEOUT);
    }

    private function followRedirects(
        string $url,
        Response $response,
        string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
        int $timeout = self::TIMEOUT,
    ): array {
        $currentUrl = $url;
        $chain = [];

        for ($hop = 0; $hop < 5; $hop++) {
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $currentUrl, $chain];
            }

            $location = trim($response->header('Location'));

            if ($location === '') {
                return [$response, $currentUrl, $chain];
            }

            $resolved = $this->urls->resolve($currentUrl, $location);

            if ($resolved === null) {
                throw new \RuntimeException('Redirect target is invalid.');
            }

            $targetUrl = $this->urls->assertPublic($resolved);
            $chain[] = [
                'from' => $currentUrl,
                'status' => $response->status(),
                'to' => $targetUrl,
            ];
            $currentUrl = $targetUrl;

            $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => $accept,
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout($timeout)
                ->get($currentUrl);
        }

        throw new \RuntimeException('Too many redirects.');
    }

    private function addCrossPageChecks(array &$pages, string $crawlOrigin): void
    {
        $byUrl = [];
        $titles = [];
        $descriptions = [];

        foreach ($pages as $index => $page) {
            try {
                $byUrl[$this->urlKey($page['url'])] = $index;
            } catch (Throwable) {
                // Keep the rest of the audit useful even if one URL is malformed.
            }

            if ($page['title'] !== '') {
                $titles[mb_strtolower(trim($page['title']))][] = $index;
            }

            if ($page['description'] !== '') {
                $descriptions[mb_strtolower(trim($page['description']))][] = $index;
            }
        }

        foreach ($titles as $indexes) {
            if (count($indexes) <= 1) {
                continue;
            }

            $multilingual = $this->indexesSpanLanguages($pages, $indexes);
            foreach ($indexes as $index) {
                $this->issue(
                    $pages[$index],
                    $multilingual ? 'title_duplicate_multilingual' : 'title_duplicate',
                    $multilingual ? 'info' : 'warning',
                    $multilingual
                        ? 'Same title appears across '.count($indexes).' audited language variants; review only if this is unintended.'
                        : 'Duplicate title across '.count($indexes).' audited pages in the same language.',
                );
            }
        }

        foreach ($descriptions as $indexes) {
            if (count($indexes) <= 1) {
                continue;
            }

            $multilingual = $this->indexesSpanLanguages($pages, $indexes);
            foreach ($indexes as $index) {
                $this->issue(
                    $pages[$index],
                    $multilingual ? 'description_duplicate_multilingual' : 'description_duplicate',
                    'warning',
                    $multilingual
                        ? 'Identical meta description appears across '.count($indexes).' language variants; check whether it should be translated.'
                        : 'Duplicate meta description across '.count($indexes).' audited pages.',
                );
            }
        }

        foreach ($pages as $index => $page) {
            try {
                $sourceUrl = $this->urlKey($page['url']);
            } catch (Throwable) {
                continue;
            }

            if ($page['canonical'] !== null) {
                try {
                    $canonicalUrl = $this->urlKey($page['canonical']);
                    if (isset($byUrl[$canonicalUrl])) {
                        $target = $pages[$byUrl[$canonicalUrl]];

                        if ($target['status'] >= 400) {
                            $this->issue(
                                $pages[$index],
                                'canonical_target_error',
                                'error',
                                'Canonical target returns HTTP '.$target['status'].': '.$target['url'].'.',
                            );
                        } elseif ($this->pageIsNoindex($target)) {
                            $this->issue(
                                $pages[$index],
                                'canonical_target_noindex',
                                'warning',
                                'Canonical target is marked noindex: '.$target['url'].'.',
                            );
                        }
                    }
                } catch (Throwable) {
                    // Canonical validity is already checked at page level.
                }
            }

            $broken = [];
            foreach ($page['links'] as $link) {
                if (! $this->urls->sameOrigin($crawlOrigin, $link)) {
                    continue;
                }

                try {
                    $targetKey = $this->urlKey($link);
                } catch (Throwable) {
                    continue;
                }

                if (isset($byUrl[$targetKey]) && $pages[$byUrl[$targetKey]]['status'] >= 400) {
                    $broken[] = $pages[$byUrl[$targetKey]]['url'];
                }
            }

            if ($broken !== []) {
                $broken = array_values(array_unique($broken));
                $this->issue(
                    $pages[$index],
                    'broken_internal_link',
                    'error',
                    count($broken).' crawled internal link(s) point to HTTP errors; first: '.$broken[0].'.',
                );
            }

            foreach ($page['hreflangs'] as $alternate) {
                try {
                    $targetUrl = $this->urlKey($alternate['href']);
                } catch (Throwable) {
                    continue;
                }

                if ($targetUrl === $sourceUrl || ! isset($byUrl[$targetUrl])) {
                    continue;
                }

                $target = $pages[$byUrl[$targetUrl]];

                if ($target['status'] >= 400) {
                    $this->issue(
                        $pages[$index],
                        'hreflang_target_error',
                        'error',
                        $alternate['lang'].' hreflang target returns HTTP '.$target['status'].': '.$target['url'].'.',
                    );
                    continue;
                }

                if ($this->pageIsNoindex($target)) {
                    $this->issue(
                        $pages[$index],
                        'hreflang_target_noindex',
                        'warning',
                        $alternate['lang'].' hreflang target is marked noindex: '.$target['url'].'.',
                    );
                }

                if ($target['canonical'] !== null) {
                    try {
                        if ($this->urlKey($target['canonical']) !== $targetUrl) {
                            $this->issue(
                                $pages[$index],
                                'hreflang_target_canonical_other',
                                'warning',
                                $alternate['lang'].' hreflang target canonicalizes elsewhere: '.$target['url'].'.',
                            );
                        }
                    } catch (Throwable) {
                        // Canonical validity is already checked at page level.
                    }
                }

                if (
                    $alternate['lang'] !== 'x-default'
                    && ! empty($target['lang'])
                    && $this->languageBase($alternate['lang']) !== $this->languageBase($target['lang'])
                ) {
                    $this->issue(
                        $pages[$index],
                        'hreflang_target_lang_mismatch',
                        'info',
                        $alternate['lang'].' hreflang points to a page with html lang="'.$target['lang'].'": '.$target['url'].'.',
                    );
                }

                $reciprocal = false;
                foreach ($target['hreflangs'] as $entry) {
                    try {
                        if ($this->urlKey($entry['href']) === $sourceUrl) {
                            $reciprocal = true;
                            break;
                        }
                    } catch (Throwable) {
                        // Ignore one malformed alternate and continue checking the set.
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

    private function indexesSpanLanguages(array $pages, array $indexes): bool
    {
        $languages = [];

        foreach ($indexes as $index) {
            if (empty($pages[$index]['lang'])) {
                continue;
            }

            $languages[$this->languageBase($pages[$index]['lang'])] = true;
        }

        return count($languages) > 1;
    }

    private function languageBase(string $code): string
    {
        return strtolower(explode('-', $code, 2)[0]);
    }

    private function pageIsNoindex(array $page): bool
    {
        return $this->directiveContains($page['robots'] ?? null, 'noindex')
            || $this->directiveContains($page['xRobotsTag'] ?? null, 'noindex');
    }

    private function directiveContains(?string $value, string $needle): bool
    {
        if ($value === null) {
            return false;
        }

        $tokens = preg_split('/[\s,;:]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(strtolower($needle), $tokens, true);
    }

    private function urlKey(string $url): string
    {
        return $this->urls->normalize($url);
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
            'xRobotsTag' => null,
            'hreflangs' => [],
            'headings' => [
                'h1Count' => 0,
                'h1' => [],
                'h2Count' => 0,
            ],
            'images' => [
                'total' => 0,
                'missingAlt' => 0,
                'emptyAlt' => 0,
            ],
            'wordCount' => 0,
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
