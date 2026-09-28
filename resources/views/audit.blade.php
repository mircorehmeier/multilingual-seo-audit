<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Multilingual SEO Audit</title>
  <meta name="description" content="Audit hreflang, canonicals, indexability, metadata, sitemaps, links, headings, images and structured data across multilingual websites.">
  <meta name="color-scheme" content="light dark">
  <link rel="stylesheet" href="/styles.css?v={{ config('audit.version') }}">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/" aria-label="Multilingual SEO Audit home">
      <span class="brand-mark">M</span>
      <span>Multilingual SEO Audit</span>
      <span class="version-badge">v{{ config('audit.version') }}</span>
    </a>
    <a class="github-link" href="https://github.com/mircorehmeier/multilingual-seo-audit" target="_blank" rel="noreferrer">GitHub ↗</a>
  </header>

  <main>
    <section class="hero">
      <p class="eyebrow">Technical SEO · EN / ES / DE and beyond</p>
      <h1>Find multilingual SEO problems before search engines do.</h1>
      <p class="intro">Crawl a website and inspect hreflang, canonicals, indexability, redirects, duplicate content, sitemap coverage, orphan candidates, internal links, metadata and structured data — without a black-box SEO score.</p>

      <form id="audit-form" class="audit-form">
        <label class="url-field">
          <span>Website URL</span>
          <input id="url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="https://example.com" required>
        </label>
        <label class="pages-field">
          <span>Max pages</span>
          <select id="max-pages" name="maxPages">
            <option value="10">10</option>
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </label>
        <button id="run-button" type="submit">Run audit</button>
      </form>
      <p class="form-note">Same-origin crawl · Sitemap-assisted discovery · Public HTTP/HTTPS targets · Maximum 100 pages</p>
      <div id="status" class="status" aria-live="polite"></div>
    </section>

    <section id="results" class="results" hidden>
      <div class="results-heading">
        <div>
          <p class="eyebrow">Audit results</p>
          <h2 id="result-domain">—</h2>
          <p id="result-meta" class="muted">—</p>
        </div>
        <div class="actions">
          <button id="download-json" class="secondary" type="button">Export JSON</button>
          <button id="download-csv" class="secondary" type="button">Export CSV</button>
        </div>
      </div>

      <div id="summary" class="summary-grid" aria-label="Audit summary"></div>
      <div id="summary-detail" class="summary-detail" hidden aria-live="polite"></div>

      <div id="site-diagnostics" class="site-diagnostics" hidden>
        <div><strong>Site-level checks</strong><span id="site-diagnostics-meta" class="muted"></span></div>
        <div id="site-issues-list" class="site-issues-list"></div>
      </div>

      <div class="toolbar">
        <label>
          <span class="sr-only">Filter issues</span>
          <select id="severity-filter">
            <option value="all">All severities</option>
            <option value="error">Errors</option>
            <option value="warning">Warnings</option>
            <option value="info">Info</option>
          </select>
        </label>
        <label class="search-field">
          <span class="sr-only">Search audited pages</span>
          <input id="table-search" type="search" placeholder="Filter by URL, title or issue…">
        </label>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Page</th>
              <th>Lang</th>
              <th>Hreflang</th>
              <th>Canonical</th>
              <th>Indexability</th>
              <th>Issues</th>
            </tr>
          </thead>
          <tbody id="results-body"></tbody>
        </table>
      </div>
    </section>

    <section class="explain">
      <div>
        <p class="eyebrow">What it checks</p>
        <h2>Built for multilingual sites, with fewer false positives.</h2>
      </div>
      <div class="check-grid">
        <article><h3>Hreflang</h3><p>HTML, XML sitemap and HTTP-header alternates, self references, reciprocal targets, indexability and canonical consistency.</p></article>
        <article><h3>Metadata</h3><p>Missing and duplicate titles/descriptions with language-aware duplicate handling and low-severity display heuristics.</p></article>
        <article><h3>Canonical</h3><p>Missing, invalid or multiple canonical tags and pages that canonicalize elsewhere.</p></article>
        <article><h3>Social</h3><p>Open Graph and Twitter/X metadata plus image URL, status, content type and detectable dimensions.</p></article>
        <article><h3>Sitemaps & links</h3><p>robots.txt rules, sitemap discovery, crawl coverage, orphan candidates, crawl depth, contextual links and independent checks for uncrawled link targets.</p></article>
        <article><h3>Structured data</h3><p>JSON-LD syntax parsing, discovered schema types and cautious semantic checks for common rich-result structures.</p></article>
        <article><h3>Content quality</h3><p>Exact body-content duplicates, conservative language detection, H1 structure, thin-content heuristics and missing image alt attributes.</p></article>
        <article><h3>Redirects</h3><p>Redirect chains, temporary redirects, sitemap redirects and same-host links using a different scheme or port.</p></article>
      </div>
    </section>
  </main>

  <footer>
    <p>Open-source project by <a href="https://rehmeier.es/" target="_blank" rel="noreferrer">Mirco Rehmeier</a>.</p>
    <p>Length and thin-content checks are heuristics, not Google ranking rules.</p>
  </footer>

  <template id="page-row-template">
    <tr>
      <td class="page-cell"></td>
      <td class="lang-cell"></td>
      <td class="hreflang-cell"></td>
      <td class="canonical-cell"></td>
      <td class="indexability-cell"></td>
      <td class="issues-cell"></td>
    </tr>
  </template>

  <script type="module" src="/app.js?v={{ config('audit.version') }}"></script>
</body>
</html>
