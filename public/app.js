const form = document.querySelector('#audit-form');
const urlInput = document.querySelector('#url');
const maxPagesInput = document.querySelector('#max-pages');
const runButton = document.querySelector('#run-button');
const status = document.querySelector('#status');
const results = document.querySelector('#results');
const resultDomain = document.querySelector('#result-domain');
const resultMeta = document.querySelector('#result-meta');
const summary = document.querySelector('#summary');
const summaryDetail = document.querySelector('#summary-detail');
const siteDiagnostics = document.querySelector('#site-diagnostics');
const siteDiagnosticsMeta = document.querySelector('#site-diagnostics-meta');
const siteIssuesList = document.querySelector('#site-issues-list');
const resultsBody = document.querySelector('#results-body');
const severityFilter = document.querySelector('#severity-filter');
const tableSearch = document.querySelector('#table-search');
const downloadJson = document.querySelector('#download-json');
const downloadCsv = document.querySelector('#download-csv');
const rowTemplate = document.querySelector('#page-row-template');
let latestResult = null;

function setStatus(message, isError = false) {
  status.textContent = message;
  status.classList.toggle('error', isError);
}

const SUMMARY_HELP = {
  pages: 'Unique final HTML pages included in this audit after redirect-target deduplication.',
  errors: 'High-priority technical problems found at site or page level.',
  warnings: 'Actionable issues worth reviewing; these are more important than informational heuristics.',
  info: 'Low-severity observations and display/content heuristics that are not necessarily SEO problems.',
  languages: 'Distinct declared HTML language codes found across audited pages.',
  sitemapUrls: 'URLs discovered from parsed XML sitemaps. Open this metric to see crawl coverage.',
  indexablePages: 'Audited pages that are technically indexable based on HTTP status, robots directives and canonical checks.',
  noindexPages: 'Audited pages explicitly marked noindex by meta robots or X-Robots-Tag.',
  redirects: 'Requested URLs that redirected before reaching their final page. This count is redirecting entry URLs, not redirect hops.',
  orphanCandidates: 'Sitemap-listed pages with no internal anchor links from other audited pages. Reported only when sitemap coverage is complete.',
  duplicateContentGroups: 'Groups of indexable audited pages with the same exact main-content fingerprint.',
  robotsBlockedPages: 'Audited URLs blocked for Googlebot by matching robots.txt rules.',
  linkTargetsChecked: 'Additional internal link targets checked outside the normal page crawl quota, mainly to catch hidden 4xx/redirect targets.',
  contextualLinks: 'Unique internal links found inside main/article content, excluding navigation and footer links.',
  maxCrawlDepth: 'Highest internal-link click depth reached from the first audited final page, where depth 0 is the starting page.',
  socialImagesChecked: 'Unique Open Graph or Twitter/X image URLs fetched to verify status, content type and detectable dimensions.',
};

function summaryCard(key, label, value, type = '') {
  const description = SUMMARY_HELP[key] || '';
  return `<button type="button" class="summary-card ${type}" data-summary-key="${key}" title="${escapeHtml(description)}" aria-expanded="false">
    <span class="summary-card-label">${escapeHtml(label)} <span class="summary-help" aria-hidden="true">?</span></span>
    <strong>${escapeHtml(String(value))}</strong>
  </button>`;
}

function escapeHtml(value = '') {
  return value.replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
}

function issueHtml(issue) {
  return `<div class="issue-item"><span class="pill ${issue.severity}">${issue.severity}</span>${escapeHtml(issue.message)}</div>`;
}

function linkHtml(url, label = url) {
  return `<a href="${escapeHtml(url)}" target="_blank" rel="noreferrer">${escapeHtml(label)}</a>`;
}

function detailList(items, emptyMessage = 'None found.') {
  if (!items.length) return `<p class="muted">${escapeHtml(emptyMessage)}</p>`;
  const limit = 50;
  const visible = items.slice(0, limit);
  const extra = items.length > limit ? `<p class="muted">Showing first ${limit} of ${items.length}.</p>` : '';
  return `<ul class="summary-detail-list">${visible.map((item) => `<li>${item}</li>`).join('')}</ul>${extra}`;
}

function collectIssues(result, severity) {
  const items = [];
  for (const issue of result.siteIssues || []) {
    if (issue.severity === severity) items.push(`<strong>Site:</strong> ${escapeHtml(issue.message)}`);
  }
  for (const page of result.pages || []) {
    for (const issue of page.issues || []) {
      if (issue.severity === severity) {
        items.push(`${linkHtml(page.url)} — ${escapeHtml(issue.message)}`);
      }
    }
  }
  return items;
}

