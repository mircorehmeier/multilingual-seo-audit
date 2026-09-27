import * as cheerio from 'cheerio';
import dns from 'node:dns/promises';
import net from 'node:net';

export type Severity = 'error' | 'warning' | 'info';

export interface AuditIssue {
  code: string;
  severity: Severity;
  message: string;
}

export interface HreflangEntry {
  lang: string;
  href: string;
}

export interface PageAudit {
  url: string;
  status: number;
  contentType: string;
  title: string;
  description: string;
  canonical: string | null;
  lang: string | null;
  robots: string | null;
  hreflangs: HreflangEntry[];
  openGraph: {
    title: string | null;
    description: string | null;
    image: string | null;
    url: string | null;
  };
  twitter: {
    card: string | null;
    title: string | null;
    description: string | null;
    image: string | null;
  };
  structuredData: {
    scripts: number;
    valid: number;
    invalid: number;
    types: string[];
  };
  issues: AuditIssue[];
  links: string[];
}

export interface AuditResult {
  startUrl: string;
  origin: string;
  auditedAt: string;
  maxPages: number;
  pages: PageAudit[];
  summary: {
    pages: number;
    errors: number;
    warnings: number;
    info: number;
    languages: string[];
    hreflangCodes: string[];
  };
}

const USER_AGENT = 'MultilingualSEOAudit/0.1 (+https://github.com/mircorehmeier/multilingual-seo-audit)';
const TRACKING_PARAMS = new Set([
  'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid',
]);
const SKIP_EXTENSIONS = /\.(?:jpg|jpeg|png|gif|webp|svg|avif|ico|pdf|zip|rar|7z|gz|mp4|mp3|wav|mov|avi|webm|css|js|mjs|xml|json|txt|woff2?|ttf|eot)(?:$|\?)/i;

function addIssue(page: PageAudit, code: string, severity: Severity, message: string) {
  if (!page.issues.some((issue) => issue.code === code && issue.message === message)) {
    page.issues.push({ code, severity, message });
  }
}

function textContent(value: string | undefined): string {
  return (value ?? '').replace(/\s+/g, ' ').trim();
}

export function normalizeUrl(input: string, base?: string): string {
  const url = base ? new URL(input, base) : new URL(input);
  url.hash = '';
  for (const key of [...url.searchParams.keys()]) {
    if (key.toLowerCase().startsWith('utm_') || TRACKING_PARAMS.has(key.toLowerCase())) {
      url.searchParams.delete(key);
    }
  }
  url.hostname = url.hostname.toLowerCase();
  if ((url.protocol === 'https:' && url.port === '443') || (url.protocol === 'http:' && url.port === '80')) {
    url.port = '';
  }
  return url.toString();
}

function isPrivateIpv4(address: string): boolean {
  const p = address.split('.').map(Number);
  if (p.length !== 4 || p.some((n) => Number.isNaN(n))) return true;
  const [a, b] = p;
  return (
    a === 0 ||
    a === 10 ||
    a === 127 ||
    (a === 169 && b === 254) ||
    (a === 172 && b >= 16 && b <= 31) ||
    (a === 192 && b === 168) ||
    (a === 100 && b >= 64 && b <= 127) ||
    a >= 224
  );
}

function isPrivateIp(address: string): boolean {
  const type = net.isIP(address);
  if (type === 4) return isPrivateIpv4(address);
  if (type === 6) {
    const lower = address.toLowerCase();
    if (lower === '::1' || lower === '::') return true;
    if (lower.startsWith('fe8') || lower.startsWith('fe9') || lower.startsWith('fea') || lower.startsWith('feb')) return true;
    if (lower.startsWith('fc') || lower.startsWith('fd')) return true;
    if (lower.startsWith('::ffff:')) {
      const mapped = lower.slice(7);
      if (net.isIP(mapped) === 4) return isPrivateIpv4(mapped);
    }
    return false;
  }
  return true;
}

async function assertPublicHttpUrl(input: string): Promise<URL> {
  const url = new URL(input);
  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error('Only http:// and https:// URLs are supported.');
  }
  if (url.username || url.password) {
    throw new Error('URLs containing credentials are not allowed.');
  }
  const host = url.hostname.toLowerCase();
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local')) {
    throw new Error('Local or private network targets are not allowed.');
  }
  if (net.isIP(host)) {
    if (isPrivateIp(host)) throw new Error('Private or reserved IP targets are not allowed.');
    return url;
  }
  const addresses = await dns.lookup(host, { all: true, verbatim: true });
  if (!addresses.length || addresses.some(({ address }) => isPrivateIp(address))) {
    throw new Error('The hostname resolves to a private or reserved network address.');
  }
  return url;
}

