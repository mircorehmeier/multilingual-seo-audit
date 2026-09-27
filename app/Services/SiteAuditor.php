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
    private const USER_AGENT_PREFIX = 'MultilingualSEOAudit/';
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
        private readonly RobotsPolicy $robotsPolicy,
        private readonly SocialImageInspector $socialImages,
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
        $sitemapHreflangs = $site['sitemapHreflangs'];
        $robotsGroups = $site['robotsGroups'];
        unset($site['seedUrls'], $site['sitemapHreflangs'], $site['robotsGroups']);

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
                            'User-Agent' => $this->userAgent(),
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

                $externalHreflangs = array_merge(
                    $this->httpHreflangs($finalUrl, $response->header('Link')),
                    $sitemapHreflangs[$finalKey] ?? [],
                );

                $page = $this->html->parse(
                    $finalUrl,
                    $response->status(),
                    $contentType,
                    $body,
                    $externalHreflangs,
                );

                $page['requestedUrl'] = $requestedUrl;
                $page['redirectChain'] = $redirectChain;
                $page['robotsTxt'] = $this->robotsPolicy->decision($finalUrl, $robotsGroups, 'Googlebot');

                if (! $page['robotsTxt']['allowed']) {
                    $this->issue(
                        $page,
                        'robots_txt_blocked',
                        'warning',
                        'robots.txt blocks Googlebot from this URL via '.
                        ($page['robotsTxt']['matchedDirective'] ?? 'disallow').': '.
                        ($page['robotsTxt']['matchedRule'] ?? '').'.',
                    );
                }

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

        $targetChecks = $this->checkUncrawledInternalLinks($pages, $crawlOrigin, $redirectMap);
        foreach ($targetChecks['redirectMap'] as $key => $entry) {
            $redirectMap[$key] = $entry;
        }
        foreach ($targetChecks['redirects'] as $key => $entry) {
            $redirectedRequests[$key] = $entry;
        }

        $socialImagesChecked = $this->attachSocialImageDiagnostics($pages);

        $crossPage = $this->addCrossPageChecks(
            $pages,
            $crawlOrigin,
            $redirectMap,
            $sitemapSeedUrls,
            $targetChecks['checks'],
        );

        $site['issues'] = array_merge($site['issues'], $targetChecks['siteIssues'], $crossPage['siteIssues']);

        $indexablePages = 0;
        foreach ($pages as &$page) {
            $page['indexability'] = $this->indexability($page);
            if ($page['indexability']['status'] === 'indexable') {
                $indexablePages++;
            }
        }
        unset($page);

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $languages = [];
        $hreflangCodes = [];
        $noindexPages = 0;
        $robotsBlockedPages = 0;

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

            if (($page['robotsTxt']['allowed'] ?? true) === false) {
                $robotsBlockedPages++;
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
            'version' => config('audit.version'),
            'startUrl' => $startUrl,
            'origin' => $crawlOrigin,
            'auditedAt' => now()->toIso8601String(),
            'maxPages' => $maxPages,
            'site' => [
                'robotsTxt' => $site['robotsTxt'],
                'sitemaps' => $site['sitemaps'],
                'sitemapUrlsDiscovered' => $site['sitemapUrlsDiscovered'],
                'crawlCoverage' => $crossPage['crawlCoverage'],
                'redirects' => array_values($redirectedRequests),
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
                'indexablePages' => $indexablePages,
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
                'robotsBlockedPages' => $robotsBlockedPages,
                'linkTargetsChecked' => count($targetChecks['checks']),
                'socialImagesChecked' => $socialImagesChecked,
                'maxCrawlDepth' => $crossPage['maxCrawlDepth'],
                'contextualLinks' => $crossPage['contextualLinks'],
            ],
        ];
    }

    private function discoverSiteMetadata(string $origin): array
    {
        $issues = [];
        $robotsUrl = $origin.'/robots.txt';
        $robotsStatus = 0;
        $robotsGroups = [];
        $sitemapCandidates = [];

        try {
            [$response, $finalRobotsUrl] = $this->fetchResource($robotsUrl, 'text/plain,*/*;q=0.2');
            $robotsStatus = $response->status();

            if ($robotsStatus >= 200 && $robotsStatus < 300) {
                $robotsBody = $response->body();
                $robotsGroups = $this->robotsPolicy->parse($robotsBody);

                foreach (preg_split('/\R/', $robotsBody) ?: [] as $line) {
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
        $sitemapHreflangs = [];

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

                $pageKey = $this->urlKey($pageUrl);
                $pageUrls[$pageKey] = $pageUrl;

                foreach ($parsed['hreflangs'][$pageKey] ?? [] as $alternate) {
                    $alternateKey = strtolower($alternate['lang']).'|'.$this->urlKey($alternate['href']);
                    $sitemapHreflangs[$pageKey][$alternateKey] = $alternate;
                }

                if (count($pageUrls) >= self::MAX_SITEMAP_URLS) {
                    break;
                }
            }
        }

        foreach ($sitemapHreflangs as $key => $alternates) {
            $sitemapHreflangs[$key] = array_values($alternates);
        }

        if ($validSitemaps === []) {
            $issues[] = [
                'code' => 'sitemap_not_detected',
                'severity' => 'info',
                'message' => 'No readable XML sitemap was detected in robots.txt or at /sitemap.xml.',
            ];
        }

        $robotsRuleCount = 0;
        foreach ($robotsGroups as $group) {
            $robotsRuleCount += count($group['rules'] ?? []);
        }

        return [
            'robotsTxt' => [
                'url' => $robotsUrl,
                'status' => $robotsStatus,
                'groups' => count($robotsGroups),
                'rules' => $robotsRuleCount,
            ],
            'robotsGroups' => $robotsGroups,
            'sitemaps' => array_values($validSitemaps),
            'sitemapUrlsDiscovered' => count($pageUrls),
            'sitemapHreflangs' => $sitemapHreflangs,
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
            return [
                'type' => 'index',
                'urls' => $this->sitemapLocations($sitemapUrl, $nodes),
                'hreflangs' => [],
            ];
        }

        if ($root !== 'urlset') {
            return null;
        }

        $urls = [];
        $hreflangs = [];
        $urlNodes = $xpath->query('/*[local-name()="urlset"]/*[local-name()="url"]');

        if ($urlNodes instanceof DOMNodeList) {
            foreach ($urlNodes as $urlNode) {
                $locNodes = $xpath->query('./*[local-name()="loc"][1]', $urlNode);
                $locNode = $locNodes instanceof DOMNodeList ? $locNodes->item(0) : null;
                $pageUrl = $locNode !== null
                    ? $this->urls->resolve($sitemapUrl, trim($locNode->textContent ?? ''))
                    : null;

                if ($pageUrl === null) {
                    continue;
                }

                $pageKey = $this->urlKey($pageUrl);
                $urls[$pageKey] = $pageUrl;

                $alternateNodes = $xpath->query(
                    './*[local-name()="link" and translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]',
                    $urlNode,
                );

                if (! $alternateNodes instanceof DOMNodeList) {
                    continue;
                }

                foreach ($alternateNodes as $alternateNode) {
                    if (! $alternateNode instanceof \DOMElement) {
                        continue;
                    }

                    $lang = strtolower(trim($alternateNode->getAttribute('hreflang')));
                    $href = $this->urls->resolve($sitemapUrl, $alternateNode->getAttribute('href'));

                    if ($lang === '' || $href === null) {
                        continue;
                    }

                    $hreflangs[$pageKey][] = [
                        'lang' => $lang,
                        'href' => $href,
                        'source' => 'sitemap',
                    ];
                }
            }
        }

        return [
            'type' => 'urlset',
            'urls' => array_values($urls),
            'hreflangs' => $hreflangs,
        ];
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
                'User-Agent' => $this->userAgent(),
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
                    'User-Agent' => $this->userAgent(),
                    'Accept' => $accept,
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout($timeout)
                ->get($currentUrl);
        }

        throw new \RuntimeException('Too many redirects.');
    }

    private function addCrossPageChecks(
        array &$pages,
        string $crawlOrigin,
        array $redirectMap,
        array $sitemapUrls,
    ): array {
        $byUrl = [];
        $titles = [];
        $descriptions = [];
        $contentHashes = [];
        $incoming = [];
        $siteIssues = [];
        $internalLinksToRedirects = 0;
        $mixedSchemeLinks = 0;
        $duplicateContentGroups = 0;
        $orphanCandidates = 0;

        foreach ($pages as $index => &$page) {
            $page['internalLinks'] = [
                'incoming' => 0,
                'outgoing' => 0,
                'redirecting' => 0,
            ];
            $page['inSitemap'] = false;

            try {
                $key = $this->urlKey($page['url']);
                $byUrl[$key] = $index;
                $incoming[$key] = 0;
            } catch (Throwable) {
                continue;
            }

            if ($page['title'] !== '') {
                $titles[mb_strtolower(trim($page['title']))][] = $index;
            }

            if ($page['description'] !== '') {
                $descriptions[mb_strtolower(trim($page['description']))][] = $index;
            }

            if (
                ! empty($page['contentHash'])
                && $page['status'] >= 200
                && $page['status'] < 300
                && ! $this->pageIsNoindex($page)
            ) {
                $contentHashes[$page['contentHash']][] = $index;
            }
        }
        unset($page);

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

        foreach ($contentHashes as $indexes) {
            if (count($indexes) <= 1) {
                continue;
            }

            $duplicateContentGroups++;
            $multilingual = $this->indexesSpanLanguages($pages, $indexes);
            $canonicalized = $this->indexesShareCanonicalTarget($pages, $indexes);

            foreach ($indexes as $index) {
                $this->issue(
                    $pages[$index],
                    $multilingual ? 'content_duplicate_multilingual' : 'content_duplicate',
                    $canonicalized ? 'info' : 'warning',
                    $multilingual
                        ? 'Exact main-content duplicate detected across '.count($indexes).' declared language variants; this can indicate untranslated body content.'
                        : 'Exact main-content duplicate detected across '.count($indexes).' audited pages'.($canonicalized ? ' with a shared canonical target.' : '.'),
                );
            }
        }

        foreach ($pages as $index => &$page) {
            $seenOutgoing = [];
            $redirectLinks = [];
            $mixedScheme = [];
            $broken = [];

            foreach ($page['links'] as $link) {
                if ($this->urls->sameHost($crawlOrigin, $link) && ! $this->urls->sameOrigin($crawlOrigin, $link)) {
                    $mixedScheme[$link] = true;
                }

                if (! $this->urls->sameOrigin($crawlOrigin, $link)) {
                    continue;
                }

                try {
                    $targetKey = $this->urlKey($link);
                } catch (Throwable) {
                    continue;
                }

                $effectiveKey = $targetKey;

                if (isset($redirectMap[$targetKey])) {
                    $redirectLinks[$targetKey] = $redirectMap[$targetKey];

                    try {
                        $effectiveKey = $this->urlKey($redirectMap[$targetKey]['finalUrl']);
                    } catch (Throwable) {
                        $effectiveKey = $targetKey;
                    }
                }

                if (! isset($seenOutgoing[$effectiveKey])) {
                    $seenOutgoing[$effectiveKey] = true;
                    $page['internalLinks']['outgoing']++;

                    if (isset($byUrl[$effectiveKey])) {
                        $incoming[$effectiveKey] = ($incoming[$effectiveKey] ?? 0) + 1;
                    }
                }

                if (isset($byUrl[$effectiveKey]) && $pages[$byUrl[$effectiveKey]]['status'] >= 400) {
                    $broken[$pages[$byUrl[$effectiveKey]]['url']] = true;
                }
            }

            if ($redirectLinks !== []) {
                $page['internalLinks']['redirecting'] = count($redirectLinks);
                $internalLinksToRedirects += count($redirectLinks);
                $first = array_key_first($redirectLinks);

                $this->issue(
                    $page,
                    'internal_link_to_redirect',
                    'warning',
                    count($redirectLinks).' internal link(s) point to redirecting URLs; first: '.$first.' → '.$redirectLinks[$first]['finalUrl'].'. Link directly to the final URL.',
                );

                $chainLinks = array_filter(
                    $redirectLinks,
                    fn (array $entry) => count($entry['chain']) > 1,
                );

                if ($chainLinks !== []) {
                    $firstChainUrl = array_key_first($chainLinks);
                    $this->issue(
                        $page,
                        'internal_link_redirect_chain',
                        'warning',
                        count($chainLinks).' internal link(s) enter a redirect chain; first: '.$firstChainUrl.' follows '.count($chainLinks[$firstChainUrl]['chain']).' redirects.',
                    );
                }
            }

            if ($mixedScheme !== []) {
                $mixedSchemeLinks += count($mixedScheme);
                $first = array_key_first($mixedScheme);

                $this->issue(
                    $page,
                    'mixed_scheme_internal_link',
                    'warning',
                    count($mixedScheme).' same-host link(s) use a different scheme or port; first: '.$first.'.',
                );
            }

            if ($broken !== []) {
                $first = array_key_first($broken);
                $this->issue(
                    $page,
                    'broken_internal_link',
                    'error',
                    count($broken).' crawled internal link(s) point to HTTP errors; first: '.$first.'.',
                );
            }
        }
        unset($page);

        foreach ($byUrl as $key => $index) {
            $pages[$index]['internalLinks']['incoming'] = $incoming[$key] ?? 0;
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

                        if ($target['canonical'] !== null) {
                            try {
                                $targetCanonical = $this->urlKey($target['canonical']);
                                if ($targetCanonical !== $canonicalUrl) {
                                    $this->issue(
                                        $pages[$index],
                                        'canonical_chain',
                                        'warning',
                                        'Canonical target itself canonicalizes elsewhere: '.$target['canonical'].'.',
                                    );
                                }
                            } catch (Throwable) {
                                // Target canonical validity is already checked on that page.
                            }
                        }
                    }
                } catch (Throwable) {
                    // Canonical validity is already checked at page level.
                }
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

        $sitemapKeys = [];
        $sitemapAudited = 0;
        $sitemapRedirects = 0;
        $sitemapTargets = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $key = $this->urlKey($sitemapUrl);
            } catch (Throwable) {
                continue;
            }

            if (isset($sitemapKeys[$key])) {
                continue;
            }

            $sitemapKeys[$key] = true;
            $effectiveKey = $key;

            if (isset($redirectMap[$key])) {
                $sitemapRedirects++;
                try {
                    $effectiveKey = $this->urlKey($redirectMap[$key]['finalUrl']);
                } catch (Throwable) {
                    $effectiveKey = $key;
                }
            }

            if (! isset($byUrl[$effectiveKey])) {
                continue;
            }

            $sitemapAudited++;
            $sitemapTargets[$effectiveKey] = true;
            $pageIndex = $byUrl[$effectiveKey];
            $pages[$pageIndex]['inSitemap'] = true;

            if (isset($redirectMap[$key])) {
                $this->issue(
                    $pages[$pageIndex],
                    'sitemap_url_redirect',
                    'warning',
                    'Sitemap URL redirects: '.$sitemapUrl.' → '.$redirectMap[$key]['finalUrl'].'. Sitemaps should list final canonical URLs.',
                );
            }

            if ($this->pageIsNoindex($pages[$pageIndex])) {
                $this->issue(
                    $pages[$pageIndex],
                    'sitemap_noindex',
                    'warning',
                    'Sitemap-listed page is marked noindex.',
                );
            }

            if ($pages[$pageIndex]['canonical'] !== null) {
                try {
                    if ($this->urlKey($pages[$pageIndex]['canonical']) !== $effectiveKey) {
                        $this->issue(
                            $pages[$pageIndex],
                            'sitemap_noncanonical',
                            'warning',
                            'Sitemap-listed URL canonicalizes to '.$pages[$pageIndex]['canonical'].'.',
                        );
                    }
                } catch (Throwable) {
                    // Canonical validity is already reported on the page.
                }
            }
        }

        $sitemapTotal = count($sitemapKeys);
        $coverageComplete = $sitemapTotal > 0 && $sitemapAudited === $sitemapTotal;
        $coveragePercent = $sitemapTotal > 0
            ? round(($sitemapAudited / $sitemapTotal) * 100, 1)
            : null;

        if ($sitemapTotal > 0 && ! $coverageComplete) {
            $siteIssues[] = [
                'code' => 'crawl_coverage_partial',
                'severity' => 'info',
                'message' => 'Audited '.$sitemapAudited.' of '.$sitemapTotal.' sitemap URLs ('.$coveragePercent.'%). Orphan-page conclusions are disabled until coverage is complete.',
            ];
        }

        if ($sitemapRedirects > 0) {
            $siteIssues[] = [
                'code' => 'sitemap_redirects',
                'severity' => 'warning',
                'message' => $sitemapRedirects.' sitemap URL(s) redirect before reaching their final page.',
            ];
        }

        if ($coverageComplete && count($pages) > 1) {
            foreach (array_keys($sitemapTargets) as $key) {
                if (! isset($byUrl[$key])) {
                    continue;
                }

                $pageIndex = $byUrl[$key];
                if ($pageIndex === 0 || $this->pageIsNoindex($pages[$pageIndex])) {
                    continue;
                }

                if (($pages[$pageIndex]['internalLinks']['incoming'] ?? 0) === 0) {
                    $orphanCandidates++;
                    $this->issue(
                        $pages[$pageIndex],
                        'orphan_candidate',
                        'warning',
                        'Sitemap-listed page has no internal anchor links from other audited pages.',
                    );
                }
            }
        }

        return [
            'siteIssues' => $siteIssues,
            'crawlCoverage' => [
                'sitemapUrls' => $sitemapTotal,
                'audited' => $sitemapAudited,
                'percent' => $coveragePercent,
                'complete' => $coverageComplete,
            ],
            'orphanCandidates' => $orphanCandidates,
            'duplicateContentGroups' => $duplicateContentGroups,
            'internalLinksToRedirects' => $internalLinksToRedirects,
            'mixedSchemeLinks' => $mixedSchemeLinks,
        ];
    }

    private function indexesShareCanonicalTarget(array $pages, array $indexes): bool
    {
        $target = null;

        foreach ($indexes as $index) {
            if (empty($pages[$index]['canonical'])) {
                return false;
            }

            try {
                $canonical = $this->urlKey($pages[$index]['canonical']);
            } catch (Throwable) {
                return false;
            }

            if ($target === null) {
                $target = $canonical;
                continue;
            }

            if ($canonical !== $target) {
                return false;
            }
        }

        return $target !== null;
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

    private function indexability(array $page): array
    {
        $status = (int) ($page['status'] ?? 0);

        if ($status === 0) {
            return ['status' => 'unknown', 'reason' => 'Page could not be fetched.'];
        }

        if ($status < 200 || $status >= 300) {
            return ['status' => 'not-indexable', 'reason' => 'HTTP '.$status.'.'];
        }

        if ($this->directiveContains($page['robots'] ?? null, 'noindex')) {
            return ['status' => 'not-indexable', 'reason' => 'Meta robots contains noindex.'];
        }

        if ($this->directiveContains($page['xRobotsTag'] ?? null, 'noindex')) {
            return ['status' => 'not-indexable', 'reason' => 'X-Robots-Tag contains noindex.'];
        }

        if (! empty($page['canonical'])) {
            try {
                if ($this->urlKey($page['canonical']) !== $this->urlKey($page['url'])) {
                    return [
                        'status' => 'canonicalized',
                        'reason' => 'Canonical points to '.$page['canonical'].'.',
                    ];
                }
            } catch (Throwable) {
                return ['status' => 'unknown', 'reason' => 'Canonical URL could not be normalized.'];
            }
        }

        return [
            'status' => 'indexable',
            'reason' => 'HTTP 2xx with no noindex directive and a self-referencing or absent canonical.',
        ];
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

    private function userAgent(): string
    {
        return self::USER_AGENT_PREFIX.config('audit.version').' (+https://github.com/mircorehmeier/multilingual-seo-audit)';
    }

    private function urlKey(string $url): string
    {
        return $this->urls->normalize($url);
    }

    private function failedPage(string $url, string $message): array
    {
        return [
            'url' => $url,
            'requestedUrl' => $url,
            'redirectChain' => [],
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
            'detectedLang' => null,
            'languageConfidence' => 0.0,
            'contentHash' => null,
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
