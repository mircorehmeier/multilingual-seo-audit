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

        foreach ($result['pages'] as $auditedPage) {
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
        $this->assertContains('redirect_chain', $issuesByUrl['https://1.1.1.1/about/']);
        $this->assertContains('content_duplicate', $issuesByUrl['https://1.1.1.1/about/']);
        $this->assertContains('content_duplicate', $issuesByUrl['https://1.1.1.1/copy/']);
        $this->assertContains('orphan_candidate', $issuesByUrl['https://1.1.1.1/orphan/']);
    }

}