async function safeFetch(input: string, init: RequestInit = {}, redirectLimit = 5): Promise<Response> {
  let current = (await assertPublicHttpUrl(input)).toString();
  for (let i = 0; i <= redirectLimit; i += 1) {
    const response = await fetch(current, {
      ...init,
      redirect: 'manual',
      headers: {
        'user-agent': USER_AGENT,
        'accept': 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
        ...(init.headers ?? {}),
      },
      signal: AbortSignal.timeout(8_000),
    });
    if ([301, 302, 303, 307, 308].includes(response.status)) {
      const location = response.headers.get('location');
      if (!location) return response;
      current = normalizeUrl(location, current);
      await assertPublicHttpUrl(current);
      continue;
    }
    return response;
  }
  throw new Error('Too many redirects.');
}

function getMeta($: cheerio.CheerioAPI, name: string): string | null {
  const value = $(`meta[name="${name}"]`).first().attr('content');
  return value ? textContent(value) : null;
}

function getProperty($: cheerio.CheerioAPI, property: string): string | null {
  const value = $(`meta[property="${property}"]`).first().attr('content');
  return value ? textContent(value) : null;
}

function absoluteOrNull(value: string | undefined, base: string): string | null {
  if (!value) return null;
  try {
    return normalizeUrl(value, base);
  } catch {
    return null;
  }
}

function collectStructuredData($: cheerio.CheerioAPI) {
  let valid = 0;
  let invalid = 0;
  const types = new Set<string>();
  const scripts = $('script[type="application/ld+json"]');

  scripts.each((_, element) => {
    const raw = $(element).text().trim();
    if (!raw) {
      invalid += 1;
      return;
    }
    try {
      const parsed = JSON.parse(raw);
      valid += 1;
      const visit = (node: unknown) => {
        if (Array.isArray(node)) {
          node.forEach(visit);
        } else if (node && typeof node === 'object') {
          const obj = node as Record<string, unknown>;
          const type = obj['@type'];
          if (typeof type === 'string') types.add(type);
          if (Array.isArray(type)) type.filter((x): x is string => typeof x === 'string').forEach((x) => types.add(x));
          if (Array.isArray(obj['@graph'])) visit(obj['@graph']);
        }
      };
      visit(parsed);
    } catch {
      invalid += 1;
    }
  });

  return { scripts: scripts.length, valid, invalid, types: [...types].sort() };
}

