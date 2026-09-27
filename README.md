# Multilingual SEO Audit

**Live demo:** https://seo-audit.rehmeier.es/

An open-source multilingual technical SEO auditor built with **Laravel 13** and PHP. Current application version: **0.6.0**.

It crawls a same-origin website and reports concrete technical issues instead of inventing an opaque SEO score.

## What it checks

- `hreflang` extraction
- self-referencing hreflang
- reciprocal hreflang between crawled pages
- hreflang target status, indexability and canonical consistency
- duplicate and suspicious hreflang codes
- `<html lang>`
- canonical tags
- missing and duplicate titles
- missing and duplicate meta descriptions
- title/meta display-length heuristics with deliberately conservative, lower-noise severity rules
- Open Graph metadata
- Twitter/X card metadata
- JSON-LD validity and discovered schema types
- robots.txt discovery plus Googlebot Allow/Disallow evaluation
- XML sitemap discovery including sitemap hreflang
- meta robots and X-Robots-Tag noindex reporting
- explicit technical indexability status with a human-readable reason
- H1 structure, visible-word heuristic and missing image alt attributes
- conservative visible-content language detection vs. `<html lang>`
- exact main-body duplicate-content groups
- broken internal links when their targets are included in the crawl
- internal links that unnecessarily pass through redirects
- redirect chains and temporary redirects
- same-host mixed-scheme/port links
- sitemap crawl coverage and orphan-page candidates when coverage is complete
- sitemap URLs that redirect, are noindex, or canonicalize elsewhere
- canonical target status/indexability checks
- HTTP/fetch failures
- CSV and JSON exports
- self-describing CSV exports with audit timestamp, engine version, social metadata, heading counts and JSON-LD diagnostics

## Tech stack

- PHP 8.4+
- Laravel 13
- Laravel HTTP client / Guzzle
- native PHP DOMDocument + DOMXPath
- vanilla JavaScript and CSS
- PHPUnit
- GitHub Actions

No database is required for V1.

## Local setup

Requirements: PHP 8.4+ and Composer 2.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Open `http://127.0.0.1:8000`.

Run tests:

```bash
php artisan test
```

## API

### Health

```text
GET /api/health
```

### Audit

```text
POST /api/audit
Content-Type: application/json
```

Example:

```json
{
  "url": "https://example.com",
  "maxPages": 25
}
```

The crawler accepts public HTTP/HTTPS targets only and limits each audit to 100 pages.

## Security

Because the application fetches user-provided URLs, SSRF protection is part of the core design.

Targets are rejected when they:

- use a non-HTTP(S) scheme
- contain URL credentials
- use localhost/local/internal hostnames
- resolve to private or reserved IPv4/IPv6 addresses

Redirect targets are validated again before they are fetched.

The public API is also rate-limited.

## Plesk deployment

The production repository is deployed to:

```text
/seo-audit
```

and the domain document root must be:

```text
/seo-audit/public
```

The repository includes `deploy.sh`. Recommended Plesk Git additional deployment action:

```bash
sh "$HOME/seo-audit/deploy.sh" > "$HOME/seo-audit/deploy.log" 2>&1
```

The script:

1. finds a compatible Plesk PHP 8.4.1+ binary
2. runs Composer install with an optimized production autoloader
3. creates/preserves the Laravel `.env`
4. creates `APP_KEY` if necessary
5. clears stale Laravel caches
6. caches configuration and Blade views
7. writes the release version and deployed Git commit to `public/deploy-status.txt`

There is **no Node.js/Passenger runtime** in the Laravel version.

## CI/CD

Every push to `main` is tested on PHP 8.4 and 8.5.

After the test job succeeds, GitHub Actions promotes the exact tested commit to the `production` branch. Plesk can watch that branch through its Git webhook and deploy it automatically.

```text
main
  ↓
GitHub Actions
  ↓ tests pass
production
  ↓
Plesk Git
  ↓
Composer + Laravel deployment
```

## Current limitations

- raw server-returned HTML only; JavaScript rendering is not included
- hreflang in XML sitemaps and HTTP `Link` headers is not yet parsed
- content-language detection is intentionally conservative and currently covers EN, DE, ES, PT, FR, IT and NL
- reciprocal hreflang and link-target checks cover URLs present in the current crawl
- sitemap discovery is capped at 8 sitemap files and 1,000 discovered URLs per audit
- structured-data checks validate JSON and discover types, but do not yet validate every rich-result requirement

## Roadmap

### V1.1

- hreflang extraction from XML sitemaps and HTTP `Link` headers
- broader automated tests for ISO language/region edge cases
- shareable audit reports

### V2

- optional AI-assisted title/meta suggestions
- multilingual metadata consistency suggestions
- issue explanations and suggested fixes
- scheduled audits

## License

MIT © 2026 Mirco Rehmeier
