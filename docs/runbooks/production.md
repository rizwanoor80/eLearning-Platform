# Production runbook — `trustutor-production`

Written for the owner (R277, cycle 26e). Nothing here is run by Claude Code: creating, configuring or deploying `trustutor-production`, entering any key, and every live-money switch are the owner's, each under a named authorisation (HOW-WE-WORK §8).

**Never put a value in this file, in a commit, in STATUS or in a log.** Environment variables are listed by name only. This repository is public.

The rehearsal server (`trustutor-rehearsal`) is the template. Anything below that is not yet true of rehearsal is marked **(new for production)**. Every server-script change runs on rehearsal first (HOW-WE-WORK rule 10), and a failed check means rollback and no second attempt the same day.

---

## 1. Create the server and site in Forge

Server size is the owner's choice. The app needs, on one machine:

| Component | Version / note |
|---|---|
| PHP | 8.4 (no 8.3 fallback) |
| PostgreSQL | 18 (CI also runs 16; production is 18) |
| Redis | any current release; it carries the queue, cache and locks |
| Node | only to build assets at deploy time |

Steps:

1. Forge → create a server named `trustutor-production` with PHP 8.4, PostgreSQL and Redis. Keep SSH keys and the firewall as Forge sets them; never share the sudo password or any API token with anyone, Claude Code included.
2. Create the site for the production domain. Repository `rizwanoor80/eLearning-Platform`, branch **`production`** (a deploy branch separate from `rehearsal`; create it from a `main` commit whose CI is green on both PostgreSQL jobs). Install a TLS certificate; the site must answer on `https` only.
3. Create the database and a database user limited to it. Choose the password in Forge; do not reuse another environment's.
4. Add the **scheduler** and the **Horizon daemon** (sections 4 and 5).
5. Put the load balancer or proxy address (if there is one) into `TRUSTED_PROXIES` (section 2). If Forge terminates TLS itself with no balancer, leave `TRUSTED_PROXIES` present and empty.
6. Turn on Forge's database backups (section 6) before the first real user exists.

---

## 2. Environment variables (names only)

Set these in the site's environment editor. **A value is the owner's to choose; none is written down here.** Variables not listed keep the defaults in `.env.example`.

**Application**
`APP_NAME` · `APP_ENV` (must be `production`) · `APP_KEY` (generate once; keep a copy in the owner's password manager, because lost keys make stored encrypted data unreadable) · `APP_DEBUG` (must be `false`) · `APP_URL` (must be `https://…`) · `APP_LOCALE` · `APP_FALLBACK_LOCALE` · `APP_GIT_SHA` (set by the deploy script, section 3) · `LOG_CHANNEL` · `LOG_LEVEL`

**Database (PostgreSQL)**
`DB_CONNECTION` · `DB_HOST` · `DB_PORT` · `DB_DATABASE` · `DB_USERNAME` · `DB_PASSWORD`

**Redis, queue, cache, session**
`REDIS_CLIENT` · `REDIS_HOST` · `REDIS_PORT` · `REDIS_PASSWORD` · `QUEUE_CONNECTION` (must be `redis`) · `CACHE_STORE` · `SESSION_DRIVER` · `SESSION_LIFETIME` · `SESSION_SECURE_COOKIE` (must be `true`) · `SESSION_DOMAIN`

**Proxies**
`TRUSTED_PROXIES` — present on every production site. Empty means "trust none". Set it to the balancer's address or range only if one exists; the `/ops/status` IP allow-list and the rate limiters key on the address this resolves to (ADR-026).

**Mail** (Postmark, R120; **new for production**)
`MAIL_MAILER` · `MAIL_FROM_ADDRESS` · `MAIL_FROM_NAME` (must read `TrusTutor Team`, R279; confirm it in `/ops/status` after every deploy) · `POSTMARK_API_KEY` · `POSTMARK_MESSAGE_STREAM_ID`

**Files**
`FILESYSTEM_DISK` — uploads (identity and permit documents) go to the **private** disk (`storage/app/private`) and are served only by signed, expiring URLs (invariant 9). If object storage is chosen instead of the server disk: `AWS_ACCESS_KEY_ID` · `AWS_SECRET_ACCESS_KEY` · `AWS_DEFAULT_REGION` · `AWS_BUCKET` · `AWS_ENDPOINT` · `AWS_USE_PATH_STYLE_ENDPOINT`, with the bucket private.

**Real-time (Reverb)**
`BROADCAST_CONNECTION` · `REVERB_APP_ID` · `REVERB_APP_KEY` · `REVERB_APP_SECRET` · `REVERB_HOST` · `REVERB_PORT` · `REVERB_SCHEME` · `VITE_REVERB_APP_KEY` · `VITE_REVERB_HOST` · `VITE_REVERB_PORT` · `VITE_REVERB_SCHEME` (the `VITE_` ones are read at build time, so a change needs a deploy). If Reverb is not run in production at launch, set `BROADCAST_CONNECTION` to the value that keeps the 60-second poll working and say so in STATUS.

**Operations**
`ERROR_TRACKING_DSN` (the Sentry project's DSN, from the owner's Sentry account; the integration is inert while this is empty, and `ops:preflight` fails until it is set) · `ERROR_TRACKING_RELEASE` (optional; the deploy script may set it to the commit sha) · `ADMIN_EMAIL` · `ADMIN_PASSWORD` (used once by the seeder to create the real admin; use a real address, not a `.test` one, or `ops:preflight` fails by design) · `PAYMENT_LIVE_MODE_ALLOWED` (leave at its default until switch-day step 3; its only job is to be the second key to the live switch)

**Not in `.env` on purpose (invariant 16).** Payment gateway keys and the webhook signing secret, and the video provider's credentials, are entered in the admin registries (Admin → Payment gateways, Admin → Video providers). They are encrypted at rest and masked in the UI, and no one pastes them anywhere else. The Stripe row stays `test` until switch-day step 6.

**Do not set on production:** any fake payment gateway or fake video provider, `APP_DEBUG=true`, a demo-account seeder. The seeder run on production creates the admin and the reference data (curricula, subjects, price bands, settings) only; **no demo tutors or parents** (`ops:preflight` fails on `.test` accounts).

---

## 3. The deploy script

The Forge deploy script lives in Forge, not in this repository, so the rehearsal one could not be read when this runbook was written. Copy rehearsal's script, then confirm it does these things in this order (this is the intended content, written from the repository's own commands):

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

