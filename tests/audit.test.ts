import { describe, expect, it } from 'vitest';
import { normalizeUrl, parseHtml } from '../src/audit.js';

describe('normalizeUrl', () => {
  it('removes fragments and common tracking parameters', () => {
    expect(normalizeUrl('https://Example.com/page?utm_source=x&id=7#section'))
      .toBe('https://example.com/page?id=7');
  });
});

describe('parseHtml', () => {
  it('extracts multilingual SEO metadata', () => {
    const html = `<!doctype html>
      <html lang="en">
        <head>
          <title>A useful multilingual SEO page title for testing</title>
          <meta name="description" content="This is a sufficiently descriptive meta description used to test extraction from a multilingual SEO page without relying on a browser.">
          <link rel="canonical" href="https://example.com/en/page/">
          <link rel="alternate" hreflang="en" href="https://example.com/en/page/">
          <link rel="alternate" hreflang="es" href="https://example.com/es/pagina/">
          <meta property="og:title" content="Open Graph title">
          <meta property="og:description" content="Open Graph description">
          <meta property="og:image" content="/image.jpg">
          <meta name="twitter:card" content="summary_large_image">
          <script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>
        </head>
        <body><a href="/en/other/?utm_source=test">Other</a></body>
      </html>`;

    const page = parseHtml('https://example.com/en/page/', 200, 'text/html', html);
    expect(page.lang).toBe('en');
    expect(page.canonical).toBe('https://example.com/en/page/');
    expect(page.hreflangs).toHaveLength(2);
    expect(page.openGraph.image).toBe('https://example.com/image.jpg');
    expect(page.structuredData.types).toContain('WebPage');
    expect(page.links).toContain('https://example.com/en/other/');
    expect(page.issues.some((issue) => issue.code === 'hreflang_self_missing')).toBe(false);
  });

  it('flags missing metadata and invalid JSON-LD', () => {
    const html = '<html><head><script type="application/ld+json">{broken}</script></head><body></body></html>';
    const page = parseHtml('https://example.com/', 200, 'text/html', html);
    const codes = page.issues.map((issue) => issue.code);
    expect(codes).toContain('title_missing');
    expect(codes).toContain('description_missing');
    expect(codes).toContain('canonical_missing');
    expect(codes).toContain('html_lang_missing');
    expect(codes).toContain('jsonld_invalid');
  });
});
