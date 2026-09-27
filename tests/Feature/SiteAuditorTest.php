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
}
