# Operations

How production runs outside of web requests: the crontab, what each job does, where logs go, how to tell a job
is alive, and how to back up and restore the database. Prod lives at `/var/www/creatorlinkstudio.com/www`
(Ubuntu, Apache, TLS at the AWS load balancer). Dev runs the same scripts from launchd agents
(`~/Library/LaunchAgents/com.creatorlinkstudio.*.plist`, logs in `/tmp/cls-*.log`).

## Prod crontab

Install with `crontab -e` as the user that owns the checkout (it needs to read `app/config/app.ini`; the error
digest also needs read access to `/var/log/apache2/error.log`, so that user must be in the `adm` group, or set
`error_log_path` in app.ini). Every script sets UTC itself.

```
# --- current prod crontab (8)
30 9 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_fact_check.php >> /tmp/cls-seo-fact-check.log 2>&1
17 4 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/domains.php >> /tmp/cls-domains.log 2>&1
*/15 * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/billing.php >> /var/www/creatorlinkstudio.com/www/cron/billing.log 2>&1
0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1
*/2 * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/moderate.php >> /var/www/creatorlinkstudio.com/www/cron/moderate.log 2>&1
0 */6 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/social_metrics.php >> /var/www/creatorlinkstudio.com/www/cron/social_metrics.log 2>&1
* * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/scheduler.php >> /var/www/creatorlinkstudio.com/www/cron/scheduler.log 2>&1
* * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/queue_worker.php >> /var/www/creatorlinkstudio.com/www/cron/queue_worker.log 2>&1
# --- new (2026-10-08)
30 3 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/db_backup.php >> /tmp/cls-db-backup.log 2>&1
45 7 * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/error_digest.php >> /tmp/cls-error-digest.log 2>&1
```

The queue worker keeps claiming jobs until it has been idle 50 s (or ran 100 jobs), so one starts every minute.
A busy worker can overlap the next one; claims are atomic, so that is safe.

## What each job does

| Job | Every | What it does | Log |
|---|---|---|---|
| scheduler | 1 min | Runs due creator automations (image, caption, publish, cross-post), publishes scheduled posts, drains inbox automation events. | cron/scheduler.log |
| queue_worker | 1 min | Runs background jobs from the `jobs` table: signup alerts, new-post fan-out, broadcasts, media and influencer generation, data exports, membership fees, event messages, clip renders. | cron/queue_worker.log |
| moderate | 2 min | Classifies newly uploaded images (adult / blocked) and writes the verdict to `media_assets`. | cron/moderate.log |
| billing | 15 min | Charges creator plans that are due and retries past-due ones (BillingService). | cron/billing.log |
| social_metrics | 6 h | Pulls likes, comments and views for connected social accounts into `social_post_metrics`. | cron/social_metrics.log |
| domains | daily 04:17 | Re-checks custom-domain DNS, takes dead domains off, purges spent handoff tokens. | /tmp/cls-domains.log |
| seo_draft | daily 09:00 | Drafts one blog article from the top queued keyword. | cron/seo_draft.log |
| seo_fact_check | daily 09:30 | Checks articles and public pages still state the current plans, prices and fee; notifies admins on drift (exits 1 then). | /tmp/cls-seo-fact-check.log |
| db_backup | daily 03:30 | mysqldump, gzip, upload to S3 `backups/db/production/<date>.sql.gz`. | /tmp/cls-db-backup.log |
| error_digest | daily 07:45 | Emails all admins the 30 most frequent error-log lines of the last 24 h (nothing sent when there are none). | /tmp/cls-error-digest.log |

Not scheduled (run by hand when needed): `directory_backfill.php`, `indexnow_sitemap.php`,
`migrate_subscriptions.php`, `site_images.php`, `sync_membership_fees.php`.

## Is it running?

- **/admin, Financials tab, Jobs box**: last finish, OK or Failed, and a note for every scheduled job. A job is
  marked Stale when its last finish is older than twice its interval (`CronRuns::INTERVALS`). Each script calls
  `CronRuns::start()` and calls `CronRuns::finish()` on every way out (idle runs included); a run that ends without
  finish() (fatal error, uncaught exception) is recorded as Failed with the note "ended without finish".
  Table: `cron_runs` (sql/2026-10-08_cron_runs.sql).
- **GET /health**: `{"ok":true,"db":true,"queue_lag_s":N,"time":"..."}`, HTTP 503 with `ok:false` when the
  database does not answer. `queue_lag_s` is how long the oldest due queued job has waited (0 when none); a
  value that keeps growing means the queue worker is not running. Point the uptime monitor (UptimeRobot,
  BetterStack) at exactly `https://www.creatorlinkstudio.com/health` (keyword `"ok":true`) and at `/`, with
  "follow redirects" OFF: the app 301s every other host and plain http to the canonical host, so a monitor on
  another URL would only be checking the redirect.

## Logs

