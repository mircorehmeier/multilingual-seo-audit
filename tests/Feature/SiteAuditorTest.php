<?php

namespace Tests\Feature;

use App\Services\SiteAuditor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAuditorTest extends TestCase
{
    public function test_sitemap_seeded_crawl_deduplicates_redirect_targets_and_downgrades_multilingual_title_duplicates(): void
    {
        $page = static function (string $lang, string $otherLang): string {
            $otherPath = $otherLang === 'de' ? '/de/' : '/en/';
            $selfPath = $lang === 'de' ? '/de/' : '/en/';
            $description = $lang === 'de'
                ? 'Eine eindeutige deutsche Beschreibung für die Portfolio-Seite.'
                : 'A unique English description for the portfolio page.';

            return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<title>Portfolio</title>
<meta name="description" content="{$description}">
<link rel="canonical" href="https://1.1.1.1{$selfPath}">
<link rel="alternate" hreflang="en" href="https://1.1.1.1/en/">
<link rel="alternate" hreflang="de" href="https://1.1.1.1/de/">
<meta property="og:title" content="Portfolio">
<meta property="og:description" content="{$description}">
<meta property="og:image" content="https://1.1.1.1/image.jpg">
<meta name="twitter:card" content="summary_large_image">
</head>
<body>
<h1>Portfolio</h1>
<p>This is enough content to make the test page useful for the crawler.</p>
<a href="https://1.1.1.1{$otherPath}">Other language</a>
</body>
</html>
HTML;
        };

        Http::fake(function ($request) use ($page) {
            return match ($request->url()) {
                'https://1.1.1.1/robots.txt' => Http::response("Sitemap: https://1.1.1.1/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
                'https://1.1.1.1/sitemap.xml' => Http::response(
                    '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://1.1.1.1/en/</loc></url><url><loc>https://1.1.1.1/de/</loc></url></urlset>',
                    200,
                    ['Content-Type' => 'application/xml'],
                ),
                'https://1.1.1.1/' => Http::response('', 301, ['Location' => '/en/']),
                'https://1.1.1.1/en/' => Http::response($page('en', 'de'), 200, ['Content-Type' => 'text/html']),
                'https://1.1.1.1/de/' => Http::response($page('de', 'en'), 200, ['Content-Type' => 'text/html']),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SiteAuditor::class)->audit('https://1.1.1.1', 10);

        $this->assertSame(2, $result['summary']['pages']);
        $this->assertSame(2, $result['summary']['sitemapUrls']);
        $this->assertCount(2, array_unique(array_column($result['pages'], 'url')));
        $this->assertSame(2, $result['summary']['indexablePages']);
        $this->assertNotEmpty($result['site']['originNormalization']['variants']);
        $this->assertNotEmpty($result['site']['patterns']);

        $pagesByUrl = [];
        foreach ($result['pages'] as $auditedPage) {
            $pagesByUrl[$auditedPage['url']] = $auditedPage;
        }

        $englishDiscovery = array_column($pagesByUrl['https://1.1.1.1/en/']['discovery'], 'type');
        $germanDiscovery = array_column($pagesByUrl['https://1.1.1.1/de/']['discovery'], 'type');

        $this->assertContains('start', $englishDiscovery);
        $this->assertContains('sitemap', $englishDiscovery);
        $this->assertContains('redirect', $englishDiscovery);
        $this->assertContains('sitemap', $germanDiscovery);
        $this->assertContains('internal-link', $germanDiscovery);
        $this->assertContains('hreflang', $germanDiscovery);

        foreach ($result['pages'] as $auditedPage) {
            $this->assertSame('indexable', $auditedPage['indexability']['status']);
            $issueMap = [];
            foreach ($auditedPage['issues'] as $issue) {
                $issueMap[$issue['code']] = $issue['severity'];
            }

            $this->assertSame('info', $issueMap['title_duplicate_multilingual']);
            $this->assertArrayNotHasKey('title_duplicate', $issueMap);
        }
    }
    public function test_redirect_links_duplicate_content_and_orphan_candidates_are_reported_with_complete_sitemap_coverage(): void
    {
        $shared = str_repeat(
            'This page contains detailed information about the project, services, experience, technology, design, development and useful resources for visitors. '.
            'The content explains how the work is organized, what users can expect, and where they can find additional information about the available services. ',
            5,
        );

        $page = static function (string $path, string $title, string $body, string $links = ''): string {
            return <<<HTML
<!doctype html>
<html lang="en">
<head>
<title>{$title}</title>
<meta name="description" content="A complete unique description for {$title} that is long enough for the audit test.">
<link rel="canonical" href="https://1.1.1.1{$path}">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="Description">
<meta property="og:image" content="https://1.1.1.1/image.jpg">
<meta name="twitter:card" content="summary_large_image">
</head>
<body><main><h1>{$title}</h1><p>{$body}</p>{$links}</main></body>
</html>
HTML;
        };

        Http::fake(function ($request) use ($page, $shared) {
            return match ($request->url()) {
                'https://1.1.1.1/robots.txt' => Http::response("Sitemap: https://1.1.1.1/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
                'https://1.1.1.1/sitemap.xml' => Http::response(
                    '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
                    '<url><loc>https://1.1.1.1/en/</loc></url>'.
                    '<url><loc>https://1.1.1.1/about/</loc></url>'.
                    '<url><loc>https://1.1.1.1/copy/</loc></url>'.
                    '<url><loc>https://1.1.1.1/orphan/</loc></url>'.
                    '</urlset>',
                    200,
                    ['Content-Type' => 'application/xml'],
                ),
                'https://1.1.1.1/' => Http::response('', 301, ['Location' => '/en/']),
                'https://1.1.1.1/en/' => Http::response(
                    $page('/en/', 'Home page with a sufficiently descriptive title', str_repeat('Home content for the audit test with useful information for visitors. ', 12), '<a href="/legacy">About</a><a href="/copy/">Copy</a>'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/legacy' => Http::response('', 301, ['Location' => '/middle']),
                'https://1.1.1.1/middle' => Http::response('', 301, ['Location' => '/about/']),
                'https://1.1.1.1/about/' => Http::response(
                    $page('/about/', 'About project and services information', $shared, '<a href="/en/">Home</a>'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/copy/' => Http::response(
                    $page('/copy/', 'Copy of project information page', $shared, '<a href="/en/">Home</a>'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/orphan/' => Http::response(
                    $page('/orphan/', 'Orphan information page for testing', str_repeat('Unique orphan page information with distinct wording for this audit scenario. ', 14), '<a href="/en/">Home</a>'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SiteAuditor::class)->audit('https://1.1.1.1', 10);

        $this->assertTrue($result['site']['crawlCoverage']['complete']);
        $this->assertSame(100.0, $result['site']['crawlCoverage']['percent']);
        $this->assertSame(1, $result['summary']['redirectChains']);
        $this->assertGreaterThanOrEqual(2, $result['summary']['redirects']);
        $this->assertSame(1, $result['summary']['internalLinksToRedirects']);
        $this->assertSame(1, $result['summary']['orphanCandidates']);
        $this->assertSame(1, $result['summary']['duplicateContentGroups']);

        $issuesByUrl = [];
        foreach ($result['pages'] as $auditedPage) {
            $issuesByUrl[$auditedPage['url']] = array_column($auditedPage['issues'], 'code');
        }

        $this->assertContains('internal_link_to_redirect', $issuesByUrl['https://1.1.1.1/en/']);
        $this->assertContains('internal_link_redirect_chain', $issuesByUrl['https://1.1.1.1/en/']);
        $this->assertNotEmpty($result['site']['redirects']);
        $this->assertContains('content_duplicate', $issuesByUrl['https://1.1.1.1/about/']);
        $this->assertContains('content_duplicate', $issuesByUrl['https://1.1.1.1/copy/']);
        $this->assertContains('orphan_candidate', $issuesByUrl['https://1.1.1.1/orphan/']);
    }


    public function test_v06_regression_covers_robots_external_hreflang_uncrawled_links_social_images_and_depth(): void
    {
        $sharePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQMcAAAAASUVORK5CYII=');
        $linkHeader = '<https://1.1.1.1/en/>; rel="alternate"; hreflang="en", <https://1.1.1.1/de/>; rel="alternate"; hreflang="de"';

        $page = static function (string $lang, string $path, string $title, string $links = '', bool $product = false): string {
            $jsonLd = $product
                ? '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Example"}</script>'
                : '';

            return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<title>{$title}</title>
<meta name="description" content="A complete and useful description for {$title} that gives search engines and visitors enough context.">
<link rel="canonical" href="https://1.1.1.1{$path}">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="A useful social description for {$title}.">
<meta property="og:image" content="https://1.1.1.1/share.png">
<meta property="og:url" content="https://1.1.1.1{$path}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="A useful social description for {$title}.">
<meta name="twitter:image" content="https://1.1.1.1/share.png">
{$jsonLd}
</head>
<body>
<main>
<h1>{$title}</h1>
<p>This page contains enough visible content for the multilingual SEO audit regression fixture. It describes the project, services, technology, users, information architecture, search visibility and practical website diagnostics in a realistic way for testing.</p>
{$links}
</main>
</body>
</html>
HTML;
        };

        $sitemap = <<<'XML'
<?xml version="1.0"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url><loc>https://1.1.1.1/blocked/</loc></url>
  <url>
    <loc>https://1.1.1.1/de/</loc>
    <xhtml:link rel="alternate" hreflang="en" href="https://1.1.1.1/en/"/>
    <xhtml:link rel="alternate" hreflang="de" href="https://1.1.1.1/de/"/>
  </url>
  <url>
    <loc>https://1.1.1.1/en/</loc>
    <xhtml:link rel="alternate" hreflang="en" href="https://1.1.1.1/en/"/>
    <xhtml:link rel="alternate" hreflang="de" href="https://1.1.1.1/de/"/>
  </url>
</urlset>
XML;

        Http::fake(function ($request) use ($page, $sitemap, $sharePng, $linkHeader) {
            return match ($request->url()) {
                'https://1.1.1.1/robots.txt' => Http::response(
                    "User-agent: *\nDisallow: /blocked/\nSitemap: https://1.1.1.1/sitemap.xml\n",
                    200,
                    ['Content-Type' => 'text/plain'],
                ),
                'https://1.1.1.1/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
                'https://1.1.1.1/' => Http::response('', 301, ['Location' => '/en/']),
                'https://1.1.1.1/en/' => Http::response(
                    $page(
                        'en',
                        '/en/',
                        'English audit regression fixture page',
                        '<a href="/blocked/">Blocked page</a><a href="/broken/">Broken target outside crawl quota</a>',
                        true,
                    ),
                    200,
                    ['Content-Type' => 'text/html', 'Link' => $linkHeader],
                ),
                'https://1.1.1.1/de/' => Http::response(
                    $page('de', '/de/', 'Deutsche Audit Testseite'),
                    200,
                    ['Content-Type' => 'text/html', 'Link' => $linkHeader],
                ),
                'https://1.1.1.1/blocked/' => Http::response(
                    $page('en', '/blocked/', 'Robots blocked test page'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/broken/' => Http::response('Not found', 404, ['Content-Type' => 'text/html']),
                'https://1.1.1.1/share.png' => Http::response($sharePng, 200, ['Content-Type' => 'image/png']),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SiteAuditor::class)->audit('https://1.1.1.1', 3);

        $this->assertSame(3, $result['summary']['pages']);
        $this->assertSame(1, $result['summary']['robotsBlockedPages']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['linkTargetsChecked']);
        $this->assertNotEmpty($result['site']['linkTargetChecks']);
        $this->assertArrayHasKey('url', $result['site']['linkTargetChecks'][0]);
        $this->assertArrayHasKey('status', $result['site']['linkTargetChecks'][0]);
        $this->assertSame(1, $result['summary']['socialImagesChecked']);
        $this->assertSame(1, $result['summary']['socialImagesDiscovered']);
        $this->assertSame(1, $result['site']['socialImages']['checked']);
        $this->assertSame(1, $result['site']['socialImages']['discovered']);
        $this->assertGreaterThanOrEqual(2, $result['summary']['contextualLinks']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['maxCrawlDepth']);

        $pages = [];
        foreach ($result['pages'] as $auditedPage) {
            $pages[$auditedPage['url']] = $auditedPage;
        }

        $home = $pages['https://1.1.1.1/en/'];
        $blocked = $pages['https://1.1.1.1/blocked/'];

        $homeIssues = array_column($home['issues'], 'code');
        $blockedIssues = array_column($blocked['issues'], 'code');

        $this->assertContains('broken_internal_link', $homeIssues);
        $this->assertContains('jsonld_product_offer_rating_missing', $homeIssues);
        $this->assertContains('robots_txt_blocked', $blockedIssues);
        $this->assertFalse($blocked['robotsTxt']['allowed']);

        $hreflangs = [];
        foreach ($home['hreflangs'] as $entry) {
            $hreflangs[$entry['lang']] = $entry;
        }

        $this->assertStringContainsString('http-header', $hreflangs['en']['source']);
        $this->assertStringContainsString('sitemap', $hreflangs['en']['source']);
        $this->assertSame('image/png', $home['socialImages']['openGraph']['contentType']);
        $this->assertSame(1, $home['socialImages']['openGraph']['width']);
        $this->assertSame(1, $home['socialImages']['openGraph']['height']);
        $this->assertSame(0, $home['internalLinks']['crawlDepth']);
        $this->assertSame(1, $blocked['internalLinks']['crawlDepth']);
    }


    public function test_canonical_preferred_origin_is_used_before_sitemap_discovery(): void
    {
        $page = static function (string $lang, string $path, string $title, string $otherPath): string {
            $otherLang = $lang === 'en' ? 'de' : 'en';

            return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<title>{$title}</title>
<meta name="description" content="A complete description for {$title} used to verify preferred-origin discovery and multilingual crawling behavior.">
<link rel="canonical" href="https://1.1.1.1{$path}">
<link rel="alternate" hreflang="en" href="https://1.1.1.1/en/">
<link rel="alternate" hreflang="de" href="https://1.1.1.1/de/">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="A useful social description for {$title}.">
<meta property="og:image" content="https://1.1.1.1/share.png">
<meta property="og:url" content="https://1.1.1.1{$path}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="A useful social description for {$title}.">
<meta name="twitter:image" content="https://1.1.1.1/share.png">
</head>
<body><main>
<h1>{$title}</h1>
<p>This page contains enough useful content to exercise preferred origin inference, sitemap discovery, canonical handling, language alternates and internal navigation in the audit regression fixture without relying on external services.</p>
<a href="{$otherPath}">{$otherLang}</a>
</main></body>
</html>
HTML;
        };

        $inputHtml = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<title>Alias entry page</title>
<meta name="description" content="Alias entry page used to test canonical preferred-origin inference before sitemap discovery.">
<link rel="canonical" href="https://1.1.1.1/en/">
</head>
<body><main><h1>Alias entry page</h1><p>This entry origin serves HTTP 200 but declares the HTTPS origin as canonical.</p></main></body>
</html>
HTML;

        $sitemap = <<<'XML'
<?xml version="1.0"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://1.1.1.1/en/</loc></url>
  <url><loc>https://1.1.1.1/de/</loc></url>
</urlset>
XML;

        $sharePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQMcAAAAASUVORK5CYII=');

        Http::fake(function ($request) use ($inputHtml, $sitemap, $page, $sharePng) {
            return match ($request->url()) {
                'http://1.1.1.1/' => Http::response($inputHtml, 200, ['Content-Type' => 'text/html']),
                'https://1.1.1.1/robots.txt' => Http::response(
                    "User-agent: *\nSitemap: https://1.1.1.1/sitemap.xml\n",
                    200,
                    ['Content-Type' => 'text/plain'],
                ),
                'https://1.1.1.1/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
                'https://1.1.1.1/' => Http::response(
                    $page('en', '/en/', 'Preferred HTTPS home page', '/de/'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/en/' => Http::response(
                    $page('en', '/en/', 'Preferred HTTPS English page', '/de/'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/de/' => Http::response(
                    $page('de', '/de/', 'Bevorzugte deutsche HTTPS Seite', '/en/'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://1.1.1.1/share.png' => Http::response($sharePng, 200, ['Content-Type' => 'image/png']),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SiteAuditor::class)->audit('http://1.1.1.1/', 10);

        $this->assertSame('http://1.1.1.1/', $result['startUrl']);
        $this->assertSame('http://1.1.1.1', $result['inputOrigin']);
        $this->assertSame('https://1.1.1.1/en/', $result['auditSeedUrl']);
        $this->assertSame('https://1.1.1.1', $result['origin']);
        $this->assertSame(2, $result['summary']['sitemapUrls']);
        $this->assertSame(2, $result['summary']['indexablePages']);
        $this->assertSame(0, $result['summary']['hostAliasPages']);
        $this->assertSame(1, $result['summary']['socialImagesDiscovered']);
        $this->assertSame(1, $result['summary']['socialImagesChecked']);
        $this->assertTrue($result['site']['originNormalization']['seedConflict']);
        $this->assertSame('sitewide-canonical', $result['site']['originNormalization']['preferredOriginSource']);
        $this->assertSame(1.0, $result['site']['originNormalization']['canonicalConfidence']);

        foreach ($result['pages'] as $auditedPage) {
            $this->assertSame('indexable', $auditedPage['indexability']['status']);
        }

        $siteIssueCodes = array_column($result['siteIssues'], 'code');
        $this->assertContains('origin_variant_direct_200', $siteIssueCodes);
        $this->assertNotContains('canonical_origin_conflict', $siteIssueCodes);
    }


    public function test_staging_mode_treats_intentional_noindex_as_expected_and_excludes_it_from_normal_counts(): void
    {
        $page = static function (string $path, string $title): string {
            return <<<HTML
<!doctype html>
<html lang="en">
<head>
<title>{$title}</title>
<meta name="description" content="A complete staging description for {$title} that is long enough for the audit regression fixture.">
<meta name="robots" content="noindex,nofollow">
<link rel="canonical" href="https://1.1.1.1{$path}">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="Staging social description">
<meta property="og:image" content="https://1.1.1.1/share.png">
<meta property="og:url" content="https://1.1.1.1{$path}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="Staging social description">
<meta name="twitter:image" content="https://1.1.1.1/share.png">
</head>
<body><main><h1>{$title}</h1><p>This staging page contains enough visible content for the audit fixture and is intentionally protected from indexing before publication. The content is complete enough to avoid unrelated thin-content observations during the test.</p></main></body>
</html>
HTML;
        };

        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
            '<url><loc>https://1.1.1.1/</loc></url>'.
            '<url><loc>https://1.1.1.1/about/</loc></url>'.
            '</urlset>';

        $sharePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQMcAAAAASUVORK5CYII=');

        Http::fake(function ($request) use ($page, $sitemap, $sharePng) {
            return match ($request->url()) {
                'https://1.1.1.1/robots.txt' => Http::response(
                    "User-agent: *\nSitemap: https://1.1.1.1/sitemap.xml\n",
                    200,
                    ['Content-Type' => 'text/plain'],
                ),
                'https://1.1.1.1/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
                'https://1.1.1.1/' => Http::response($page('/', 'Staging home page for audit testing'), 200, ['Content-Type' => 'text/html']),
                'https://1.1.1.1/about/' => Http::response($page('/about/', 'Staging about page for audit testing'), 200, ['Content-Type' => 'text/html']),
                'https://1.1.1.1/share.png' => Http::response($sharePng, 200, ['Content-Type' => 'image/png']),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SiteAuditor::class)->audit('https://1.1.1.1/', 10, 'staging');

        $this->assertSame('staging', $result['environment']);
        $this->assertSame('protected', $result['site']['environment']['status']);
        $this->assertSame(2, $result['site']['environment']['protectedPages']);
        $this->assertSame(0, $result['site']['environment']['unprotectedPages']);
        $this->assertGreaterThanOrEqual(4, $result['site']['environment']['expectedFindings']);
        $this->assertSame(0, $result['summary']['warnings']);
        $this->assertSame(0, $result['summary']['stagingUnprotectedPages']);

        foreach ($result['pages'] as $auditedPage) {
            $expectedCodes = array_column(
                array_values(array_filter(
                    $auditedPage['issues'],
                    static fn (array $issue): bool => ($issue['expected'] ?? false) === true,
                )),
                'code',
            );

            $this->assertContains('robots_noindex', $expectedCodes);
            $this->assertContains('sitemap_noindex', $expectedCodes);
        }

        $this->assertSame([], $result['site']['patterns']);
    }

    public function test_staging_mode_warns_when_pages_are_left_indexable(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<title>Unprotected staging page used for launch testing</title>
<meta name="description" content="A complete description for an intentionally unprotected staging fixture used to test the launch safety warning.">
<link rel="canonical" href="https://1.1.1.1/">
<meta property="og:title" content="Unprotected staging page">
<meta property="og:description" content="Description">
<meta property="og:image" content="https://1.1.1.1/share.png">
<meta property="og:url" content="https://1.1.1.1/">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Unprotected staging page">
<meta name="twitter:description" content="Description">
<meta name="twitter:image" content="https://1.1.1.1/share.png">
</head>
<body><main><h1>Unprotected staging page</h1><p>This page intentionally lacks noindex and robots blocking so the staging-mode safety warning can be tested without unrelated technical failures.</p></main></body>
</html>
HTML;

        $sharePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQMcAAAAASUVORK5CYII=');

        Http::fake([
            'https://1.1.1.1/robots.txt' => Http::response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']),
            'https://1.1.1.1/sitemap.xml' => Http::response('', 404, ['Content-Type' => 'application/xml']),
            'https://1.1.1.1/' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            'https://1.1.1.1/share.png' => Http::response($sharePng, 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(SiteAuditor::class)->audit('https://1.1.1.1/', 5, 'staging');

        $this->assertSame('unprotected', $result['site']['environment']['status']);
        $this->assertSame(1, $result['site']['environment']['unprotectedPages']);
        $this->assertSame(1, $result['summary']['stagingUnprotectedPages']);

        $siteCodes = array_column($result['siteIssues'], 'code');
        $this->assertContains('staging_unprotected_pages', $siteCodes);
        $this->assertGreaterThanOrEqual(1, $result['summary']['warnings']);
    }

}
