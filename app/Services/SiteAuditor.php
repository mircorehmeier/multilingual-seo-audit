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
    private const MAX_SOCIAL_IMAGES = 75;

    public function __construct(
        private readonly UrlGuard $urls,
        private readonly HtmlAuditor $html,
        private readonly RobotsPolicy $robotsPolicy,
        private readonly SocialImageInspector $socialImages,
        private readonly AuditPatternAnalyzer $patterns,
        private readonly OriginInspector $originInspector,
    ) {
    }

    public function audit(string $startInput, int $requestedMaxPages = 25, string $environment = 'live'): array
    {
        $startInput = trim($startInput);
        $environment = strtolower(trim($environment));
        if (! in_array($environment, ['live', 'staging'], true)) {
            $environment = 'live';
        }

        if (! preg_match('#^https?://#i', $startInput)) {
            $startInput = 'https://'.$startInput;
        }

        $inputUrl = $this->urls->assertPublic($startInput);
        $maxPages = max(1, min(100, $requestedMaxPages));

        $seed = $this->resolveAuditSeed($inputUrl);
        $auditSeedUrl = $seed['seedUrl'];
        $inputOrigin = $this->urls->origin($inputUrl);
        $crawlOrigin = $seed['seedOrigin'];

        $site = $this->discoverSiteMetadata($crawlOrigin);
        $sitemapSeedUrls = $site['seedUrls'];
        $sitemapHreflangs = $site['sitemapHreflangs'];
        $sitemapSources = $site['sitemapSources'];
        $robotsGroups = $site['robotsGroups'];
        unset($site['seedUrls'], $site['sitemapHreflangs'], $site['sitemapSources'], $site['robotsGroups']);

        $queue = [$auditSeedUrl];
        $queued = [$this->urlKey($auditSeedUrl) => true];
        $visited = [];
        $seenFinal = [];
        $redirectMap = [];
        $redirectedRequests = [];
        $pages = [];
        $discovery = [];

        $this->recordDiscovery(
            $discovery,
            $auditSeedUrl,
            'start',
            $auditSeedUrl !== $inputUrl ? $inputUrl : null,
        );

        if ($seed['redirectChain'] !== []) {
            $redirectedRequests[$this->urlKey($inputUrl)] = [
                'requestedUrl' => $inputUrl,
                'finalUrl' => $seed['redirectFinalUrl'],
                'chain' => $seed['redirectChain'],
            ];

            foreach ($seed['redirectChain'] as $hop) {
                $redirectMap[$this->urlKey($hop['from'])] = [
                    'finalUrl' => $seed['redirectFinalUrl'],
                    'chain' => $seed['redirectChain'],
                ];
            }

            $this->recordDiscovery($discovery, $seed['redirectFinalUrl'], 'redirect', $inputUrl);
        }

        if ($seed['source'] === 'start-page-canonical' && $auditSeedUrl !== $seed['redirectFinalUrl']) {
            $this->recordDiscovery($discovery, $auditSeedUrl, 'canonical', $seed['redirectFinalUrl']);
        }

        $enqueue = function (string $url, string $type, ?string $from = null) use (
            &$queue,
            &$queued,
            &$visited,
            &$discovery,
            &$crawlOrigin,
        ): void {
            if (! $this->urls->sameOrigin($crawlOrigin, $url)) {
                return;
            }

            $this->recordDiscovery($discovery, $url, $type, $from);
            $key = $this->urlKey($url);

            if (isset($queued[$key]) || isset($visited[$key])) {
                return;
            }

            $queued[$key] = true;
            array_unshift($queue, $url);
        };

        foreach ($sitemapSeedUrls as $seedUrl) {
            $key = $this->urlKey($seedUrl);
            foreach ($sitemapSources[$key] ?? [] as $sourceSitemap) {
                $this->recordDiscovery($discovery, $seedUrl, 'sitemap', $sourceSitemap);
            }

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
                    $page = $this->failedPage(
                        $requestedUrl,
                        $response instanceof Throwable ? $response->getMessage() : 'Request failed.',
                    );
                    $page['discovery'] = $this->discoveryEntries($discovery, $requestedUrl);
                    $pages[] = $page;
                    continue;
                }

                try {
                    [$response, $finalUrl, $redirectChain] = $this->followRedirects($requestedUrl, $response);
                } catch (Throwable $exception) {
                    $page = $this->failedPage($requestedUrl, $exception->getMessage());
                    $page['discovery'] = $this->discoveryEntries($discovery, $requestedUrl);
                    $pages[] = $page;
                    continue;
                }

                if ($redirectChain !== []) {
                    $this->recordDiscovery($discovery, $finalUrl, 'redirect', $requestedUrl);
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
                    $page['discovery'] = $this->discoveryEntries($discovery, $requestedUrl, $finalUrl);
                    $pages[] = $page;
                    $seenFinal[$finalKey] = true;
                    continue;
                }

                $requestedKey = $this->urlKey($requestedUrl);
                $externalHreflangs = array_merge(
                    $this->httpHreflangs($finalUrl, $response->header('Link')),
                    $sitemapHreflangs[$requestedKey] ?? [],
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
                $page['discovery'] = $this->discoveryEntries($discovery, $requestedUrl, $finalUrl);
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

                foreach ($page['links'] as $link) {
                    try {
                        if ($this->urlKey($link) === $finalKey) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    $enqueue($link, 'internal-link', $finalUrl);
                }

                foreach ($page['hreflangs'] as $alternate) {
                    if (empty($alternate['href'])) {
                        continue;
                    }

                    try {
                        if ($this->urlKey($alternate['href']) === $finalKey) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    $enqueue($alternate['href'], 'hreflang', $finalUrl);
                }

                if (! empty($page['canonical'])) {
                    try {
                        if ($this->urlKey($page['canonical']) !== $finalKey) {
                            $enqueue($page['canonical'], 'canonical', $finalUrl);
                        }
                    } catch (Throwable) {
                        // Canonical validity is reported separately.
                    }
                }
            }
        }

        foreach ($pages as &$page) {
            $page['discovery'] = $this->discoveryEntries(
                $discovery,
                (string) ($page['requestedUrl'] ?? $page['url']),
                (string) $page['url'],
            );
        }
        unset($page);

        $targetChecks = $this->checkUncrawledInternalLinks($pages, $crawlOrigin, $redirectMap);
        foreach ($targetChecks['redirectMap'] as $key => $entry) {
            $redirectMap[$key] = $entry;
        }
        foreach ($targetChecks['redirects'] as $key => $entry) {
            $redirectedRequests[$key] = $entry;
        }

        $socialImageDiagnostics = $this->attachSocialImageDiagnostics($pages);

        if ($socialImageDiagnostics['discovered'] > $socialImageDiagnostics['checked']) {
            $site['issues'][] = [
                'code' => 'social_image_check_limited',
                'severity' => 'info',
                'message' => 'Checked '.$socialImageDiagnostics['checked'].' of '.$socialImageDiagnostics['discovered'].
                    ' unique social-image URLs because the per-audit safety limit is '.self::MAX_SOCIAL_IMAGES.'.',
            ];
        }

        $crossPage = $this->addCrossPageChecks(
            $pages,
            $crawlOrigin,
            $redirectMap,
            $sitemapSeedUrls,
            $targetChecks['checks'],
        );

        $site['issues'] = array_merge($site['issues'], $targetChecks['siteIssues'], $crossPage['siteIssues']);

        $canonicalPreference = $this->canonicalOriginPreference($pages, $crawlOrigin, $seed);
        $preferredOrigin = $canonicalPreference['origin'];

        if (
            $preferredOrigin !== $crawlOrigin
            && $canonicalPreference['source'] === 'sitewide-canonical'
        ) {
            $site['issues'][] = [
                'code' => 'canonical_origin_conflict',
                'severity' => 'warning',
                'message' => 'Most page canonicals prefer '.$preferredOrigin.' while this crawl used '.$crawlOrigin.
                    '. Treat this as a host-normalization problem, not dozens of independent page canonicals.',
            ];
        }

        $originNormalization = $this->originInspector->inspect($inputUrl, $preferredOrigin);
        $originNormalization['preferredOriginSource'] = $canonicalPreference['source'];
        $originNormalization['canonicalConfidence'] = $canonicalPreference['confidence'];
        $originNormalization['inputOrigin'] = $inputOrigin;
        $originNormalization['auditSeedUrl'] = $auditSeedUrl;
        $originNormalization['seedConflict'] = $inputOrigin !== $preferredOrigin;
        $site['issues'] = array_merge($site['issues'], $originNormalization['issues']);

        $indexablePages = 0;
        $hostAliasPages = 0;
        foreach ($pages as &$page) {
            $page['indexability'] = $this->indexability($page, $preferredOrigin);
            if ($page['indexability']['status'] === 'host-alias') {
                $hostAliasPages++;
            }
            if ($page['indexability']['status'] === 'indexable') {
                $indexablePages++;
            }
        }
        unset($page);

        $environmentDiagnostics = $this->applyEnvironmentContext(
            $pages,
            $site['issues'],
            $environment,
            $preferredOrigin,
        );

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $languages = [];
        $hreflangCodes = [];
        $noindexPages = 0;
        $robotsBlockedPages = 0;

        foreach ($site['issues'] as $issue) {
            if (($issue['expected'] ?? false) === true) {
                continue;
            }
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
                if (($issue['expected'] ?? false) === true) {
                    continue;
                }
                $counts[$issue['severity']]++;
            }
        }

        $languageList = array_keys($languages);
        $hreflangList = array_keys($hreflangCodes);
        sort($languageList);
        sort($hreflangList);

        $issuePatterns = $this->patterns->analyze($pages);

        return [
            'version' => config('audit.version'),
            'environment' => $environment,
            'startUrl' => $inputUrl,
            'auditSeedUrl' => $auditSeedUrl,
            'inputOrigin' => $inputOrigin,
            'origin' => $preferredOrigin,
            'auditedAt' => now()->toIso8601String(),
            'maxPages' => $maxPages,
            'site' => [
                'robotsTxt' => $site['robotsTxt'],
                'sitemaps' => $site['sitemaps'],
                'sitemapUrlsDiscovered' => $site['sitemapUrlsDiscovered'],
                'crawlCoverage' => $crossPage['crawlCoverage'],
                'redirects' => array_values($redirectedRequests),
                'linkTargetChecks' => array_values($targetChecks['checks']),
                'originNormalization' => $originNormalization,
                'socialImages' => $socialImageDiagnostics,
                'environment' => $environmentDiagnostics,
                'patterns' => $issuePatterns,
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
                'socialImagesChecked' => $socialImageDiagnostics['checked'],
                'socialImagesDiscovered' => $socialImageDiagnostics['discovered'],
                'hostAliasPages' => $hostAliasPages,
                'stagingProtectedPages' => $environmentDiagnostics['protectedPages'],
                'stagingUnprotectedPages' => $environmentDiagnostics['unprotectedPages'],
                'expectedStagingFindings' => $environmentDiagnostics['expectedFindings'],
                'maxCrawlDepth' => $crossPage['maxCrawlDepth'],
                'contextualLinks' => $crossPage['contextualLinks'],
                'patterns' => count($issuePatterns),
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
        $sitemapSources = [];

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
                $sitemapSources[$pageKey][$this->urlKey($finalUrl)] = $finalUrl;

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

        foreach ($sitemapSources as $key => $sources) {
            $sitemapSources[$key] = array_values($sources);
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
            'sitemapSources' => $sitemapSources,
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

    private function httpHreflangs(string $baseUrl, ?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        preg_match_all(
            '/<([^>]+)>\s*((?:;\s*[A-Za-z][A-Za-z0-9_-]*\s*=\s*(?:"[^"]*"|[^;,\s]+))+)/',
            $header,
            $matches,
            PREG_SET_ORDER,
        );

        $alternates = [];

        foreach ($matches as $match) {
            $params = $match[2] ?? '';

            if (
                ! preg_match('/;\s*rel\s*=\s*"?([^";]+)"?/i', $params, $rel)
                || ! in_array('alternate', preg_split('/\s+/', strtolower(trim($rel[1]))) ?: [], true)
                || ! preg_match('/;\s*hreflang\s*=\s*"?([^";,\s]+)"?/i', $params, $langMatch)
            ) {
                continue;
            }

            $href = $this->urls->resolve($baseUrl, $match[1]);
            $lang = strtolower(trim($langMatch[1]));

            if ($href !== null && $lang !== '') {
                $alternates[] = [
                    'lang' => $lang,
                    'href' => $href,
                    'source' => 'http-header',
                ];
            }
        }

        return $alternates;
    }

    private function checkUncrawledInternalLinks(array $pages, string $crawlOrigin, array $knownRedirects): array
    {
        $knownPages = [];
        foreach ($pages as $page) {
            try {
                $knownPages[$this->urlKey($page['url'])] = true;
            } catch (Throwable) {
                // Ignore malformed page URLs already represented as fetch failures.
            }
        }

        $candidates = [];
        foreach ($pages as $page) {
            foreach ($page['links'] ?? [] as $link) {
                if (! $this->urls->sameOrigin($crawlOrigin, $link)) {
                    continue;
                }

                try {
                    $key = $this->urlKey($link);
                } catch (Throwable) {
                    continue;
                }

                if (isset($knownPages[$key]) || isset($knownRedirects[$key])) {
                    continue;
                }

                $candidates[$key] = $link;
            }
        }

        $totalCandidates = count($candidates);
        $candidates = array_slice($candidates, 0, 50, true);
        $checks = [];
        $redirectMap = [];
        $redirects = [];
        $siteIssues = [];

        if ($totalCandidates > 50) {
            $siteIssues[] = [
                'code' => 'link_target_check_limited',
                'severity' => 'info',
                'message' => 'Checked 50 of '.$totalCandidates.' uncrawled internal link targets because the independent link-check limit was reached.',
            ];
        }

        foreach (array_chunk($candidates, self::CONCURRENCY, true) as $batch) {
            $urls = array_values($batch);
            $responses = Http::pool(
                fn (Pool $pool) => array_map(
                    fn (string $url) => $pool
                        ->withHeaders([
                            'User-Agent' => $this->userAgent(),
                            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                        ])
                        ->withOptions(['allow_redirects' => false])
                        ->connectTimeout(3)
                        ->timeout(self::RESOURCE_TIMEOUT)
                        ->get($url),
                    $urls,
                ),
                concurrency: self::CONCURRENCY,
            );

            foreach ($urls as $index => $requestedUrl) {
                $response = $responses[$index] ?? null;
                $requestedKey = $this->urlKey($requestedUrl);

                if ($response instanceof Throwable || ! $response instanceof Response) {
                    $checks[$requestedKey] = [
                        'url' => $requestedUrl,
                        'status' => 0,
                        'finalUrl' => $requestedUrl,
                        'chain' => [],
                        'error' => $response instanceof Throwable ? $response->getMessage() : 'Request failed.',
                    ];
                    continue;
                }

                try {
                    [$finalResponse, $finalUrl, $chain] = $this->followRedirects(
                        $requestedUrl,
                        $response,
                        'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                        self::RESOURCE_TIMEOUT,
                    );

                    $checks[$requestedKey] = [
                        'url' => $requestedUrl,
                        'status' => $finalResponse->status(),
                        'finalUrl' => $finalUrl,
                        'chain' => $chain,
                        'error' => null,
                    ];

                    if ($chain !== []) {
                        $redirects[$requestedKey] = [
                            'requestedUrl' => $requestedUrl,
                            'finalUrl' => $finalUrl,
                            'chain' => $chain,
                        ];

                        foreach ($chain as $hop) {
                            $redirectMap[$this->urlKey($hop['from'])] = [
                                'finalUrl' => $finalUrl,
                                'chain' => $chain,
                            ];
                        }
                    }
                } catch (Throwable $exception) {
                    $checks[$requestedKey] = [
                        'url' => $requestedUrl,
                        'status' => 0,
                        'finalUrl' => $requestedUrl,
                        'chain' => [],
                        'error' => $exception->getMessage(),
                    ];
                }
            }
        }

        return compact('checks', 'redirectMap', 'redirects', 'siteIssues');
    }

    private function attachSocialImageDiagnostics(array &$pages): array
    {
        $urls = [];

        foreach ($pages as $page) {
            foreach ([$page['openGraph']['image'] ?? null, $page['twitter']['image'] ?? null] as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[$url] = $url;
                }
            }
        }

        $discovered = count($urls);
        $urls = array_slice(array_values($urls), 0, self::MAX_SOCIAL_IMAGES);
        $results = $this->socialImages->inspect($urls);

        foreach ($pages as &$page) {
            $page['socialImages'] = [
                'openGraph' => null,
                'twitter' => null,
            ];

            foreach ([
                'openGraph' => $page['openGraph']['image'] ?? null,
                'twitter' => $page['twitter']['image'] ?? null,
            ] as $kind => $url) {
                if (! is_string($url) || $url === '' || ! isset($results[$url])) {
                    continue;
                }

                $result = $results[$url];
                $page['socialImages'][$kind] = $result;
                $label = $kind === 'openGraph' ? 'Open Graph' : 'Twitter/X';

                if (($result['status'] ?? 0) === 0) {
                    $this->issue(
                        $page,
                        'social_image_unreachable',
                        'warning',
                        $label.' image could not be fetched: '.$url.'.',
                    );
                } elseif (($result['status'] ?? 0) >= 400) {
                    $this->issue(
                        $page,
                        'social_image_http_error',
                        'warning',
                        $label.' image returns HTTP '.$result['status'].': '.$url.'.',
                    );
                } elseif (($result['status'] ?? 0) >= 300) {
                    $this->issue(
                        $page,
                        'social_image_redirect',
                        'info',
                        $label.' image URL redirects (HTTP '.$result['status'].'): '.$url.'.',
                    );
                } elseif (! str_starts_with((string) ($result['contentType'] ?? ''), 'image/')) {
                    $this->issue(
                        $page,
                        'social_image_content_type',
                        'warning',
                        $label.' image URL does not return an image content type: '.$url.'.',
                    );
                }
            }
        }
        unset($page);

        return [
            'checked' => count($results),
            'discovered' => $discovered,
            'limit' => self::MAX_SOCIAL_IMAGES,
        ];
    }

    private function addCrossPageChecks(
        array &$pages,
        string $crawlOrigin,
        array $redirectMap,
        array $sitemapUrls,
        array $linkTargetChecks = [],
    ): array {
        $byUrl = [];
        $titles = [];
        $descriptions = [];
        $contentHashes = [];
        $incoming = [];
        $contextualIncoming = [];
        $adjacency = [];
        $siteIssues = [];
        $internalLinksToRedirects = 0;
        $mixedSchemeLinks = 0;
        $duplicateContentGroups = 0;
        $orphanCandidates = 0;
        $contextualLinks = 0;
        $maxCrawlDepth = 0;

        foreach ($pages as $index => &$page) {
            $page['internalLinks'] = [
                'incoming' => 0,
                'outgoing' => 0,
                'contextualIncoming' => 0,
                'contextualOutgoing' => 0,
                'redirecting' => 0,
                'crawlDepth' => null,
            ];
            $page['inSitemap'] = false;

            try {
                $key = $this->urlKey($page['url']);
                $byUrl[$key] = $index;
                $incoming[$key] = 0;
                $contextualIncoming[$key] = 0;
                $adjacency[$key] = [];
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
            $seenContextualOutgoing = [];
            $redirectLinks = [];
            $mixedScheme = [];
            $broken = [];
            $contextualTargets = [];

            foreach ($page['linkDetails'] ?? [] as $detail) {
                if (($detail['location'] ?? '') !== 'contextual') {
                    continue;
                }

                try {
                    $contextualTargets[$this->urlKey($detail['href'])] = true;
                } catch (Throwable) {
                    // Ignore malformed link detail.
                }
            }

            try {
                $sourceKey = $this->urlKey($page['url']);
            } catch (Throwable) {
                $sourceKey = null;
            }

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

                    if ($sourceKey !== null && isset($byUrl[$effectiveKey])) {
                        $adjacency[$sourceKey][$effectiveKey] = true;
                    }

                    if (isset($byUrl[$effectiveKey])) {
                        $incoming[$effectiveKey] = ($incoming[$effectiveKey] ?? 0) + 1;
                    }
                }

                $isContextual = isset($contextualTargets[$targetKey]) || isset($contextualTargets[$effectiveKey]);
                if ($isContextual && ! isset($seenContextualOutgoing[$effectiveKey])) {
                    $seenContextualOutgoing[$effectiveKey] = true;
                    $page['internalLinks']['contextualOutgoing']++;
                    $contextualLinks++;

                    if (isset($byUrl[$effectiveKey])) {
                        $contextualIncoming[$effectiveKey] = ($contextualIncoming[$effectiveKey] ?? 0) + 1;
                    }
                }

                if (isset($byUrl[$effectiveKey]) && $pages[$byUrl[$effectiveKey]]['status'] >= 400) {
                    $broken[$pages[$byUrl[$effectiveKey]]['url']] = true;
                } elseif (isset($linkTargetChecks[$targetKey]) && ($linkTargetChecks[$targetKey]['status'] ?? 0) >= 400) {
                    $broken[$link] = true;
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
                    count($broken).' checked internal link(s) point to HTTP errors; first: '.$first.'.',
                );
            }
        }
        unset($page);

        foreach ($byUrl as $key => $index) {
            $pages[$index]['internalLinks']['incoming'] = $incoming[$key] ?? 0;
            $pages[$index]['internalLinks']['contextualIncoming'] = $contextualIncoming[$key] ?? 0;
        }

        if ($pages !== []) {
            try {
                $startKey = $this->urlKey($pages[0]['url']);
                $depths = [$startKey => 0];
                $queue = [$startKey];

                while ($queue !== []) {
                    $current = array_shift($queue);
                    $depth = $depths[$current];

                    foreach (array_keys($adjacency[$current] ?? []) as $target) {
                        if (isset($depths[$target])) {
                            continue;
                        }

                        $depths[$target] = $depth + 1;
                        $maxCrawlDepth = max($maxCrawlDepth, $depth + 1);
                        $queue[] = $target;
                    }
                }

                foreach ($depths as $key => $depth) {
                    if (isset($byUrl[$key])) {
                        $pages[$byUrl[$key]]['internalLinks']['crawlDepth'] = $depth;
                    }
                }
            } catch (Throwable) {
                // Crawl depth is diagnostic only; leave null if the start URL cannot be normalized.
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
            'maxCrawlDepth' => $maxCrawlDepth,
            'contextualLinks' => $contextualLinks,
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

    private function resolveAuditSeed(string $inputUrl): array
    {
        $inputOrigin = $this->urls->origin($inputUrl);
        $seedUrl = $inputUrl;
        $seedOrigin = $inputOrigin;
        $redirectFinalUrl = $inputUrl;
        $redirectChain = [];
        $source = 'input';

        try {
            [$response, $finalUrl, $redirectChain] = $this->fetchResource(
                $inputUrl,
                'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
            );

            $redirectFinalUrl = $finalUrl;
            $seedUrl = $finalUrl;
            $seedOrigin = $this->urls->origin($finalUrl);
            $source = $redirectChain !== [] ? 'redirect' : 'input';

            $contentType = strtolower($response->header('Content-Type'));
            if (
                $response->status() >= 200
                && $response->status() < 300
                && (
                    str_contains($contentType, 'text/html')
                    || str_contains($contentType, 'application/xhtml+xml')
                )
                && strlen($response->body()) <= self::MAX_BODY_BYTES
            ) {
                $canonical = $this->canonicalFromHtml($finalUrl, $response->body());

                if (
                    $canonical !== null
                    && $this->sameOriginFamily($finalUrl, $canonical)
                    && $this->urls->origin($canonical) !== $this->urls->origin($finalUrl)
                ) {
                    $safeCanonical = $this->urls->assertPublic($canonical);
                    $seedUrl = $safeCanonical;
                    $seedOrigin = $this->urls->origin($safeCanonical);
                    $source = 'start-page-canonical';
                }
            }
        } catch (Throwable) {
            // The normal crawl will report the fetch problem; seed resolution is best-effort.
        }

        return [
            'inputUrl' => $inputUrl,
            'inputOrigin' => $inputOrigin,
            'seedUrl' => $seedUrl,
            'seedOrigin' => $seedOrigin,
            'redirectFinalUrl' => $redirectFinalUrl,
            'redirectChain' => $redirectChain,
            'source' => $source,
        ];
    }

    private function canonicalFromHtml(string $baseUrl, string $html): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(
            "//link[contains(concat(' ', normalize-space(translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')), ' '), ' canonical ')][@href]"
        );
        $node = $nodes instanceof DOMNodeList ? $nodes->item(0) : null;

        if (! $node instanceof \DOMElement) {
            return null;
        }

        return $this->urls->resolve($baseUrl, $node->getAttribute('href'));
    }

    private function canonicalOriginPreference(array $pages, string $crawlOrigin, array $seed): array
    {
        $counts = [];
        $total = 0;

        foreach ($pages as $page) {
            if (empty($page['canonical'])) {
                continue;
            }

            try {
                $origin = $this->urls->origin($page['canonical']);
            } catch (Throwable) {
                continue;
            }

            if (! $this->sameOriginFamily($crawlOrigin, $origin)) {
                continue;
            }

            $counts[$origin] = ($counts[$origin] ?? 0) + 1;
            $total++;
        }

        if ($counts !== []) {
            arsort($counts);
            $origin = (string) array_key_first($counts);
            $count = (int) $counts[$origin];
            $confidence = $total > 0 ? $count / $total : 0.0;

            if ($count >= 2 && $confidence >= 0.8) {
                return [
                    'origin' => $origin,
                    'source' => 'sitewide-canonical',
                    'confidence' => round($confidence, 3),
                ];
            }
        }

        return [
            'origin' => $crawlOrigin,
            'source' => $seed['source'] === 'start-page-canonical'
                ? 'start-page-canonical'
                : 'crawl-origin',
            'confidence' => $seed['source'] === 'start-page-canonical' ? 1.0 : null,
        ];
    }

    private function sameOriginFamily(string $a, string $b): bool
    {
        $hostA = strtolower((string) parse_url($a, PHP_URL_HOST));
        $hostB = strtolower((string) parse_url($b, PHP_URL_HOST));

        if ($hostA === '' || $hostB === '') {
            return false;
        }

        if (filter_var($hostA, FILTER_VALIDATE_IP) || filter_var($hostB, FILTER_VALIDATE_IP)) {
            return $hostA === $hostB;
        }

        $familyA = str_starts_with($hostA, 'www.') ? substr($hostA, 4) : $hostA;
        $familyB = str_starts_with($hostB, 'www.') ? substr($hostB, 4) : $hostB;

        return $familyA === $familyB;
    }

    private function isPreferredHostAliasCanonical(
        string $pageUrl,
        string $canonical,
        string $preferredOrigin,
    ): bool {
        if (! $this->sameOriginFamily($pageUrl, $canonical)) {
            return false;
        }

        if ($this->urls->origin($canonical) !== $preferredOrigin) {
            return false;
        }

        try {
            $page = $this->urls->normalize($pageUrl);
            $target = $this->urls->normalize($canonical);
        } catch (Throwable) {
            return false;
        }

        return (string) parse_url($page, PHP_URL_PATH) === (string) parse_url($target, PHP_URL_PATH)
            && (string) parse_url($page, PHP_URL_QUERY) === (string) parse_url($target, PHP_URL_QUERY);
    }

    private function applyEnvironmentContext(
        array &$pages,
        array &$siteIssues,
        string $environment,
        string $preferredOrigin,
    ): array {
        $diagnostics = [
            'mode' => $environment,
            'status' => $environment === 'staging' ? 'unknown' : 'live',
            'protectedPages' => 0,
            'unprotectedPages' => 0,
            'expectedFindings' => 0,
            'metaNoindexPages' => 0,
            'xRobotsNoindexPages' => 0,
            'robotsBlockedPages' => 0,
            'crossOriginCanonicalPages' => 0,
            'unprotectedUrls' => [],
            'note' => $environment === 'staging'
                ? 'Staging mode treats intentional noindex/robots protection as expected. robots.txt is not access control or a security boundary.'
                : 'Live mode interprets indexability normally.',
        ];

        if ($environment !== 'staging') {
            return $diagnostics;
        }

        $expectedCodes = [
            'robots_noindex',
            'x_robots_noindex',
            'robots_txt_blocked',
            'sitemap_noindex',
            'canonical_target_noindex',
            'hreflang_target_noindex',
        ];

        foreach ($pages as &$page) {
            $metaNoindex = $this->directiveContains($page['robots'] ?? null, 'noindex');
            $xRobotsNoindex = $this->directiveContains($page['xRobotsTag'] ?? null, 'noindex');
            $robotsBlocked = ($page['robotsTxt']['allowed'] ?? true) === false;

            if ($metaNoindex) {
                $diagnostics['metaNoindexPages']++;
            }
            if ($xRobotsNoindex) {
                $diagnostics['xRobotsNoindexPages']++;
            }
            if ($robotsBlocked) {
                $diagnostics['robotsBlockedPages']++;
            }

            $crossOriginCanonical = false;
            if (! empty($page['canonical'])) {
                try {
                    $crossOriginCanonical = $this->urls->origin($page['canonical']) !== $this->urls->origin($page['url']);
                } catch (Throwable) {
                    $crossOriginCanonical = false;
                }
            }

            if ($crossOriginCanonical) {
                $diagnostics['crossOriginCanonicalPages']++;
            }

            $protected = $metaNoindex || $xRobotsNoindex || $robotsBlocked;
            $page['environment'] = [
                'mode' => 'staging',
                'protectedFromIndexing' => $protected,
                'metaNoindex' => $metaNoindex,
                'xRobotsNoindex' => $xRobotsNoindex,
                'robotsBlocked' => $robotsBlocked,
                'crossOriginCanonical' => $crossOriginCanonical,
            ];

            if ($protected) {
                $diagnostics['protectedPages']++;
            } elseif (($page['status'] ?? 0) >= 200 && ($page['status'] ?? 0) < 300) {
                $diagnostics['unprotectedPages']++;
                $diagnostics['unprotectedUrls'][] = $page['url'];
            }

            foreach ($page['issues'] as &$issue) {
                $expected = in_array($issue['code'] ?? '', $expectedCodes, true);

                if (
                    $crossOriginCanonical
                    && in_array(
                        $issue['code'] ?? '',
                        ['canonical_other', 'sitemap_noncanonical', 'hreflang_target_canonical_other'],
                        true,
                    )
                ) {
                    $expected = true;
                }

                if (! $expected) {
                    continue;
                }

                $issue['expected'] = true;
                $issue['severity'] = 'info';
                $diagnostics['expectedFindings']++;
            }
            unset($issue);
        }
        unset($page);

        if ($diagnostics['unprotectedPages'] > 0) {
            $examples = array_slice($diagnostics['unprotectedUrls'], 0, 3);
            $extra = $diagnostics['unprotectedPages'] > count($examples)
                ? ' +'.($diagnostics['unprotectedPages'] - count($examples)).' more'
                : '';

            $siteIssues[] = [
                'code' => 'staging_unprotected_pages',
                'severity' => 'warning',
                'message' => $diagnostics['unprotectedPages'].' staging page'.
                    ($diagnostics['unprotectedPages'] === 1 ? ' is' : 's are').
                    ' not protected by noindex, X-Robots-Tag noindex, or robots.txt: '.
                    implode(', ', $examples).$extra.'.',
            ];

            $diagnostics['status'] = $diagnostics['protectedPages'] > 0 ? 'partial' : 'unprotected';
        } else {
            $diagnostics['status'] = $diagnostics['protectedPages'] > 0 ? 'protected' : 'unknown';
        }

        return $diagnostics;
    }

    private function indexability(array $page, string $preferredOrigin): array
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
                    if ($this->isPreferredHostAliasCanonical($page['url'], $page['canonical'], $preferredOrigin)) {
                        return [
                            'status' => 'host-alias',
                            'reason' => 'Canonical points to the same page on preferred origin '.$preferredOrigin.
                                '; this is a host-normalization issue rather than page-level canonicalization.',
                        ];
                    }

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

    private function recordDiscovery(
        array &$discovery,
        string $url,
        string $type,
        ?string $from,
    ): void {
        try {
            $key = $this->urlKey($url);
        } catch (Throwable) {
            return;
        }

        $entryKey = $type.'|'.($from ?? '');
        $discovery[$key][$entryKey] = [
            'type' => $type,
            'from' => $from,
        ];
    }

    private function discoveryEntries(array $discovery, string ...$urls): array
    {
        $entries = [];

        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }

            try {
                $key = $this->urlKey($url);
            } catch (Throwable) {
                continue;
            }

            foreach ($discovery[$key] ?? [] as $entryKey => $entry) {
                $entries[$entryKey] = $entry;
            }
        }

        $values = array_values($entries);
        usort($values, static function (array $a, array $b): int {
            $order = [
                'start' => 0,
                'sitemap' => 1,
                'internal-link' => 2,
                'hreflang' => 3,
                'canonical' => 4,
                'redirect' => 5,
            ];

            return ($order[$a['type']] ?? 99) <=> ($order[$b['type']] ?? 99);
        });

        return $values;
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
            'robotsTxt' => [
                'allowed' => true,
                'matchedDirective' => null,
                'matchedRule' => null,
            ],
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
            'contentHashStatus' => 'not generated (fetch failed)',
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
                'parseable' => 0,
                'invalidSyntax' => 0,
                'types' => [],
                'semanticIssues' => [],
            ],
            'socialImages' => [
                'openGraph' => null,
                'twitter' => null,
            ],
            'issues' => [[
                'code' => 'fetch_failed',
                'severity' => 'error',
                'message' => $message,
            ]],
            'links' => [],
            'linkDetails' => [],
            'discovery' => [],
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
