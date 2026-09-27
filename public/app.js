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
const runtimeVersion = document.querySelector('#runtime-version');
const resultEngineVersion = document.querySelector('#result-engine-version');

let latestResult = null;

async function verifyRuntimeVersion() {
  if (!runtimeVersion) return;

  const renderedVersion = runtimeVersion.dataset.renderedVersion || '';

  try {
    const response = await fetch('/api/health?ts=' + Date.now(), { cache: 'no-store' });
    const health = await response.json();
    const backendVersion = String(health.version || '').replace('-laravel', '');

    if (backendVersion) {
      runtimeVersion.innerHTML = '<span class="runtime-dot" aria-hidden="true"></span>Running version <strong>' + escapeHtml(backendVersion) + '</strong>';
    }

    const mismatch = Boolean(renderedVersion && backendVersion && renderedVersion !== backendVersion);
    runtimeVersion.classList.toggle('mismatch', mismatch);

    if (mismatch) {
      runtimeVersion.title = 'The page HTML and backend report different versions. Hard refresh or redeploy.';
      runtimeVersion.innerHTML += ' <span>· version mismatch</span>';
    }
  } catch {
    runtimeVersion.title = 'Could not verify the backend version.';
  }
}

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
      ? page.hreflangs.map((entry) => `<span class="pill" title="${escapeHtml(entry.href)}">${escapeHtml(entry.lang)}</span>`).join('')
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
  siteDiagnosticsMeta.textContent = 'robots.txt: ' + (robots.status || 'not checked') + ' · ' + sitemapCount + ' sitemap' + (sitemapCount === 1 ? '' : 's') + ' parsed · ' + sitemapUrls + ' URL' + (sitemapUrls === 1 ? '' : 's') + ' discovered · ' + coverageText;
  siteIssuesList.innerHTML = issues.length ? issues.map(issueHtml).join('') : '<span class="no-issues">No site-level issues found</span>';
  siteDiagnostics.hidden = false;
}

function render(result) {
  latestResult = result;
  const parsed = new URL(result.startUrl);
  resultDomain.textContent = parsed.hostname;
  resultMeta.textContent = result.summary.pages + ' page' + (result.summary.pages === 1 ? '' : 's') + ' audited · ' + new Date(result.auditedAt).toLocaleString();
  if (resultEngineVersion) resultEngineVersion.textContent = 'Engine v' + (result.version || 'unknown');
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
    'Title', 'Meta description', 'Canonical', 'Indexability', 'Indexability reason', 'Robots', 'X-Robots-Tag', 'Redirect hops',
    'Incoming internal links', 'Outgoing internal links', 'Internal links to redirects', 'In sitemap', 'H1 count', 'H1 text', 'H2 count',
    'Word count', 'Image count', 'Images missing alt', 'Images empty alt', 'Hreflang codes', 'Hreflang targets',
    'OG title', 'OG description', 'OG image', 'OG URL', 'Twitter card', 'Twitter title', 'Twitter description', 'Twitter image',
    'JSON-LD blocks', 'JSON-LD valid', 'JSON-LD invalid', 'JSON-LD types', 'Content hash',
    'Error count', 'Warning count', 'Info count', 'Sitemaps parsed', 'Sitemap URLs discovered', 'Sitemap coverage %', 'Issues',
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
      'Redirect hops': (page.redirectChain || []).length,
      'Incoming internal links': page.internalLinks?.incoming ?? '',
      'Outgoing internal links': page.internalLinks?.outgoing ?? '',
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
      'OG title': page.openGraph?.title || '',
      'OG description': page.openGraph?.description || '',
      'OG image': page.openGraph?.image || '',
      'OG URL': page.openGraph?.url || '',
      'Twitter card': page.twitter?.card || '',
      'Twitter title': page.twitter?.title || '',
      'Twitter description': page.twitter?.description || '',
      'Twitter image': page.twitter?.image || '',
      'JSON-LD blocks': page.structuredData?.scripts ?? '',
      'JSON-LD valid': page.structuredData?.valid ?? '',
      'JSON-LD invalid': page.structuredData?.invalid ?? '',
      'JSON-LD types': (page.structuredData?.types || []).join(' | '),
      'Content hash': page.contentHash || '',
      'Error count': count('error'),
      'Warning count': count('warning'),
      'Info count': count('info'),
      'Issues': page.issues.map((issue) => issue.severity + ': ' + issue.message).join(' | '),
    }));
  }

  download('multilingual-seo-audit.csv', rows.map((row) => row.map(csvEscape).join(',')).join('\n'), 'text/csv;charset=utf-8');
});

verifyRuntimeVersion();