function noindexPage(page) {
  return (page.issues || []).some((issue) => ['robots_noindex', 'x_robots_noindex'].includes(issue.code));
}

function summaryDetailContent(key, result) {
  const pages = result.pages || [];
  const site = result.site || {};
  const summaryData = result.summary || {};
  let title = '';
  let description = SUMMARY_HELP[key] || '';
  let body = '';

  if (key === 'pages') {
    title = `Pages · ${summaryData.pages ?? pages.length}`;
    body = detailList(pages.map((page) => linkHtml(page.url)), 'No HTML pages were audited.');
  } else if (['errors', 'warnings', 'info'].includes(key)) {
    const severity = key === 'errors' ? 'error' : key === 'warnings' ? 'warning' : 'info';
    const count = summaryData[key] ?? 0;
    title = `${key[0].toUpperCase() + key.slice(1)} · ${count}`;
    body = detailList(collectIssues(result, severity), `No ${key} were found.`);
  } else if (key === 'languages') {
    const languages = summaryData.languages || [];
    title = `Languages · ${languages.length}`;
    body = detailList(languages.map((lang) => {
      const count = pages.filter((page) => String(page.lang || '').toLowerCase() === String(lang).toLowerCase()).length;
      return `<strong>${escapeHtml(lang)}</strong> — ${count} page${count === 1 ? '' : 's'}`;
    }), 'No declared HTML languages were detected.');
  } else if (key === 'sitemapUrls') {
    const coverage = site.crawlCoverage || {};
    title = `Sitemap URLs · ${summaryData.sitemapUrls ?? 0}`;
    const sitemapItems = (site.sitemaps || []).map((url) => linkHtml(url));
    body = `<p><strong>Coverage:</strong> ${coverage.audited ?? 0} of ${coverage.sitemapUrls ?? summaryData.sitemapUrls ?? 0} sitemap URLs audited${coverage.percent == null ? '' : ` (${coverage.percent}%)`}.</p>` +
      detailList(sitemapItems, 'No readable XML sitemap was detected.');
  } else if (key === 'indexablePages') {
    const matching = pages.filter((page) => page.indexability?.status === 'indexable');
    title = `Indexable pages · ${summaryData.indexablePages ?? matching.length}`;
    body = detailList(matching.map((page) => linkHtml(page.url)), 'No audited pages are currently classified as indexable.');
  } else if (key === 'noindexPages') {
    const matching = pages.filter(noindexPage);
    title = `Noindex pages · ${summaryData.noindexPages ?? matching.length}`;
    body = detailList(matching.map((page) => {
      const directive = page.xRobotsTag || page.robots || 'noindex';
      return `${linkHtml(page.url)} — ${escapeHtml(directive)}`;
    }), 'No audited pages explicitly use noindex.');
  } else if (key === 'redirects') {
    const redirects = site.redirects || [];
    title = `Redirects · ${summaryData.redirects ?? redirects.length}`;
    body = detailList(redirects.map((entry) => {
      const chain = entry.chain || [];
      const hops = chain.map((hop) => `${hop.status} ${escapeHtml(hop.from)} → ${escapeHtml(hop.to)}`).join(' · ');
      return `${linkHtml(entry.requestedUrl)} → ${linkHtml(entry.finalUrl)} <span class="muted">(${chain.length} hop${chain.length === 1 ? '' : 's'})</span>${hops ? `<div class="summary-subdetail">${hops}</div>` : ''}`;
    }), 'No redirecting entry URLs were encountered.');
  } else if (key === 'orphanCandidates') {
    const matching = pages.filter((page) => (page.issues || []).some((issue) => issue.code === 'orphan_candidate'));
    title = `Orphan candidates · ${summaryData.orphanCandidates ?? matching.length}`;
    body = detailList(matching.map((page) => linkHtml(page.url)), 'No orphan candidates were found, or sitemap coverage was not complete enough to make that conclusion.');
  } else if (key === 'duplicateContentGroups') {
    const matching = pages.filter((page) => (page.issues || []).some((issue) => issue.code === 'content_duplicate' || issue.code === 'content_duplicate_multilingual'));
    title = `Duplicate groups · ${summaryData.duplicateContentGroups ?? 0}`;
    body = detailList(matching.map((page) => linkHtml(page.url)), 'No exact main-content duplicate groups were found.');
  } else if (key === 'robotsBlockedPages') {
    const matching = pages.filter((page) => page.robotsTxt?.allowed === false);
    title = `Robots blocked · ${summaryData.robotsBlockedPages ?? matching.length}`;
    body = detailList(matching.map((page) => `${linkHtml(page.url)} — matched ${escapeHtml(page.robotsTxt?.matchedDirective || 'disallow')}: ${escapeHtml(page.robotsTxt?.matchedRule || '')}`), 'No audited pages are blocked for Googlebot by robots.txt.');
  } else if (key === 'linkTargetsChecked') {
    const checks = site.linkTargetChecks || [];
    title = `Checked link targets · ${summaryData.linkTargetsChecked ?? checks.length}`;
    body = detailList(checks.map((check) => {
      const status = check.status || 'failed';
      const final = check.finalUrl && check.finalUrl !== check.url ? ` → ${linkHtml(check.finalUrl)}` : '';
      const error = check.error ? ` — ${escapeHtml(check.error)}` : '';
      return `${linkHtml(check.url)} — HTTP ${escapeHtml(String(status))}${final}${error}`;
    }), 'No extra internal targets needed checking outside the main crawl.');
  } else if (key === 'contextualLinks') {
    const noContextualIncoming = pages.filter((page) =>
      page.indexability?.status === 'indexable' &&
      page.inSitemap === true &&
      (page.internalLinks?.crawlDepth ?? 0) > 0 &&
      (page.internalLinks?.contextualIncoming ?? 0) === 0
    );
    title = `Contextual links · ${summaryData.contextualLinks ?? 0}`;
    const opportunities = noContextualIncoming.map((page) =>
      `${linkHtml(page.url)} — ${page.internalLinks?.incoming ?? 0} total inbound, 0 contextual inbound`
    );
    body = `<p><strong>${summaryData.contextualLinks ?? 0}</strong> unique internal links were found inside main/article content.</p>
      <h4>Pages with no contextual inbound links</h4>
      ${detailList(opportunities, 'Every eligible sitemap page has at least one contextual inbound link, or no opportunity could be established.')}`;
  } else if (key === 'maxCrawlDepth') {
    const depth = summaryData.maxCrawlDepth ?? 0;
    const deepest = pages.filter((page) => page.internalLinks?.crawlDepth === depth);
    title = `Max crawl depth · ${depth}`;
    body = `<p>Depth 0 is the first audited final page; depth 1 is one internal-link click away, and so on.</p>` +
      detailList(deepest.map((page) => linkHtml(page.url)), 'No crawl-depth data is available.');
  } else if (key === 'socialImagesChecked') {
    const images = new Map();
    for (const page of pages) {
      for (const image of [page.socialImages?.openGraph, page.socialImages?.twitter]) {
        if (image?.url) images.set(image.url, image);
      }
    }
    title = `Social images checked · ${summaryData.socialImagesChecked ?? images.size}`;
    body = detailList([...images.values()].map((image) => {
      const dimensions = image.width && image.height ? ` · ${image.width}×${image.height}` : '';
      return `${linkHtml(image.url)} — HTTP ${image.status || 'failed'} · ${escapeHtml(image.contentType || 'unknown type')}${dimensions}`;
    }), 'No social-image URLs were available to check.');
  } else {
    title = 'Metric details';
  }

  return { title, description, body };
}