# APP_GIT_SHA is what /ops/status reports as app.git_sha; set it before caching config.
# (Set it in the way rehearsal does; if rehearsal writes it into .env, do the same.)

$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

npm ci --no-audit --no-fund
npm run build

$FORGE_PHP artisan migrate --force --no-interaction
$FORGE_PHP artisan config:cache --no-interaction
$FORGE_PHP artisan route:cache --no-interaction
$FORGE_PHP artisan view:cache --no-interaction
$FORGE_PHP artisan event:cache --no-interaction

# Horizon: finish current jobs, then the daemon (section 4) restarts it.
$FORGE_PHP artisan horizon:terminate --no-interaction
```

Notes:

- **Push-to-deploy.** A deploy is a fast-forward of the deploy branch to a `main` commit whose CI is green on both PostgreSQL jobs. Never deploy from a branch that is not on `main`.
- `migrate --force` runs on every deploy. Migrations are forward-only; a fix is a new migration, never an edit. A migration on production is a rule 8 action: name it in the log before the push.
- `storage:link` is run once when the site is created, not on every deploy (`ops:preflight` checks it).
- **No recurring attempts in flight.** On any deploy that changes charging code, deploy outside the hourly `recurring:charge` window (switch-day step 5).
- **Verify through `/ops/status`, not ssh** (R213). After every deploy read: `app.git_sha` is the commit just pushed; `migrations.pending` is 0; `horizon.status` is `running`; `queues.failed_jobs` is 0; `ledger.result` is ok with `ledger.unbalanced_lessons` and `ledger.stranded_payments` at 0; `/`, `/login`, `/tutors` and `/terms` return 200; `/ops/status` without the token returns 404; `mail.from_name` reads `TrusTutor Team`.
- **Rollback is open before any cutover.** Rolling back code is the owner's: redeploy the previous green `main` commit. Never roll back a migration; write a forward migration. A failed check after cutover means rollback and no second attempt the same day.
- **Status token (new for production).** After the first deploy, sign in to Admin → Settings → Operations and generate the status token for this server. It is shown once; store it in the owner's password manager. Set the optional IP allow-list once `TRUSTED_PROXIES` is right.

---

## 4. Horizon

Horizon is the production queue worker (`QUEUE_CONNECTION=redis`). `config/horizon.php` has a `production` environment block (up to 10 worker processes); the `rehearsal` block is smaller.

1. Forge → the server → **Daemons** → add: command `php artisan horizon`, directory the site's current path, user `forge`, processes 1.
2. The deploy script's `horizon:terminate` makes Horizon finish its current jobs and exit; the daemon starts it again on the new code. Confirm `horizon.status` reads `running` and the master pid has changed after each deploy.
3. Horizon is never run locally; this is only for servers.
4. `queues.failed_jobs` must stay at 0. A failure is read in the Horizon dashboard (`/horizon`, admins only) and the status document, and is investigated, not cleared.

---

## 5. The scheduler

The app's recurring work (permit checks, `lessons:expire-unpaid`, `recurring:generate`, `recurring:charge`, reminders, room lifecycle, auto-release, payment reconciliation, the ledger check) all runs from Laravel's scheduler.

Forge → the server → **Scheduler** → add: `php artisan schedule:run`, every minute, user `forge`.

Each command is idempotent and uses `onOneServer()` plus `withoutOverlapping()`. `recurring:generate` and `recurring:charge` are safe to run twice by design. `/ops/status` shows `scheduler.status` from a tick the scheduler writes every minute; `ops:preflight` fails if there has been no tick in the last three minutes.

---

## 6. Database backups, and the restore drill (CP8 acceptance)

Two things need backing up: **the database** and **the private document disk** (`storage/app/private`, or the bucket if object storage is used). A backup of one without the other cannot restore a working tenant, because documents are referenced from rows.

1. Forge → the server → **Backups** → add a **daily** PostgreSQL database backup to an off-server storage provider of the owner's choice. Keep at least 14 days. The backup destination's credentials are the owner's and are entered in Forge only.
2. Back up the private document disk daily to the same off-server place (for object storage, enable the bucket's versioning instead).
3. After the first real data exists, run **one restore drill** on a scratch server, and record the date:
   1. Create a throwaway Forge server with PHP 8.4 and PostgreSQL 18. Do not point it at production services, and do not give it real mail or payment keys.
   2. Download last night's database backup to it and restore it into an empty database (`pg_restore` or `psql`, according to the backup's format).
   3. Restore the private-disk backup to `storage/app/private`.
   4. Deploy the same commit as production, with `APP_ENV` not `production`, mail set to a log driver and **no gateway row `live`**.
   5. Run `php artisan migrate --no-interaction` (expect nothing to migrate) and `php artisan ledger:verify --no-interaction`. **Pass = ledger OK and an admin can sign in and open a lesson, a tutor document by signed URL and the payments page.**
   6. Destroy the scratch server and delete the restored data.
4. Record the result in STATUS and tick CHECKPOINTS CP8 "Restore from last night's backup on a scratch server succeeds". Until it is recorded, the box is not ticked.

A restore drill never touches production. Restoring over production is a deletion of production data and a rule 8 gate.

---

## 7. `ops:preflight` before first traffic

After the first deploy and before any parent or tutor can reach the site, run this read-only command from Forge's Commands tab on production:

```
php artisan ops:preflight --no-interaction
```

It lists each check with pass or fail, and for a failure the fix. The checks (from `App\Services\Ops\Preflight`) are:

APP_ENV is production · APP_DEBUG is off · APP_URL is https · APP_KEY is set · session cookies are secure · the mailer is a real transport · the queue is Redis with Horizon configured · the scheduler is ticking · the payment gateway is not the fake · a payment gateway is active · the video provider is real and active · webhook secrets are present · trusted proxies are set · no demo accounts · the storage link exists · the ledger verifies · the error-tracking DSN is set.

Every check must pass. It fails by design on local and on rehearsal. Report only the failing lines, never a value. A check that cannot be read counts as a failure, not a pass.

Run it again after any change to the environment.

---

## 8. Switch-day steps (owner, in order; none are Claude Code's)

Quoted verbatim from `docs/STATUS.md` §7:

(1) D-04 settled with counsel and `trustutor-production` authorised and deployed through a rehearsal pass (R210/R213); (2) Postmark wired (R120); (3) in Admin → Payment gateways enter the live keys and the live webhook signing secret together, with the webhook subscribed to all events including `refund.updated` (Owner action 20); (4) `payments:mode-readiness --target=live` shows no blocker; (5) deploy with no recurring attempts in flight; (6) switch the Stripe row to `live`; (7) make one small real booking with your own card, confirm capture, the ledger hold and the receipt, then refund it by hand from the platform and confirm the refund settles (`ledger:verify` green before and after); (8) only then open to parents.

Before step (1) can honestly be called done, sections 1 to 7 above are complete: the server exists, the deploy script matches rehearsal, Horizon and the scheduler run, backups are on, one restore drill has passed, and `ops:preflight` passes with no failing line.

### Other gates this runbook does not replace

- No page body still contains "DRAFT" (CHECKPOINTS CP8).
- The active video provider has real credentials entered in Admin → Video providers.
- The soft-launch checklist is signed off by Rizwan.
- `rehearsal` and `production` are never the same database, the same Redis, or the same gateway row.
