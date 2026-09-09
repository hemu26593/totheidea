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

## 5. HTTPS

Terminate TLS at the web server or the load balancer and redirect port 80.
`SESSION_SECURE_COOKIE` defaults to **true** whenever `APP_ENV=production`, so
the session cookie will not be sent over plain HTTP — which also means the
application is unusable over HTTP in production, by design. Behind a proxy, set
`TRUSTED_PROXIES` (section 2) or Laravel will not believe the request is secure
and will generate `http://` URLs.

## 6. Queue worker

Three jobs are queued: notification dispatch, day-plan carry-forward, and AI
form generation. The default connection is `database`, so no broker is needed.

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

- **`--tries=3`** is the worker-level default. `DispatchNotificationJob` overrides
  it from `config/notifications.php` and adds its own backoff; the other two take
  the worker's value.
- **Restart the worker on every deploy** (`php artisan queue:restart`), or it
  keeps running the old code.
- **`DB_QUEUE_RETRY_AFTER`** (default 90s) must exceed the longest job's runtime,
  or a still-running job is handed to a second worker.
- Retries are safe. Every job is idempotent: notification dispatch is keyed on
  `dedupe_key` with a unique index, carry-forward only ever sees `planned` items
  and settles each one as it copies it, and AI generation stops at a draft that a
  second person must approve.
- Failures are visible in three places: the `failed_jobs` table, the dispatch's
  own `status`/`error`/`attempts` columns, and the Notifications screen.

## 7. Scheduler

One cron entry, which is all Laravel needs:

```cron
* * * * * cd /var/www/bmp && php artisan schedule:run >> /dev/null 2>&1
```

It drives two commands, both registered in `routes/console.php`:

| Command | Cadence | Notes |
|---|---|---|
| `bmp:notifications:sweep` | hourly | Finds due notifications and queues them. Running it often is safe — the dedupe key decides what has already been handled. |
| `bmp:day-plans:carry-forward` | daily 00:15 | Moves unfinished tasks to the next day. Re-running cannot duplicate a task. |

Both use `withoutOverlapping()`. Run the cron as the same user that owns
`storage/`, or the scheduler's lock files land with the wrong owner.

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

The notification pipeline is complete — eligibility, scheduling, deduplication,
queueing, retry, and logging all work — but **no delivery channel is bound**.
`ChannelDispatcher` resolves to `UnconfiguredChannelDispatcher`, which throws, so
every dispatch will land in `failed` with a clear reason until a real dispatcher
is bound in `AppServiceProvider`.

This is deliberate: a no-op dispatcher would record sends that never happened.
Configuring `MAIL_*` alone does **not** turn notifications on — binding a
dispatcher is a code change and is the one remaining piece of that feature.

WhatsApp delivery is explicitly out of scope.

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
