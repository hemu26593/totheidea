# BMP Digital Platform — Production Runbook

Operational reference for deploying and running the BMP Digital Platform.
Architecture decisions live in [`architecture-decisions.md`](architecture-decisions.md);
binding engineering rules live in [`../CLAUDE.md`](../CLAUDE.md).

This runbook describes how to deploy. **Nothing here has been executed against a
production server**, and no production infrastructure is configured yet — there
is no backup job, no monitoring, and no host. Treat every section as a checklist
to carry out, not a description of something already running.

---

## 1. Server requirements

| | |
|---|---|
| PHP | **8.3 or later** (developed and tested on 8.4) |
| PHP extensions | `ctype` `curl` `dom` `fileinfo` `filter` `hash` `mbstring` `openssl` `pcre` `pdo` `pdo_mysql` `session` `tokenizer` `xml` `zip` `intl` |
| Database | **MySQL 8.0+** (ADR-006). InnoDB, `utf8mb4` / `utf8mb4_0900_ai_ci` |
| Web server | nginx or Apache, document root **`public/`** |
| Node | 20+ **for the build only** — not needed at runtime |
| Composer | 2.x |
| Process supervisor | systemd or supervisor, for the queue worker |
| Cron | for the scheduler |

Outbound HTTPS is required only if the AI provider or a mail provider is
enabled. The frontend build has no external network dependency (`vite.config.js`
has the skeleton's remote webfont fetch removed) — keep it that way.

## 2. Environment

Copy `.env.example` to `.env` and fill it in. It carries **names and empty
placeholders only** — never commit a real value into it.

```
APP_NAME="BMP Digital Platform"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bmp.example.com          # https, and the real host

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=bmp
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
REPORTS_DISK=local                       # MUST stay on a private disk

LOG_CHANNEL=stack
LOG_LEVEL=warning                        # `debug` in production is noise and leak risk

MAIL_MAILER=smtp                         # see section 9
MAIL_HOST=... MAIL_PORT=... MAIL_USERNAME=... MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="programme@example.com"

TRUSTED_PROXIES=                         # see below — set this behind a proxy

BMP_SUPER_ADMIN_EMAIL=ops@example.com    # NOT the example default
BMP_SUPER_ADMIN_PASSWORD=                # leave empty: one is generated and printed once

AI_PROVIDER=null                         # 'anthropic' + a key to enable generation
ANTHROPIC_API_KEY=
```

Then, on the server and only on the server:

```bash
php artisan key:generate      # writes APP_KEY into .env
```

**`APP_KEY` is not a formality.** Everything encrypted — sessions, cookies, any
encrypted column — is unreadable without it. Back it up with the database; a
restored database and a lost key together are not a recoverable system.

**`TRUSTED_PROXIES`** — leave it empty when the server is reachable directly.
Behind a load balancer or CDN, set it to the proxy's address, a comma-separated
list, or `*` when nothing can reach the app except through that proxy. Two
things depend on the caller's real address: the external form link's per-IP rate
limiting (an unread proxy makes every participant share one address, so one
abuser throttles everyone), and the IP recorded against a used access grant.

## 3. Deploy

```bash
# 1. Code
git clone <repo> /var/www/bmp && cd /var/www/bmp
git checkout <release tag>

# 2. PHP dependencies — no dev packages on a production host
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Frontend assets. public/build is NOT in version control, so this must run
#    on the deploy host (or the built directory must be shipped to it).
npm ci
npm run build

# 4. Permissions: the web user must own the two writable trees, and nothing else
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;

# 5. Public symlink for public/storage -> storage/app/public
php artisan storage:link

# 6. Schema
php artisan migrate --force        # --force: production refuses interactively

# 7. First install only — creates the initial Super Admin and prints its
#    password ONCE. Never run db:seed again on a populated database.
php artisan db:seed --force

# 8. Caches — AFTER .env is in place, never before (see the warning below)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> **Order matters at step 8.** `config:cache` stops Laravel reading `.env` at
> all. Cache before the environment file is complete and the application runs on
> config defaults, silently. If you change `.env` afterwards, re-run
> `config:cache` — nothing else picks the change up.

Zero-downtime deploys (Envoyer, Deployer, a symlinked `current/`) work normally.
Keep `storage/` and `.env` outside the release directory and symlink them in.

## 4. Web server

nginx, the relevant parts:

```nginx
server {
    listen 443 ssl http2;
    server_name bmp.example.com;
    root /var/www/bmp/public;          # public/ — NEVER the project root

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 20 MB uploads are accepted by the application; the web server has to agree
    client_max_body_size 21m;

    location ~ /\.(?!well-known).* { deny all; }
}
```

Also set `upload_max_filesize` and `post_max_size` to at least `21M` in
`php.ini`, or the 20 MB document limit the application enforces is unreachable.

**Serving the project root instead of `public/` would expose `.env`.** It is the
single most damaging web-server misconfiguration available; check it explicitly.

## 4a. GoDaddy shared hosting

Shared hosting differs from a VPS in ways that decide the deployment, so treat
this section as authoritative over section 3 where they disagree.

### What is NOT available

| | |
|---|---|
| Composer on the server | often absent → run `composer install --no-dev` **locally** and upload `vendor/` |
| Node / npm on the server | absent → run `npm run build` **locally** and upload `public/build/` |
| A process supervisor | absent → the queue runs from cron (section 6) |
| `proc_open` | frequently disabled → why the scheduler uses `Schedule::call()` (section 7) |
| Root / a chosen PHP binary | use the cPanel PHP selector; set it to **8.3 or later** |

`public/build/` is **not** in version control, so a `git pull` alone never
produces a working front end. Build it and ship it.

### Directory layout

The application must sit **outside** the document root, with only `public/`
inside it. Two ways to get there:

**Preferred — repoint the document root.** In cPanel, set the domain's document
root to `bmp/public`. Nothing else changes and `public/index.php` works as
shipped.

```
/home/USER/
├── bmp/                     ← the application, NOT web-reachable
│   ├── app/  bootstrap/  config/  database/  resources/  routes/  storage/  vendor/
│   ├── artisan
│   ├── .env                 ← outside the document root: unreachable by URL
│   └── public/              ← cPanel document root points HERE
│       ├── index.php  .htaccess  favicon.ico  robots.txt
│       └── build/           ← uploaded, not built on the server
└── public_html/             ← left unused, or used by another site
```

**Fallback — if the document root cannot be changed.** Put the application in
`/home/USER/bmp` as above, move the *contents* of `public/` into `public_html/`,
and edit the two paths in `public_html/index.php`:

```php
require __DIR__.'/../bmp/vendor/autoload.php';
$app = require_once __DIR__.'/../bmp/bootstrap/app.php';
```

(also adjust the `maintenance.php` path on line 9). This edit must be re-applied
after every deploy that replaces `index.php`, which is why the first option is
preferred.

> **Never put the whole application inside `public_html`.** Doing so puts `.env`
> — database credentials, `APP_KEY`, mail password — one URL away. It is the
> single most damaging mistake available on this platform.

### Writable directories

```bash
chmod -R 775 storage bootstrap/cache
```

Nothing else needs to be writable. On cPanel the web user and the FTP/SSH user
are usually the same account, so ownership is normally already correct.

### `storage:link`

**Not required by this application.** Nothing is served from the public disk —
no `Storage::url()`, no `asset('storage/…')` anywhere in the codebase. Documents
and reports live on the private disk and are streamed by a controller that checks
ownership and a policy first. If a shared host blocks symlinks, nothing breaks.

### `.htaccess`

The one that ships in `public/` is the standard Laravel front-controller rewrite
and is all that is needed, provided `mod_rewrite` is enabled (it is, on GoDaddy
Linux plans). If the host requires a PHP version selector directive, cPanel adds
its own `AddHandler` line — leave it in place.

### PHP settings to check in the cPanel PHP selector

| Setting | Value | Why |
|---|---|---|
| PHP version | **8.3+** | `composer.json` requires `^8.3` |
| `upload_max_filesize` | ≥ 21M | the app accepts 20 MB documents |
| `post_max_size` | ≥ 21M | same |
| `memory_limit` | ≥ 256M | report generation |
| `max_execution_time` | ≥ 120 | report generation and the cron worker |

Required extensions: `ctype curl dom fileinfo filter hash iconv json libxml
mbstring openssl pcre pdo pdo_mysql session tokenizer xml`. All are standard on
cPanel.

## 5. HTTPS

Terminate TLS at the web server or the load balancer and redirect port 80.
`SESSION_SECURE_COOKIE` defaults to **true** whenever `APP_ENV=production`, so
the session cookie will not be sent over plain HTTP — which also means the
application is unusable over HTTP in production, by design. Behind a proxy, set
`TRUSTED_PROXIES` (section 2) or Laravel will not believe the request is secure
and will generate `http://` URLs.

## 6. Queue worker

Three jobs are queued: notification dispatch, day-plan carry-forward, and AI
form generation. The connection is `database`, so no broker is needed. Nothing
in the queue path uses `proc_open` — jobs run inside the worker process.

### On a VPS or anywhere with a process supervisor

```ini
# /etc/supervisor/conf.d/bmp-worker.conf
[program:bmp-worker]
command=php /var/www/bmp/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/var/www/bmp
user=www-data
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/bmp/worker.log
stopwaitsecs=3600
```

### On shared hosting (GoDaddy) — cron, because there is no supervisor

A long-running daemon is not available: there is no supervisor, and shared hosts
kill long processes. Drive the queue from cron instead, with a worker that
**exits when the queue is empty**:

```cron
*/5 * * * * cd /home/USER/bmp && /usr/local/bin/php artisan queue:work --stop-when-empty --tries=3 --max-time=240 >> storage/logs/queue.log 2>&1
```

- `--stop-when-empty` makes the worker drain the queue and exit, so it never
  becomes a long-running process.
- `--max-time=240` caps it below the 5-minute cron interval, so two workers
  never overlap.
- Every job is idempotent, so a job picked up twice cannot double-write.
- **Latency is the cron interval.** A notification queued at 10:01 sends at
  10:05. That is the trade shared hosting imposes; tighten the interval if the
  plan allows it.

Restart the worker on every deploy (`php artisan queue:restart`) or it keeps
running the old code. `DB_QUEUE_RETRY_AFTER` (default 90s) must exceed the
longest job's runtime.

Failures are visible in three places: the `failed_jobs` table, the dispatch's own
`status`/`error`/`attempts` columns, and the Notifications screen.

## 7. Scheduler

One cron entry drives everything:

```cron
* * * * * cd /home/USER/bmp && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

| Command | Cadence | Notes |
|---|---|---|
| `bmp:notifications:sweep` | hourly, minute 0 | Finds due notifications and queues them. Safe to run often — the dedupe key decides what has already been handled. |
| `bmp:day-plans:carry-forward` | daily at 00:15 | Moves unfinished tasks to the next day. Re-running cannot duplicate a task. |

**Both are registered with `Schedule::call()`, not `Schedule::command()`, and
that is a hosting requirement rather than a preference.** `Schedule::command()`
launches the command in a separate process via Symfony's Process, which refuses
to start when `proc_open` is disabled — and it does that on the foreground path,
not only under `runInBackground()`. Shared hosting routinely disables
`proc_open`. Registered the way they are, both tasks run inside the same PHP
process and need nothing beyond PHP itself.
`SharedHostingSchedulerTest` fails if that ever regresses.

### If your plan cannot run cron every minute

Laravel's scheduler only fires a task when `schedule:run` happens to execute
during that task's due minute. A coarse cron silently skips tasks:

| Cron interval | `bmp:notifications:sweep` (minute 0) | `bmp:day-plans:carry-forward` (00:15) |
|---|---|---|
| every minute | runs | runs |
| every 5 min (`*/5`) | runs | runs |
| every 15 min (`*/15`) | runs | runs |
| every 30 min (`*/30`) | runs | **never runs** |
| hourly at :00 | runs | **never runs** |

If the plan cannot hit 00:15, do not leave it broken — call the commands from
cron directly and skip the scheduler for them:

```cron
0  * * * * cd /home/USER/bmp && /usr/local/bin/php artisan bmp:notifications:sweep    >> storage/logs/schedule.log 2>&1
15 0 * * * cd /home/USER/bmp && /usr/local/bin/php artisan bmp:day-plans:carry-forward >> storage/logs/schedule.log 2>&1
```

Both commands are idempotent, so this loses only `withoutOverlapping()` — which
the commands do not depend on for correctness.

Use the absolute path to the PHP binary cPanel gives you (often
`/usr/local/bin/ea-php83`), not a bare `php`, which on cPanel often resolves to
an older version.

## 8. Storage

| Path | Contents | Reachable by URL? |
|---|---|---|
| `storage/app/private/documents/{customer}/` | uploaded documents | **No** |
| `storage/app/private/reports/…` | generated PDF/CSV artifacts | **No** |
| `storage/app/public/` | nothing today | Yes, via `public/storage` |

Both documents and reports are written to the **private** disk and served only
through a controller that re-checks ownership and consults a policy. Filenames on
disk are generated by Laravel, so a participant's filename never becomes part of
a path.

**Never set `REPORTS_DISK=public`.** A report aggregates a whole business's
position; the public disk is published by `storage:link` and needs no
authorization to read.

Back up `storage/app/private` — it holds the only copy of every uploaded
document. Report artifacts are regenerable from the database, but restoring them
is cheaper than regenerating.

## 9. Mail and notifications

Both paths are email, and both work once SMTP is configured. They are separate
mechanisms, and it is worth knowing which is which.

### Required environment variables

```
MAIL_MAILER=smtp
MAIL_HOST=smtpout.secureserver.net       # GoDaddy, or any provider
MAIL_PORT=465
MAIL_USERNAME=programme@yourdomain.com
MAIL_PASSWORD=...                        # the mailbox password
MAIL_ENCRYPTION=ssl                      # or tls on port 587
MAIL_FROM_ADDRESS="programme@yourdomain.com"
MAIL_FROM_NAME="BMP Digital Platform"

NOTIFICATIONS_CHANNEL=mail               # 'none' switches BMP notifications off
```

Nothing about a provider is compiled in. `MailChannelDispatcher` hands the
message to Laravel's Mail layer, so any transport Laravel supports works —
GoDaddy's own SMTP, or SES, Postmark, Mailgun, Brevo. An external provider is
worth preferring: shared-hosting SMTP carries low sending limits and a shared
reputation.

Ports and encryption vary by provider — GoDaddy commonly uses 465/SSL or
587/TLS. Confirm the values in cPanel rather than assuming them.

> **`config/mail.php` defaults to `MAIL_MAILER=log`.** Leave it unset and mail is
> written into `storage/logs/laravel.log` instead of being sent — including
> password-reset tokens. In production the notification dispatcher refuses to
> run on a non-delivering mailer (`log`, `array`, `null`) rather than record a
> send that did not happen, so notifications fail loudly instead of silently.
> Password reset has no such guard: it would quietly log. **Set `MAIL_MAILER`.**

### Password reset

Uses Laravel's own `Notifiable` / `ResetPassword` path. Independent of the BMP
channel: it keeps working with `NOTIFICATIONS_CHANNEL=none`, and a failing BMP
transport does not affect it. Without it a locked-out administrator has no
self-service recovery.

### BMP notifications — the nine SOW triggers

Delivered by `MailChannelDispatcher` through `DispatchNotificationJob`:

```
trigger → candidate → dedupe → notification_dispatches row (pending)
        → queued job → Mail → transport
        → sent (with the transport's Message-ID) or failed (with the reason)
```

- **Email only.** WhatsApp, SMS and push are out of scope; a dispatch for a
  channel with no driver is a recorded failure, never a quiet success.
- **Sent is the transport's word.** The row is marked sent only after Mail has
  handed the message over, and it stores that transport's own Message-ID.
- **Failures retry** per `config/notifications.php`
  (`NOTIFICATIONS_MAX_ATTEMPTS`, default 3, backoff 60/300/900s), stay `pending`
  while attempts remain, then settle at `failed` with the reason.
- **The address is the snapshot.** Mail goes to `address_used` as recorded when
  the dispatch was created, never a fresh lookup — so the row says where the
  message actually went.
- **The email carries no business data.** It says something needs attention and
  names the business it concerns. No figure, score, balance, attendance mark or
  assignment title is put in an email: an inbox is not somewhere the platform
  controls, and a message that carries no business data cannot carry the wrong
  business's. Staff recipients get a link to the platform; customer contacts do
  not, because participants have no account.

### Switching notifications off

`NOTIFICATIONS_CHANNEL=none` binds the dispatcher that refuses. Every dispatch is
then recorded as `failed` with the reason. That is deliberate: a no-op reporting
success would put sends that never happened into the one table that answers "did
we actually contact this business?".

### Verifying it on the server

```bash
php artisan tinker --execute="Mail::raw('BMP smtp check', fn(\$m) => \$m->to('you@yourdomain.com')->subject('BMP smtp check'));"
```

If that arrives, notifications will send. Then watch the Notifications screen, or:

```sql
SELECT status, COUNT(*) FROM notification_dispatches GROUP BY status;
```

## 10. Backups

**Nothing is configured. This is the operator's to set up before go-live.**

Minimum:

| What | Why | Suggested |
|---|---|---|
| MySQL database | everything except uploaded files | `mysqldump --single-transaction --routines` nightly, retained 30 days, plus binlogs if point-in-time recovery is wanted |
| `storage/app/private` | uploaded documents — **the only copy** | nightly file-level backup, retained 30 days |
| `.env` (and `APP_KEY`) | without the key, encrypted data is unreadable | stored in a secrets manager, **not** alongside the database dump |

Keep at least one copy off the application host. A backup on the server that
died is not a backup.

**Restore procedure**

```bash
php artisan down                                  # stop traffic
mysql bmp < backup.sql                            # 1. database
rsync -a backup/private/ storage/app/private/     # 2. documents
# 3. restore .env (APP_KEY must be the one that encrypted the data)
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan migrate --force                       # 4. any newer migrations
php artisan queue:restart
php artisan up
```

Then run the smoke test in section 12. **Rehearse a restore before go-live** — an
untested backup is a hypothesis.

## 11. Rollback

```bash
php artisan down
git checkout <previous tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

**Migrations do not roll back cleanly and should not be assumed to.** If the bad
release added a migration, prefer rolling forward with a fix. If you must go
back, restore the database from the pre-deploy backup rather than running
`migrate:rollback` against production data.

Take a database backup **immediately before every deploy** that carries a
migration.

## 12. Smoke test

After any deploy or restore, signed in as an Admin:

1. `/login` — sign in; `/dashboard` renders with figures.
2. `/customers` → open a customer → each workspace tab loads: Enrolments, Forms,
   Sessions, Assignments, Attendance, Day Plan, Time Grid, MMD, Fund Plan,
   Action Plan, HR & Systems, Documents, Notes, Reports, AI.
3. `/batches`, `/sessions`, `/assignments`, `/attendance`, `/reports`,
   `/notifications`, `/ai/generations` all load.
4. Upload a small document, then download it back.
5. Export a report, then download it.
6. Issue an access grant and open `/external/forms/{token}` **in a private
   window**: the form renders, submits, shows the confirmation, and the same link
   then returns 404.
7. The submission appears in the internal app against the right business.
8. Sign in as Staff: `/users` returns 403.
9. `storage/logs/laravel.log` has no new errors; the browser console is clean.

Then confirm the background side:

```bash
php artisan queue:work --once --stop-when-empty   # a worker can claim a job
php artisan schedule:list                          # both commands, sane next-run times
php artisan about                                  # env=production, debug=false, caches on
```

## 13. Super Admin accounts

**Provision at least two Super Admins before go-live.**

A Super Admin cannot demote, deactivate or delete themselves, and the last
Super Admin cannot be demoted or deactivated by anyone (ADR-012). With exactly
one Super Admin account, losing access to it means losing the ability to
administer roles at all — the guard that protects you becomes the thing that
locks you out.

```bash
php artisan bmp:promote-super-admin <email>
```

Do not weaken or bypass the guard to work around a single-account situation;
create the second account instead.

## 14. Logs and monitoring

- Application: `storage/logs/laravel.log` (`stack` → `single`). Set
  `LOG_LEVEL=warning` in production; rotate with logrotate.
- Queue worker: wherever supervisor writes it (above).
- Administrative and security actions are in the `audit_logs` table, not the log
  file — including every access-grant issue, use and refusal.
- `/up` is a health-check endpoint suitable for a load balancer.

Nothing is wired to an alerting system. At minimum, alert on: the worker process
dying, `failed_jobs` growing, and 5xx rate.
