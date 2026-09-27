<?php

namespace Tests\Unit;

use App\Services\HtmlAuditor;
use App\Services\LanguageDetector;
use App\Services\UrlGuard;
use PHPUnit\Framework\TestCase;

class HtmlAuditorTest extends TestCase
{
    public function test_content_and_indexability_checks_are_reported_with_useful_severity(): void
    {
        $auditor = new HtmlAuditor(new UrlGuard(), new LanguageDetector());
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
    public function test_visible_content_language_mismatch_is_flagged_only_with_confident_detection(): void
    {
        $auditor = new HtmlAuditor(new UrlGuard(), new LanguageDetector());
        $spanish = str_repeat(
            'Este es un texto en español para una página web y contiene información sobre el proyecto, los servicios y las opciones para los usuarios. '.
            'La página explica que el contenido es útil para las personas que buscan más información y que pueden encontrar todo en el sitio. ',
            6,
        );

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<title>Example Spanish content page for testing</title>
<meta name="description" content="A sufficiently complete description for a language detection test page.">
<link rel="canonical" href="https://example.com/es/">
<meta property="og:title" content="Example">
<meta property="og:description" content="Description">
<meta property="og:image" content="https://example.com/image.jpg">
<meta name="twitter:card" content="summary_large_image">
</head>
<body><main><h1>Ejemplo</h1><p>{$spanish}</p></main></body>
</html>
HTML;

        $page = $auditor->parse('https://example.com/es/', 200, 'text/html', $html);
        $codes = array_column($page['issues'], 'code');

        $this->assertSame('es', $page['detectedLang']);
        $this->assertGreaterThanOrEqual(0.42, $page['languageConfidence']);
        $this->assertContains('html_lang_content_mismatch', $codes);
        $this->assertNotNull($page['contentHash']);
    }


    public function test_short_title_and_thin_content_heuristics_avoid_common_false_positives(): void
    {
        $auditor = new HtmlAuditor(new UrlGuard(), new LanguageDetector());

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<title>Contact — Example Brand</title>
<meta name="description" content="Contact Example Brand about products, projects, partnerships and other enquiries using this page.">
<link rel="canonical" href="https://example.com/contact/">
<meta property="og:title" content="Contact — Example Brand">
<meta property="og:description" content="Contact Example Brand about products, projects, partnerships and other enquiries using this page.">
<meta property="og:image" content="https://example.com/image.jpg">
<meta name="twitter:card" content="summary_large_image">
</head>
<body>
<main>
<h1>Contact</h1>
<p>This contact page gives visitors a direct way to get in touch about projects, partnerships, products and general enquiries. It intentionally stays concise while still explaining what the form is for and how it should be used. Visitors can also use the page to ask questions about current work, discuss possible collaborations, or request more information before starting a conversation.</p>
</main>
</body>
</html>
HTML;

        $page = $auditor->parse('https://example.com/contact/', 200, 'text/html', $html);
        $codes = array_column($page['issues'], 'code');

        $this->assertNotContains('title_short', $codes);
        $this->assertNotContains('content_thin', $codes);
    }

}
