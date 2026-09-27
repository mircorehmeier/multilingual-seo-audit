const form = document.querySelector('#audit-form');
const urlInput = document.querySelector('#url');
const maxPagesInput = document.querySelector('#max-pages');
const runButton = document.querySelector('#run-button');
const status = document.querySelector('#status');
const results = document.querySelector('#results');
const resultDomain = document.querySelector('#result-domain');
const resultMeta = document.querySelector('#result-meta');
const summary = document.querySelector('#summary');
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

function summaryCard(label, value, type = '') {
  return `<div class="summary-card ${type}"><span>${label}</span><strong>${value}</strong></div>`;
}

function escapeHtml(value = '') {
  return value.replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
}

function issueHtml(issue) {
  return `<div class="issue-item"><span class="pill ${issue.severity}">${issue.severity}</span>${escapeHtml(issue.message)}</div>`;
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
    summaryCard('Pages', result.summary.pages),
    summaryCard('Errors', result.summary.errors, 'error'),
    summaryCard('Warnings', result.summary.warnings, 'warning'),
    summaryCard('Info', result.summary.info, 'info'),
    summaryCard('Languages', result.summary.languages.length || '—'),
    summaryCard('Sitemap URLs', result.summary.sitemapUrls ?? '—'),
    summaryCard('Indexable pages', result.summary.indexablePages ?? '—'),
    summaryCard('Noindex pages', result.summary.noindexPages ?? 0),
    summaryCard('Redirects', result.summary.redirects ?? 0),
    summaryCard('Orphan candidates', result.summary.orphanCandidates ?? 0),
    summaryCard('Duplicate groups', result.summary.duplicateContentGroups ?? 0),
    summaryCard('Robots blocked', result.summary.robotsBlockedPages ?? 0),
    summaryCard('Checked link targets', result.summary.linkTargetsChecked ?? 0),
    summaryCard('Contextual links', result.summary.contextualLinks ?? 0),
    summaryCard('Max crawl depth', result.summary.maxCrawlDepth ?? 0),
    summaryCard('Social images checked', result.summary.socialImagesChecked ?? 0),
  ].join('');
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