export function parseHtml(url: string, status: number, contentType: string, html: string): PageAudit {
  const $ = cheerio.load(html);
  const title = textContent($('title').first().text());
  const description = getMeta($, 'description') ?? '';
  const canonicalElements = $('link[rel~="canonical"]');
  const canonical = absoluteOrNull(canonicalElements.first().attr('href'), url);
  const lang = textContent($('html').attr('lang')) || null;
  const robots = getMeta($, 'robots');

  const hreflangs: HreflangEntry[] = [];
  $('link[rel~="alternate"][hreflang]').each((_, element) => {
    const code = textContent($(element).attr('hreflang')).toLowerCase();
    const href = absoluteOrNull($(element).attr('href'), url);
    if (code && href) hreflangs.push({ lang: code, href });
  });

  const links = new Set<string>();
  $('a[href]').each((_, element) => {
    const href = $(element).attr('href');
    if (!href || /^(?:mailto:|tel:|javascript:|data:)/i.test(href)) return;
    try {
      const normalized = normalizeUrl(href, url);
      if (!SKIP_EXTENSIONS.test(normalized)) links.add(normalized);
    } catch {
      // Invalid links are ignored by the crawler. They can be added as a separate check in V2.
    }
  });

  const page: PageAudit = {
    url,
    status,
    contentType,
    title,
    description,
    canonical,
    lang,
    robots,
    hreflangs,
    openGraph: {
      title: getProperty($, 'og:title'),
      description: getProperty($, 'og:description'),
      image: absoluteOrNull(getProperty($, 'og:image') ?? undefined, url),
      url: absoluteOrNull(getProperty($, 'og:url') ?? undefined, url),
    },
    twitter: {
      card: getMeta($, 'twitter:card'),
      title: getMeta($, 'twitter:title'),
      description: getMeta($, 'twitter:description'),
      image: absoluteOrNull(getMeta($, 'twitter:image') ?? undefined, url),
    },
    structuredData: collectStructuredData($),
    issues: [],
    links: [...links],
  };

  // Length checks are intentionally heuristics, not Google ranking rules.
  if (!page.title) addIssue(page, 'title_missing', 'error', 'Missing <title>.');
  else if (page.title.length < 30) addIssue(page, 'title_short', 'info', `Title is ${page.title.length} characters (short display heuristic).`);
  else if (page.title.length > 60) addIssue(page, 'title_long', 'warning', `Title is ${page.title.length} characters (long display heuristic).`);

  if (!page.description) addIssue(page, 'description_missing', 'warning', 'Missing meta description.');
  else if (page.description.length < 70) addIssue(page, 'description_short', 'info', `Meta description is ${page.description.length} characters (short display heuristic).`);
  else if (page.description.length > 160) addIssue(page, 'description_long', 'warning', `Meta description is ${page.description.length} characters (long display heuristic).`);

  if (canonicalElements.length === 0) addIssue(page, 'canonical_missing', 'warning', 'Missing rel="canonical".');
  if (canonicalElements.length > 1) addIssue(page, 'canonical_multiple', 'error', `Found ${canonicalElements.length} canonical tags.`);
  if (canonicalElements.length > 0 && !canonical) addIssue(page, 'canonical_invalid', 'error', 'Canonical URL is invalid or empty.');
  if (page.canonical && page.canonical !== normalizeUrl(url)) addIssue(page, 'canonical_other', 'info', `Canonical points to ${page.canonical}.`);

  if (!page.lang) addIssue(page, 'html_lang_missing', 'warning', 'Missing html lang attribute.');

  if (!page.openGraph.title) addIssue(page, 'og_title_missing', 'info', 'Missing og:title.');
  if (!page.openGraph.description) addIssue(page, 'og_description_missing', 'info', 'Missing og:description.');
  if (!page.openGraph.image) addIssue(page, 'og_image_missing', 'info', 'Missing or invalid og:image.');
  if (!page.twitter.card) addIssue(page, 'twitter_card_missing', 'info', 'Missing twitter:card.');

  if (page.structuredData.invalid > 0) {
    addIssue(page, 'jsonld_invalid', 'error', `${page.structuredData.invalid} JSON-LD block(s) contain invalid JSON.`);
  }

  if (page.hreflangs.length > 0) {
    const self = normalizeUrl(url);
    const selfRefs = page.hreflangs.filter((entry) => normalizeUrl(entry.href) === self);
    if (selfRefs.length === 0) addIssue(page, 'hreflang_self_missing', 'warning', 'Hreflang set does not include a self-reference.');

    const seen = new Set<string>();
    for (const entry of page.hreflangs) {
      if (seen.has(entry.lang)) addIssue(page, 'hreflang_duplicate_code', 'warning', `Duplicate hreflang code: ${entry.lang}.`);
      seen.add(entry.lang);
      if (entry.lang !== 'x-default' && !/^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(entry.lang)) {
        addIssue(page, 'hreflang_code_suspicious', 'warning', `Suspicious hreflang code: ${entry.lang}.`);
      }
    }
  }

  return page;
}

function addCrossPageChecks(pages: PageAudit[]) {
  const byUrl = new Map(pages.map((page) => [normalizeUrl(page.url), page]));
  const titleMap = new Map<string, PageAudit[]>();
  const descriptionMap = new Map<string, PageAudit[]>();

  for (const page of pages) {
    if (page.title) {
      const key = page.title.toLocaleLowerCase();
      titleMap.set(key, [...(titleMap.get(key) ?? []), page]);
    }
    if (page.description) {
      const key = page.description.toLocaleLowerCase();
      descriptionMap.set(key, [...(descriptionMap.get(key) ?? []), page]);
    }
  }

  for (const group of titleMap.values()) {
    if (group.length > 1) group.forEach((page) => addIssue(page, 'title_duplicate', 'warning', `Duplicate title across ${group.length} audited pages.`));
  }
  for (const group of descriptionMap.values()) {
    if (group.length > 1) group.forEach((page) => addIssue(page, 'description_duplicate', 'warning', `Duplicate meta description across ${group.length} audited pages.`));
  }

  for (const page of pages) {
    const sourceUrl = normalizeUrl(page.url);
    for (const alternate of page.hreflangs) {
      const target = byUrl.get(normalizeUrl(alternate.href));
      if (!target || normalizeUrl(alternate.href) === sourceUrl) continue;
      const reciprocal = target.hreflangs.some((entry) => normalizeUrl(entry.href) === sourceUrl);
      if (!reciprocal) {
        addIssue(page, 'hreflang_not_reciprocal', 'warning', `${alternate.lang} alternate does not link back from ${target.url}.`);
      }
    }
  }
}