| What | Where |
|---|---|
| PHP and app `error_log()` lines | Apache error log, `/var/log/apache2/error.log` on prod (dev: `/opt/homebrew/var/log/httpd/error_log`); rotated daily by logrotate (about 06:25) to `error.log.1` |
| Cron output | the file at the end of each crontab line above |
| Error digest source | app.ini `error_log_path` (optional, current env section), else php.ini `error_log`, else `/var/log/apache2/error.log`. Only the last 64 MB of each file is read; `<log>.1` is read too when it was rotated inside the last 24 h. |

Test the digest without sending: `php cron/error_digest.php --log=/path/to/file --dry-run`.

## Database backup

`cron/db_backup.php` dumps with `--single-transaction --quick --routines --no-tablespaces` (no table locks on
InnoDB), using the `db_*` credentials of the current app.ini section through a 0600 temp defaults file, gzips,
uploads PRIVATE to `s3://content-os-bucket/backups/db/<env>/<YYYY-MM-DD>.sql.gz`, deletes the temp file and
exits non-zero on any failure. A second run on the same day overwrites that day's object.

- Optional app.ini key `mysqldump_path` when `mysqldump` is not on cron's PATH, or is a newer major version than
  the server (a MySQL 9 client against an 8.0 server fails on `--routines` with "Unknown table 'LIBRARIES'").
- **Bucket lifecycle (set once)**: S3 console, content-os-bucket, Management, Create lifecycle rule
  `db-backups-30d`, prefix `backups/db/`, "Expire current versions of objects" after 30 days (if bucket versioning
  is on, also "Permanently delete noncurrent versions" after 1 day). Or from a shell:

```
aws s3api put-bucket-lifecycle-configuration --bucket content-os-bucket --lifecycle-configuration '{"Rules":[{"ID":"db-backups-30d","Filter":{"Prefix":"backups/db/"},"Status":"Enabled","Expiration":{"Days":30},"NoncurrentVersionExpiration":{"NoncurrentDays":1}}]}'
```

  This call REPLACES the bucket's whole lifecycle configuration: run `aws s3api get-bucket-lifecycle-configuration
  --bucket content-os-bucket` first and merge any existing rules into the JSON.

## Restore

Always restore into a scratch database first, check it, then decide.

```
# 1. download (pick the date)
aws s3 cp s3://content-os-bucket/backups/db/production/2026-10-08.sql.gz /tmp/
# 2. check and unpack
gunzip -t /tmp/2026-10-08.sql.gz && gunzip -k /tmp/2026-10-08.sql.gz
tail -1 /tmp/2026-10-08.sql                      # "-- Dump completed on ..." means the dump is whole
# 3. import into a scratch database
mysql -e "CREATE DATABASE contentos_restore_test CHARACTER SET utf8mb4"
mysql contentos_restore_test < /tmp/2026-10-08.sql
# 4. confirm
mysql -e "SELECT COUNT(*) AS users FROM contentos_restore_test.user_accounts; SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = 'contentos_restore_test'"
# 5. clean up
mysql -e "DROP DATABASE contentos_restore_test"; rm /tmp/2026-10-08.sql /tmp/2026-10-08.sql.gz
```

(Add `-h <host> -u <user> -p` to the mysql lines as needed; credentials are in app.ini.) For a real recovery,
stop the crontab first, import the checked file into the production database name, then start the crontab again.

### Restore drill performed on dev, 2026-10-08

```
$ PATH=/usr/local/mysql/bin:$PATH php cron/db_backup.php      # 8.0 client to match the 8.0.28 dev server
2026-10-08T15:01:57+00:00 uploaded backups/db/development/2026-10-08.sql.gz (0.26 MB, dump 2.0s)
# downloaded with S3Service::get_private_to_file() -> 277390 bytes
$ gunzip -t 2026-10-08.sql.gz && gunzip -k 2026-10-08.sql.gz  # 2437599 bytes
$ tail -1 2026-10-08.sql
-- Dump completed on 2026-10-08 11:01:56
$ mysql -e "CREATE DATABASE contentos_restore_test CHARACTER SET utf8mb4"
$ mysql contentos_restore_test < 2026-10-08.sql               # 0.8 s
$ mysql -e "SELECT ... restored vs live"
restored_users  live_users  restored_tables  live_tables
8               9           96               96
$ mysql -e "DROP DATABASE contentos_restore_test"
```

All 96 tables came back. The one-user difference is a throwaway test account another session created on dev
the second the dump ran (user 3054), after the dump's snapshot; it has since been deleted.

## Web server changes (pending, Daniel)

Security headers (HSTS, nosniff, Referrer-Policy), the single X-Frame-Options, the one-hop http/apex to
https://www redirect without `:443`, and 301s for `/index.php*` and `/compare` are in
`docs/ops/vhost-creatorlinkstudio.conf`, with the apply, validate (`sudo apache2ctl configtest`), reload
(`sudo systemctl reload apache2`) and rollback steps. Never edit the .htaccess files or Apache on dev for this.
