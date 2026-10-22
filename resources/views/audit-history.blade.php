<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Private Audit History</title>
  <link rel="stylesheet" href="/styles.css?v={{ config('audit.version') }}">
  <style>
    .history-wrap {max-width:1200px;margin:2rem auto;padding:0 1.25rem}
    .history-top {display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
    .history-search {display:flex;gap:.5rem;margin:1.5rem 0}
    .history-search input {padding:.65rem;min-width:260px}
    .history-table {width:100%;border-collapse:collapse;background:var(--card,#fff)}
    .history-table th,.history-table td {text-align:left;padding:.8rem .6rem;border-bottom:1px solid #ccc;overflow-wrap:anywhere}
    .history-table td:first-child {max-width:400px}
    .history-table a {text-decoration:underline}
    .history-pagination {display:flex;gap:1rem;justify-content:space-between;margin:1rem 0}
    @media(max-width:750px){.history-table{font-size:.82rem}.history-table th,.history-table td{padding:.5rem .2rem}}
  </style>
</head>
<body>
  <main class="history-wrap">
    <div class="history-top"><div><p class="eyebrow">Private admin area</p><h1>Recent audits</h1><p class="muted">All websites · Quick and background audits · Reports expire after 7 days</p></div><a class="secondary" href="/">← Back to auditor</a></div>
    @if(session('notice'))<p class="environment-note">{{ session('notice') }}</p>@endif
    <form method="get" class="history-search"><label for="domain">Filter domain</label><input id="domain" name="domain" value="{{ $search }}" placeholder="e.g. brasilll.com"><button type="submit">Filter</button></form>
    <div style="overflow-x:auto">
      <table class="history-table">
        <thead><tr><th>Website</th><th>Started</th><th>Environment</th><th>Pages</th><th>Status</th><th>Report</th></tr></thead>
        <tbody>
          @forelse($runs as $run)
            <tr>
              <td>{{ $run->url }}</td>
              <td>{{ $run->created_at }}</td>
              <td>{{ $run->environment }}</td>
              <td>{{ $run->pages_done }} / {{ $run->max_pages }}</td>
              <td>{{ ucfirst($run->status) }}</td>
              <td>
                @if($run->status === 'completed')
                  <a href="{{ route('audit.history.open', ['id' => $run->id]) }}">View audit</a>
                  @if(isset($progressPairs[$run->id]))
                    <span style="margin:0 .25rem">·</span><a href="{{ route('audit.history.progress', ['id' => $run->id]) }}"><strong>Progress since last audit →</strong></a>
                  @endif
                @elseif($run->status === 'failed')
                  <small>{{ $run->error }}</small>
                @else
                  <span>Not ready</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6">No recent background audits found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="history-pagination">
      <span>Showing {{ $runs->firstItem() ?? 0 }}–{{ $runs->lastItem() ?? 0 }} of {{ $runs->total() }}</span>
      <span>@if($runs->previousPageUrl())<a href="{{ $runs->previousPageUrl() }}">← Previous</a>@endif @if($runs->nextPageUrl())<a href="{{ $runs->nextPageUrl() }}">Next →</a>@endif</span>
    </div>
    <p class="muted">Only the newest completed audit for each site and environment can be compared with its immediate predecessor. Reports expire after seven days.</p>
  </main>
</body>
</html>
