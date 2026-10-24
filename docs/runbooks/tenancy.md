# Tenancy runbook — skeleton

Written in cycle 28a (R284) from the spike; **a skeleton**: sections marked _(28b)_ to _(28f)_ are filled by the sub-cycle that builds the thing. Mechanism and measurements: ADR-032. Product frame: ADR-031, PRD section 13.

**Never put a value in this file, in a commit, in STATUS or in a log.** Variables are listed by name only. This repository is public.

---

## 1. Model in one paragraph

A tenant is one TrusTutor instance: a PostgreSQL database holding the v1 schema. The registry (`tenants`, `tenant_domains`) lives in the **central** database. A request names its tenant by host (`IdentifyTenant`); a queued job carries `tenant_id` in its payload; a scheduled command runs once per tenant through `tenants:each`. Switching tenant re-points the `pgsql` connection in place (same Connection object, so holders follow); with no tenant the connection points at the database `__no_tenant__`, which does not exist, so a query outside a tenant fails instead of reading someone's data.

## 2. Environment (names only)

| Name | Meaning |
|---|---|
| `TENANCY_ENABLED` | `false` leaves the application exactly as v1 (single database). With `true` the application refuses to boot unless: `DB_URL` is unset, `DB_CENTRAL_DATABASE` is set, the cache is neither `file` nor `database`, the session driver is neither `file` nor `cookie`, and the queue is not `database` (use redis for cache and queue; database or redis for sessions). `SESSION_DOMAIN` must stay unset. |
| `DB_CENTRAL_DATABASE` | The central registry database. Created by the owner in Forge _(Owner action 27)_. |
| `TENANCY_DATABASE_PREFIX` | Prefix for tenant database names (default `tenant_`). |
| `TENANCY_TEMPLATE_DATABASE` | Optional database cloned by `tenants:create` instead of migrating. |

The database role needs `CREATEDB` to create tenant databases (`tenants:create` fails and removes what it made if it lacks it). A role Forge creates does not have it; granting it is an owner action when the first new tenant is wanted, not before: adopting the existing database creates nothing.

## 3. Commands

| Command | Does |
|---|---|
| `php artisan tenants:create <slug> [--name=] [--host=] [--template=] [--admin-email=] [--admin-name=]` | Creates the database (migrated, or cloned from a template), seeds the reference data (curricula, subjects, price bands, settings; never demo data), the tenant row and its primary domain, and with `--admin-email` the first administrator. Their password is a random one nobody knows; they set their own through "forgot password". |
| `php artisan tenants:adopt <slug> --from=current --host=<host> [--name=]` | Registers the database the application used before tenancy (`DB_DATABASE`) as a tenant without copying or changing a row of it. See section 5. |
| `php artisan tenants:unregister <slug> --yes` | Removes a tenant from the registry (host and contact rows). The tenant database is never dropped or changed. |
| `php artisan tenants:migrate [--tenant=<slug>]` | Runs the pending migrations (never seeders) on every active or suspended tenant (or one). Safe to run twice. A slug that matches nothing fails. |
| `php artisan tenants:each "<artisan command>" [--tenant=<slug>]` | Runs a command once per active tenant; carries on after one fails; exits 1 and names the failures. |
| `php artisan migrate --database=central --path=database/migrations/central --force` | Registry migrations. |

All run with `--no-interaction` in scripts.

A bare `php artisan migrate`, `migrate:fresh`, `db:seed` and the like **refuse to run** on a tenancy-enabled server while no tenant is set (they would target the non-existent `__no_tenant__` database). Use `tenants:migrate` for tenants; the registry migrates with `--database=central --path=database/migrations/central` (the guard requires a `--path` and another connection; it does not check their values, so name them exactly). `tenants:create` creates the tenant as `provisioning`, migrates it, then marks it `active`; if anything fails it removes what it created.

Operator how-to: create `php artisan tenants:create acme --name="Acme" --host=acme.example --no-interaction`; migrate all `php artisan tenants:migrate --no-interaction`; run a command for every tenant `php artisan tenants:each "<command>" --no-interaction`.

## 4. Deploy order

1. Once, before the first deploy that carries tenancy: create the central database in Forge and set `DB_CENTRAL_DATABASE` (name only here; Owner action 27). The application refuses to boot with `TENANCY_ENABLED=true` and no central database.
2. `php artisan migrate --database=central --path=database/migrations/central --force --no-interaction` (the registry; run it first, a tenant migration never needs it).
3. `php artisan tenants:migrate --force --no-interaction` (every active or suspended tenant; safe to run twice).
4. Reload the workers (they hold a tenant only while a job runs).