function showSummaryDetail(key) {
  if (!latestResult || !summaryDetail) return;
  const detail = summaryDetailContent(key, latestResult);
  summary.querySelectorAll('.summary-card').forEach((card) => {
    const selected = card.dataset.summaryKey === key;
    card.classList.toggle('selected', selected);
    card.setAttribute('aria-expanded', selected ? 'true' : 'false');
  });
  summaryDetail.innerHTML = `
    <div class="summary-detail-heading">
      <div><span class="eyebrow">Metric details</span><h3>${escapeHtml(detail.title)}</h3></div>
      <button type="button" class="summary-detail-close" aria-label="Close metric details">×</button>
    </div>
    <p class="summary-detail-description">${escapeHtml(detail.description)}</p>
    <div class="summary-detail-body">${detail.body}</div>
  `;
  summaryDetail.hidden = false;
}

function pageMatches(page) {
  const severity = severityFilter.value;
  if (severity !== 'all' && !page.issues.some((issue) => issue.severity === severity)) return false;
  const q = tableSearch.value.trim().toLowerCase();
  if (!q) return true;
  const haystack = [
    page.url,
    page.title,
    page.description,
    page.lang || '',
    page.canonical || '',
    ...page.hreflangs.flatMap((entry) => [entry.lang, entry.href]),
    ...page.issues.map((issue) => `${issue.code} ${issue.message} ${issue.severity}`),
  ].join(' ').toLowerCase();
  return haystack.includes(q);
}

