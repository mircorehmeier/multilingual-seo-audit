# Multilingual SEO Audit

A lightweight open-source web tool for auditing **multilingual technical SEO**: hreflang, canonical tags, titles, meta descriptions, language declarations, Open Graph/Twitter cards and JSON-LD.

It is deliberately not another opaque “SEO score”. The output is a list of concrete, inspectable signals and issues per URL.

## Why this exists

Multilingual sites fail in ways that generic page checkers often hide: missing self-referencing hreflang, non-reciprocal alternates, inconsistent language declarations, duplicate metadata across translated sections, or canonicals that quietly point somewhere else.

This project focuses on those problems first.

## V1 features

- Same-origin crawl of up to 100 pages
- `hreflang` extraction and self-reference checks
- Reciprocal `hreflang` checks between audited pages
- Duplicate hreflang-code detection
- `<html lang>` extraction
- Canonical tag checks
- Missing and duplicate titles/meta descriptions
- Title/meta display-length **heuristics** (not ranking rules)
- Open Graph metadata checks
- Twitter/X card metadata checks
- JSON-LD parsing and discovered schema types
- Error / warning / info filtering
- URL/title/issue search
- JSON and CSV exports
- Basic SSRF protection for public deployments
- Responsive, dependency-light frontend

## Quick start

Requirements: Node.js 22+

```bash
npm install
npm run dev
```

Open `http://localhost:3000` and enter a public website URL. Use the crawler responsibly on sites you own or are authorized to audit.

Run tests:

```bash
npm test
```

Compile TypeScript:

```bash
npm run build
```

## How checks are interpreted

The tool intentionally separates **technical errors** from **heuristics**.

Examples:

- A missing `<title>` is an error.
- Invalid JSON-LD is an error.
- Missing `rel="canonical"` is a warning.
- A title above 60 characters is a display heuristic, not a claim that Google penalizes it.
- Missing Open Graph fields are informational because they primarily affect sharing previews.

For multilingual pages, Google documents that each language version should list itself and the alternate language versions, and that alternate URLs should be fully qualified. This project uses those concrete relationships rather than inventing a score.

## Security model

The server fetches user-provided public URLs. To reduce SSRF exposure it rejects:

- non-HTTP(S) schemes
- URLs containing credentials
- localhost / `.local` targets
- private and reserved IPv4 ranges
- local/link-local/unique-local IPv6 ranges
- redirects that resolve to blocked targets

Production deployments should additionally use reverse-proxy rate limiting and sensible infrastructure-level timeouts.

## Current limitations

V1 is intentionally small:

- It audits raw server-returned HTML; it does not render JavaScript.
- It does not attempt automatic content-language detection.
- It does not validate every Schema.org property or Google rich-result requirement.
- Reciprocal hreflang checks are limited to URLs included in the current crawl.
- It does not yet parse hreflang from XML sitemaps or HTTP `Link` headers.

Those are good candidates for future versions rather than reasons to make V1 harder to understand.

## Roadmap

### V1.1

- Sitemap discovery/import
- HTTP `Link` header hreflang
- Broken internal link reporting
- robots/noindex visibility report
- stronger hreflang language/region validation

### V2 — assisted fixes

- Suggested title/meta rewrites
- Multilingual metadata consistency suggestions
- Search-intent mismatch hints between translated pages
- Explain-why guidance for each issue

AI-assisted suggestions should remain optional: the deterministic audit must stay useful on its own.

## Project structure

```text
multilingual-seo-audit/
├─ public/
│  ├─ index.html
│  ├─ styles.css
│  └─ app.js
├─ src/
│  ├─ audit.ts
│  └─ server.ts
├─ tests/
│  └─ audit.test.ts
├─ .github/workflows/ci.yml
├─ CONTRIBUTING.md
├─ SECURITY.md
├─ LICENSE
└─ README.md
```

## License

MIT © 2026 Mirco Rehmeier
