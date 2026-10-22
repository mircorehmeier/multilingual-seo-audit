# Background audits (Plesk)

Version 1.0 adds optional 250, 500 and 1,000-page audits processed by the Laravel database queue.

## Deployment

1. Deploy the updated files using the usual `deploy.sh` workflow. The script creates a private SQLite database, changes an existing `QUEUE_CONNECTION=sync` to `database`, and runs `artisan migrate --force`.
2. Ensure the Plesk CLI PHP 8.5 installation has **pdo_sqlite** enabled (the deploy script checks this). Confirm `QUEUE_CONNECTION=database` in the application's existing `.env`, and that the database and storage directories are writable.
3. Set up a Plesk scheduled task **once per minute** under the subscription user. Use this shell command (adjust paths to your actual deployed directory):
   
   ```bash
   flock -n /tmp/seo-audit-queue.lock /opt/plesk/php/8.5/bin/php /var/www/vhosts/rehmeier.es/seo-audit/artisan queue:work database --queue=audits --once --tries=1 --timeout=1800 --memory=1024
   ```
   
   `flock` prevents overlapping workers if a crawl takes longer than a minute.
4. Create a **daily** Plesk task to delete expired audit results and rows:
   
   ```bash
   /opt/plesk/php/8.5/bin/php /var/www/vhosts/rehmeier.es/seo-audit/artisan seo-audit:prune
   ```

## Storage policy

- Up to 100 pages: original synchronous behavior; no completed report stored.
- 250–1000 pages: queued audit. Database stores small status/progress metadata; the full result is saved as **gzip-compressed JSON** in Laravel's **private local storage**, not the publicly served folder.
- Runs expire after 7 days. The daily prune task is required to physically delete expired report files and database records.
- The browser downloads CSV/JSON from the in-memory result as before.
- Audits are available through unguessable UUID URLs; there is no account authentication. Do **not** expose this tool publicly without adding access control for sensitive site reports.

## Operational notes

- This is a first background-processing implementation, not yet a resumable page-by-page crawl. A worker interruption can require a new audit, though the browser request is no longer subject to HTTP execution timeouts.
- Worker progress updates after each crawl batch. If a run sits at `queued`, check that the scheduled worker is active.
- `QUEUE_CONNECTION=sync` will **not** work for large audits; the endpoint refuses that configuration.
- If jobs fail, inspect `storage/logs/laravel.log` and `php artisan queue:failed`.
- Existing production installs that use MySQL/PostgreSQL should configure their existing database connection; SQLite is a convenient default for a personal tool.