function renderRows() {
  if (!latestResult) return;
  resultsBody.innerHTML = '';
  for (const page of latestResult.pages.filter(pageMatches)) {
    const row = rowTemplate.content.firstElementChild.cloneNode(true);
    const pageCell = row.querySelector('.page-cell');
    const langCell = row.querySelector('.lang-cell');
    const hreflangCell = row.querySelector('.hreflang-cell');
    const canonicalCell = row.querySelector('.canonical-cell');
    const indexabilityCell = row.querySelector('.indexability-cell');
    const issuesCell = row.querySelector('.issues-cell');

    pageCell.innerHTML = `<a class="page-url" href="${escapeHtml(page.url)}" target="_blank" rel="noreferrer">${escapeHtml(page.url)}</a><div class="page-title">${escapeHtml(page.title || 'No title')}</div>`;
    const detected = page.detectedLang
      ? `<span class="pill detected-lang" title="Detected from visible content · confidence ${Math.round((page.languageConfidence || 0) * 100)}%">detected: ${escapeHtml(page.detectedLang)}</span>`
      : '';
    langCell.innerHTML = (page.lang ? `<span class="pill">${escapeHtml(page.lang)}</span>` : '—') + detected;
    hreflangCell.innerHTML = page.hreflangs.length
      ? page.hreflangs.map((entry) => `<span class="pill" title="${escapeHtml((entry.source || 'html') + ' · ' + entry.href)}">${escapeHtml(entry.lang)}</span>`).join('')
      : '—';
    canonicalCell.innerHTML = page.canonical
      ? `<a class="canonical" href="${escapeHtml(page.canonical)}" target="_blank" rel="noreferrer" title="${escapeHtml(page.canonical)}">${escapeHtml(page.canonical)}</a>`
      : '—';
    const indexability = page.indexability || { status: 'unknown', reason: 'Indexability was not calculated.' };
    indexabilityCell.innerHTML = `<span class="pill" title="${escapeHtml(indexability.reason || '')}">${escapeHtml(indexability.status || 'unknown')}</span>`;
    issuesCell.innerHTML = page.issues.length
      ? `<div class="issue-list">${page.issues.map(issueHtml).join('')}</div>`
      : '<span class="no-issues">No issues found</span>';

    resultsBody.appendChild(row);
  }
}

function renderSiteDiagnostics(result) {
  const site = result.site || {};
  const issues = result.siteIssues || [];
  const robots = site.robotsTxt || {};
  const sitemapCount = (site.sitemaps || []).length;
  const sitemapUrls = site.sitemapUrlsDiscovered || 0;
  const coverage = site.crawlCoverage || {};
  const coverageText = coverage.percent == null ? 'coverage n/a' : coverage.percent + '% sitemap coverage';
  siteDiagnosticsMeta.textContent = 'robots.txt: ' + (robots.status || 'not checked') + ' · ' + (robots.rules ?? 0) + ' rule' + ((robots.rules ?? 0) === 1 ? '' : 's') + ' · ' + sitemapCount + ' sitemap' + (sitemapCount === 1 ? '' : 's') + ' parsed · ' + sitemapUrls + ' URL' + (sitemapUrls === 1 ? '' : 's') + ' discovered · ' + coverageText;
  siteIssuesList.innerHTML = issues.length ? issues.map(issueHtml).join('') : '<span class="no-issues">No site-level issues found</span>';
  siteDiagnostics.hidden = false;
}

