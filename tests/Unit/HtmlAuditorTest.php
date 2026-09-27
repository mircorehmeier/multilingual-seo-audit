<?php

namespace Tests\Unit;

use App\Services\HtmlAuditor;
use App\Services\UrlGuard;
use PHPUnit\Framework\TestCase;

class HtmlAuditorTest extends TestCase
{
    public function test_content_and_indexability_checks_are_reported_with_useful_severity(): void
    {
        $auditor = new HtmlAuditor(new UrlGuard());
        $title = str_repeat('A', 61);
        $description = str_repeat('B', 170);

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<title>{$title}</title>
<meta name="description" content="{$description}">
<meta name="robots" content="noindex,follow">
<link rel="canonical" href="https://example.com/en/">
<link rel="alternate" hreflang="en" href="https://example.com/en/">
<link rel="alternate" hreflang="zh-Hans" href="https://example.com/zh/">
<link rel="alternate" hreflang="es-419" href="https://example.com/latam/">
</head>
<body>
<h1>Example</h1>
<img src="one.jpg">
<p>Short page content.</p>
</body>
</html>
HTML;

        $page = $auditor->parse('https://example.com/en/', 200, 'text/html', $html);
        $issues = [];
        foreach ($page['issues'] as $issue) {
            $issues[$issue['code']] = $issue['severity'];
        }

        $this->assertSame('info', $issues['title_long']);
        $this->assertSame('info', $issues['description_long']);
        $this->assertSame('info', $issues['robots_noindex']);
        $this->assertSame('warning', $issues['image_alt_missing']);
        $this->assertSame('warning', $issues['hreflang_code_suspicious']);
        $this->assertSame(1, $page['headings']['h1Count']);
        $this->assertSame(1, $page['images']['missingAlt']);
        $this->assertGreaterThan(0, $page['wordCount']);
    }
}