Verification is R213 through `GET /ops/status` on each tenant's host: `app.tenant` names the tenant, `migrations.pending` is 0 for it, and `migrations.central_pending` is 0 (it is `null` only when tenancy is off). A host that is unknown or not verified answers 404; a tenant that is not active answers 503.

While `TENANCY_ENABLED=false` the application is exactly v1 and none of the above applies.

## 5. Adopting the v1 database as tenant one _(28b, R291)_

`tenants:adopt` registers the database the application already uses as a tenant. Nothing in it is copied, migrated or changed: the command writes only the central registry (the tenant, its verified primary host and one contact row per administrator it finds). Gated on the owner's GO (R291).

```
php artisan tenants:adopt trustutor --from=current --host=<the host the instance answers on> --no-interaction
```

It refuses: any `--from` but `current`; no `--host`; a database that is already a tenant's; the central registry; a database that does not exist; one that is not a TrusTutor database (no `users`, `lessons`, `ledger_entries` and `migrations` tables), in which case it leaves the database alone and registers nothing.

Afterwards: the host answers through the tenant path, `php artisan tenants:each "ledger:verify" --no-interaction` is green, and R213 on the host shows the tenant slug and 0 pending.

**Rollback** (the database is never touched): `php artisan tenants:unregister trustutor --yes --no-interaction`. The host then answers 404 until `TENANCY_ENABLED=false` is set or the tenant is adopted again.

The landlord default: after adoption `DB_DATABASE` still names the tenant's database, but with tenancy on the tenant connection is re-pointed at `__no_tenant__` outside a tenant, so a stray query fails instead of reading it. Do not point `DB_DATABASE` anywhere else while tenancy is on: `tenants:adopt` reads it, and an adopted database must still be the one the instance had.

## 6. Connections and capacity

One tenant connection and one central connection per PHP process, regardless of tenant count. PostgreSQL `max_connections` (100 by default) therefore bounds PHP-FPM children plus Horizon processes, not tenants. If it is tight, put PgBouncer in transaction mode in front; the tenancy code uses no session-level state.

Measured (ADR-032): migrate-all with everything applied is 0.4 s for 2 tenants, 0.8 to 0.9 s for 20; a new tenant by migration is 0.8 s, by template clone 65 to 216 ms.

## 7. Queues, scheduler, cache _(28c)_

The queue driver must be redis: the `jobs` table is per tenant. `failed_jobs` and `job_batches` are pinned to the central database, so a job that fails (including one refused because its tenant is suspended) is recorded with no tenant set and can be retried with `queue:retry` once the tenant is back. A worker restores its previous tenant after each job. The file and database cache drivers are refused at boot. Octane is unsupported.

One shared queue for all tenants; a payload without `tenant_id` is refused. Scheduled commands go through `tenants:each` (28c adds the unique per-tenant job). Cache keys carry the tenant in their prefix. Failed-job and batch tables and the scheduler's lock live in the central database _(28c)_.

## 8. Domains and Caddy _(28d)_

## 9. Signup and the super-admin _(28e)_

## 10. Metering and invoices _(28f)_

## 11. Suspending, closing, restoring a tenant _(28e)_

## 12. Backups and restore, per tenant

A tenant is one database plus its private disk, so a tenant is backed up and restored on its own. The central database holds the registry (and later the billing records) and is backed up with the same schedule.

Back up: every tenant database and the central database, daily, off the server (Forge's database backups list databases; add each tenant database when it is created, and the central one now). Retain at least 14 days. The private document disk (`storage/app/private`) is backed up beside them (production runbook section 6); documents are referenced from rows.

Restore one tenant: restore its dump into the tenant's own database name (empty first) and its private-disk backup to the disk, then run `php artisan tenants:migrate --tenant=<slug> --no-interaction` and check R213 on its host. The registry rows are untouched by a restore. A suspend/close command arrives in 28e; until then, restore outside working hours. Rehearse it on a scratch server before relying on it (production runbook section 6 drill).

## 13. Known risks carried from the spike

Shared private disk path `tutor-documents/{profile_id}` and unscoped broadcast channels (`App.Models.User.{id}`) (28c); Horizon's gate reads tenant users; PgBouncer in transaction mode breaks server-side prepared statements (enable emulated prepares or use session mode).

Tenant-scoped Daily room names and Stripe idempotency keys (28c); landlord default connection after adoption (28b); super-admin host skips `IdentifyTenant` (28e); Redis-shared cache and locks (28c). Detail in ADR-032.