export async function auditSite(startInput: string, requestedMaxPages = 25): Promise<AuditResult> {
  const startUrl = normalizeUrl(startInput.match(/^https?:\/\//i) ? startInput : `https://${startInput}`);
  await assertPublicHttpUrl(startUrl);
  let crawlOrigin = new URL(startUrl).origin;
  const maxPages = Math.max(1, Math.min(100, Math.floor(requestedMaxPages || 25)));
  const queue: string[] = [startUrl];
  const queued = new Set<string>(queue);
  const visited = new Set<string>();
  const pages: PageAudit[] = [];

  const concurrency = 6;

  while (queue.length > 0 && pages.length < maxPages) {
    const batch: string[] = [];
    while (queue.length > 0 && batch.length < concurrency && pages.length + batch.length < maxPages) {
      const candidate = queue.shift()!;
      if (visited.has(candidate)) continue;
      visited.add(candidate);
      batch.push(candidate);
    }
    if (batch.length === 0) continue;

    const batchResults = await Promise.all(batch.map(async (url): Promise<PageAudit | null> => {
      try {
        const response = await safeFetch(url);
        const finalUrl = normalizeUrl(response.url || url);
        const contentType = response.headers.get('content-type') ?? '';
        if (!contentType.toLowerCase().includes('text/html') && !contentType.toLowerCase().includes('application/xhtml+xml')) {
          return null;
        }
        const html = await response.text();
        const page = parseHtml(finalUrl, response.status, contentType, html);
        if (response.status >= 400) addIssue(page, 'http_error', 'error', `HTTP ${response.status}.`);
        else if (response.status >= 300) addIssue(page, 'http_redirect', 'warning', `HTTP ${response.status}.`);
        return page;
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Request failed.';
        return {
          url,
          status: 0,
          contentType: '',
          title: '',
          description: '',
          canonical: null,
          lang: null,
          robots: null,
          hreflangs: [],
          openGraph: { title: null, description: null, image: null, url: null },
          twitter: { card: null, title: null, description: null, image: null },
          structuredData: { scripts: 0, valid: 0, invalid: 0, types: [] },
          issues: [{ code: 'fetch_failed', severity: 'error', message }],
          links: [],
        };
      }
    }));

    for (const page of batchResults) {
      if (!page || pages.length >= maxPages) continue;
      if (pages.length === 0) crawlOrigin = new URL(page.url).origin;
      pages.push(page);

      for (const link of page.links) {
        try {
          const parsed = new URL(link);
          if (parsed.origin !== crawlOrigin || queued.has(link) || visited.has(link)) continue;
          queued.add(link);
          queue.push(link);
        } catch {
          // Ignore invalid links.
        }
      }
    }
  }

  addCrossPageChecks(pages);

  const counts = { error: 0, warning: 0, info: 0 };
  const languages = new Set<string>();
  const hreflangCodes = new Set<string>();
  for (const page of pages) {
    if (page.lang) languages.add(page.lang.toLowerCase());
    page.hreflangs.forEach((entry) => hreflangCodes.add(entry.lang));
    page.issues.forEach((issue) => { counts[issue.severity] += 1; });
  }

  return {
    startUrl,
    origin: crawlOrigin,
    auditedAt: new Date().toISOString(),
    maxPages,
    pages,
    summary: {
      pages: pages.length,
      errors: counts.error,
      warnings: counts.warning,
      info: counts.info,
      languages: [...languages].sort(),
      hreflangCodes: [...hreflangCodes].sort(),
    },
  };
}