function render(result) {
  latestResult = result;
  const parsed = new URL(result.startUrl);
  resultDomain.textContent = parsed.hostname;
  resultMeta.textContent = result.summary.pages + ' page' + (result.summary.pages === 1 ? '' : 's') + ' audited · ' + new Date(result.auditedAt).toLocaleString();
  summary.innerHTML = [
    summaryCard('pages', 'Pages', result.summary.pages),
    summaryCard('errors', 'Errors', result.summary.errors, 'error'),
    summaryCard('warnings', 'Warnings', result.summary.warnings, 'warning'),
    summaryCard('info', 'Info', result.summary.info, 'info'),
    summaryCard('languages', 'Languages', result.summary.languages.length || '—'),
    summaryCard('sitemapUrls', 'Sitemap URLs', result.summary.sitemapUrls ?? '—'),
    summaryCard('indexablePages', 'Indexable pages', result.summary.indexablePages ?? '—'),
    summaryCard('noindexPages', 'Noindex pages', result.summary.noindexPages ?? 0),
    summaryCard('redirects', 'Redirects', result.summary.redirects ?? 0),
    summaryCard('orphanCandidates', 'Orphan candidates', result.summary.orphanCandidates ?? 0),
    summaryCard('duplicateContentGroups', 'Duplicate groups', result.summary.duplicateContentGroups ?? 0),
    summaryCard('robotsBlockedPages', 'Robots blocked', result.summary.robotsBlockedPages ?? 0),
    summaryCard('linkTargetsChecked', 'Checked link targets', result.summary.linkTargetsChecked ?? 0),
    summaryCard('contextualLinks', 'Contextual links', result.summary.contextualLinks ?? 0),
    summaryCard('maxCrawlDepth', 'Max crawl depth', result.summary.maxCrawlDepth ?? 0),
    summaryCard('socialImagesChecked', 'Social images checked', result.summary.socialImagesChecked ?? 0),
  ].join('');
  if (summaryDetail) {
    summaryDetail.hidden = true;
    summaryDetail.innerHTML = '';
  }
  renderSiteDiagnostics(result);
  renderRows();
  results.hidden = false;
  results.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function download(filename, content, type) {
  const blob = new Blob([content], { type });
  const href = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = href;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(href);
}

function csvEscape(value) {
  const text = String(value ?? '');
  return `"${text.replaceAll('"', '""')}"`;
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  runButton.disabled = true;
  results.hidden = true;
  siteDiagnostics.hidden = true;
  setStatus(`Auditing up to ${maxPagesInput.value} pages…`);

  try {
    const response = await fetch('/api/audit', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ url: urlInput.value, maxPages: Number(maxPagesInput.value) }),
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || payload.message || 'Audit failed.');
    setStatus(`Done. Audited ${payload.summary.pages} pages.`);
    render(payload);
  } catch (error) {
    setStatus(error.message || 'Audit failed.', true);
  } finally {
    runButton.disabled = false;
  }
});

summary.addEventListener('click', (event) => {
  const card = event.target.closest('.summary-card[data-summary-key]');
  if (!card) return;
  showSummaryDetail(card.dataset.summaryKey);
});

summaryDetail?.addEventListener('click', (event) => {
  if (!event.target.closest('.summary-detail-close')) return;
  summaryDetail.hidden = true;
  summary.querySelectorAll('.summary-card').forEach((card) => {
    card.classList.remove('selected');
    card.setAttribute('aria-expanded', 'false');
  });
});

severityFilter.addEventListener('change', renderRows);
tableSearch.addEventListener('input', renderRows);

downloadJson.addEventListener('click', () => {
  if (!latestResult) return;
  download('multilingual-seo-audit.json', JSON.stringify(latestResult, null, 2), 'application/json');
});

downloadCsv.addEventListener('click', () => {
  if (!latestResult) return;

  const headers = [
    'Record type', 'Audit generated at', 'Engine version', 'URL', 'Requested URL', 'Status', 'Lang', 'Detected lang', 'Language confidence',
    'Title', 'Meta description', 'Canonical', 'Indexability', 'Indexability reason', 'Robots', 'X-Robots-Tag', 'Robots.txt allowed',
    'Redirect final URL', 'Redirect chain', 'Link check final URL', 'Link check error',
    'Robots.txt matched rule', 'Redirect hops', 'Incoming internal links', 'Outgoing internal links', 'Contextual incoming links',
    'Contextual outgoing links', 'Crawl depth', 'Internal links to redirects', 'In sitemap', 'H1 count', 'H1 text', 'H2 count',
    'Word count', 'Image count', 'Images missing alt', 'Images empty alt', 'Hreflang codes', 'Hreflang targets', 'Hreflang sources',
    'OG title', 'OG description', 'OG image', 'OG URL', 'OG image status', 'OG image content type', 'OG image width', 'OG image height',
    'Twitter card', 'Twitter title', 'Twitter description', 'Twitter image', 'Twitter image status', 'Twitter image content type',
    'Twitter image width', 'Twitter image height', 'JSON-LD blocks', 'JSON-LD parseable', 'JSON-LD syntax invalid',
    'JSON-LD types', 'JSON-LD semantic findings', 'Content hash', 'Content hash status',
    'Error count', 'Warning count', 'Info count', 'Sitemaps parsed', 'Sitemap URLs discovered', 'Sitemap coverage %',
    'Summary indexable pages', 'Summary noindex pages', 'Summary robots blocked pages', 'Summary redirects', 'Summary redirect chains',
    'Summary orphan candidates', 'Summary duplicate groups', 'Summary links to redirects', 'Summary mixed-scheme links',
    'Summary link targets checked', 'Summary social images checked', 'Summary contextual links', 'Summary max crawl depth', 'Issues',
  ];

  const makeRow = (values) => headers.map((header) => values[header] ?? '');
  const rows = [headers];
  const siteIssues = latestResult.siteIssues || [];
  const siteCount = (severity) => siteIssues.filter((issue) => issue.severity === severity).length;

  rows.push(makeRow({
    'Record type': 'site',
    'Audit generated at': latestResult.auditedAt || '',
    'Engine version': latestResult.version || '',
    'URL': latestResult.origin,
    'Status': latestResult.site?.robotsTxt?.status || '',
    'Error count': siteCount('error'),
    'Warning count': siteCount('warning'),
    'Info count': siteCount('info'),
    'Sitemaps parsed': (latestResult.site?.sitemaps || []).length,
    'Sitemap URLs discovered': latestResult.site?.sitemapUrlsDiscovered || 0,
    'Sitemap coverage %': latestResult.site?.crawlCoverage?.percent ?? '',
    'Summary indexable pages': latestResult.summary?.indexablePages ?? '',
    'Summary noindex pages': latestResult.summary?.noindexPages ?? '',
    'Summary robots blocked pages': latestResult.summary?.robotsBlockedPages ?? '',
    'Summary redirects': latestResult.summary?.redirects ?? '',
    'Summary redirect chains': latestResult.summary?.redirectChains ?? '',
    'Summary orphan candidates': latestResult.summary?.orphanCandidates ?? '',
    'Summary duplicate groups': latestResult.summary?.duplicateContentGroups ?? '',
    'Summary links to redirects': latestResult.summary?.internalLinksToRedirects ?? '',
    'Summary mixed-scheme links': latestResult.summary?.mixedSchemeLinks ?? '',
    'Summary link targets checked': latestResult.summary?.linkTargetsChecked ?? '',
    'Summary social images checked': latestResult.summary?.socialImagesChecked ?? '',
    'Summary contextual links': latestResult.summary?.contextualLinks ?? '',
    'Summary max crawl depth': latestResult.summary?.maxCrawlDepth ?? '',
    'Issues': siteIssues.map((issue) => issue.severity + ': ' + issue.message).join(' | '),
  }));

  for (const redirect of latestResult.site?.redirects || []) {
    const chain = redirect.chain || [];
    rows.push(makeRow({
      'Record type': 'redirect',
      'Audit generated at': latestResult.auditedAt || '',
      'Engine version': latestResult.version || '',
      'URL': redirect.requestedUrl || '',
      'Requested URL': redirect.requestedUrl || '',
      'Redirect hops': chain.length,
      'Redirect final URL': redirect.finalUrl || '',
      'Redirect chain': chain.map((hop) => hop.status + ' ' + hop.from + ' => ' + hop.to).join(' | '),
      'Issues': 'Redirect: ' + (redirect.requestedUrl || '') + ' => ' + (redirect.finalUrl || ''),
    }));
  }

  for (const check of latestResult.site?.linkTargetChecks || []) {
    rows.push(makeRow({
      'Record type': 'link-check',
      'Audit generated at': latestResult.auditedAt || '',
      'Engine version': latestResult.version || '',
      'URL': check.url || '',
      'Requested URL': check.url || '',
      'Status': check.status || '',
      'Redirect hops': (check.chain || []).length,
      'Redirect chain': (check.chain || []).map((hop) => hop.status + ' ' + hop.from + ' => ' + hop.to).join(' | '),
      'Link check final URL': check.finalUrl || '',
      'Link check error': check.error || '',
      'Issues': check.error ? 'Link target check failed: ' + check.error : '',
    }));
  }

  for (const page of latestResult.pages) {
    const count = (severity) => page.issues.filter((issue) => issue.severity === severity).length;

    rows.push(makeRow({
      'Record type': 'page',
      'Audit generated at': latestResult.auditedAt || '',
      'Engine version': latestResult.version || '',
      'URL': page.url,
      'Requested URL': page.requestedUrl || page.url,
      'Status': page.status,
      'Lang': page.lang || '',
      'Detected lang': page.detectedLang || '',
      'Language confidence': page.languageConfidence ?? '',
      'Title': page.title,
      'Meta description': page.description,
      'Canonical': page.canonical || '',
      'Indexability': page.indexability?.status || 'unknown',
      'Indexability reason': page.indexability?.reason || '',
      'Robots': page.robots || '',
      'X-Robots-Tag': page.xRobotsTag || '',
      'Robots.txt allowed': page.robotsTxt?.allowed === false ? 'no' : 'yes',
      'Robots.txt matched rule': page.robotsTxt?.matchedRule || '',
      'Redirect hops': (page.redirectChain || []).length,
      'Incoming internal links': page.internalLinks?.incoming ?? '',
      'Outgoing internal links': page.internalLinks?.outgoing ?? '',
      'Contextual incoming links': page.internalLinks?.contextualIncoming ?? '',
      'Contextual outgoing links': page.internalLinks?.contextualOutgoing ?? '',
      'Crawl depth': page.internalLinks?.crawlDepth ?? '',
      'Internal links to redirects': page.internalLinks?.redirecting ?? '',
      'In sitemap': page.inSitemap ? 'yes' : 'no',
      'H1 count': page.headings?.h1Count ?? '',
      'H1 text': (page.headings?.h1 || []).join(' | '),
      'H2 count': page.headings?.h2Count ?? '',
      'Word count': page.wordCount ?? '',
      'Image count': page.images?.total ?? '',
      'Images missing alt': page.images?.missingAlt ?? '',
      'Images empty alt': page.images?.emptyAlt ?? '',
      'Hreflang codes': page.hreflangs.map((entry) => entry.lang).join(' | '),
      'Hreflang targets': page.hreflangs.map((entry) => entry.lang + ' => ' + entry.href).join(' | '),
      'Hreflang sources': page.hreflangs.map((entry) => entry.lang + ' => ' + (entry.source || 'html')).join(' | '),
      'OG title': page.openGraph?.title || '',
      'OG description': page.openGraph?.description || '',
      'OG image': page.openGraph?.image || '',
      'OG URL': page.openGraph?.url || '',
      'OG image status': page.socialImages?.openGraph?.status ?? '',
      'OG image content type': page.socialImages?.openGraph?.contentType || '',
      'OG image width': page.socialImages?.openGraph?.width ?? '',
      'OG image height': page.socialImages?.openGraph?.height ?? '',
      'Twitter card': page.twitter?.card || '',
      'Twitter title': page.twitter?.title || '',
      'Twitter description': page.twitter?.description || '',
      'Twitter image': page.twitter?.image || '',
      'Twitter image status': page.socialImages?.twitter?.status ?? '',
      'Twitter image content type': page.socialImages?.twitter?.contentType || '',
      'Twitter image width': page.socialImages?.twitter?.width ?? '',
      'Twitter image height': page.socialImages?.twitter?.height ?? '',
      'JSON-LD blocks': page.structuredData?.scripts ?? '',
      'JSON-LD parseable': page.structuredData?.parseable ?? '',
      'JSON-LD syntax invalid': page.structuredData?.invalidSyntax ?? '',
      'JSON-LD types': (page.structuredData?.types || []).join(' | '),
      'JSON-LD semantic findings': (page.structuredData?.semanticIssues || []).map((issue) => issue.code + ': ' + issue.message).join(' | '),
      'Content hash': page.contentHash || '',
      'Content hash status': page.contentHashStatus || '',
      'Error count': count('error'),
      'Warning count': count('warning'),
      'Info count': count('info'),
      'Issues': page.issues.map((issue) => issue.severity + ': ' + issue.message).join(' | '),
    }));
  }

  download('multilingual-seo-audit.csv', rows.map((row) => row.map(csvEscape).join(',')).join('\n'), 'text/csv;charset=utf-8');
});

