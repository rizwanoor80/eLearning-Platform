# Tenancy runbook — skeleton

Written in cycle 28a (R284) from the spike; **a skeleton**: sections marked _(28b)_ to _(28f)_ are filled by the sub-cycle that builds the thing. Mechanism and measurements: ADR-032. Product frame: ADR-031, PRD section 13.

**Never put a value in this file, in a commit, in STATUS or in a log.** Variables are listed by name only. This repository is public.

---

## 1. Model in one paragraph

A tenant is one TrusTutor instance: a PostgreSQL database holding the v1 schema. The registry (`tenants`, `tenant_domains`) lives in the **central** database. A request names its tenant by host (`IdentifyTenant`); a queued job carries `tenant_id` in its payload; a scheduled command runs once per tenant through `tenants:each`. Switching tenant re-points the `pgsql` connection in place (same Connection object, so holders follow); with no tenant the connection points at the database `__no_tenant__`, which does not exist, so a query outside a tenant fails instead of reading someone's data.

## 2. Environment (names only)

| Name | Meaning |
|---|---|
| `TENANCY_ENABLED` | `false` leaves the application exactly as v1 (single database). With `true` the application refuses to boot unless: `DB_URL` is unset, `DB_CENTRAL_DATABASE` is set, the cache is not `file`, and the queue is not `database` (use redis). `SESSION_DOMAIN` must stay unset. |
| `DB_CENTRAL_DATABASE` | The central registry database. Created by the owner in Forge _(Owner action 27)_. |
| `TENANCY_DATABASE_PREFIX` | Prefix for tenant database names (default `tenant_`). |
| `TENANCY_TEMPLATE_DATABASE` | Optional database cloned by `tenants:create` instead of migrating. |

The database role needs `CREATEDB` to create tenant databases. A role Forge creates does not have it: owner action _(28b)_.

## 3. Commands

| Command | Does |
|---|---|
| `php artisan tenants:create <slug> [--name=] [--host=] [--template=]` | Creates the database (migrated, or cloned from a template), the tenant row and its primary domain. |
| `php artisan tenants:migrate [--tenant=<slug>]` | Runs the pending migrations (never seeders) on every active or suspended tenant (or one). Safe to run twice. A slug that matches nothing fails. |
| `php artisan tenants:each "<artisan command>" [--tenant=<slug>]` | Runs a command once per active tenant; carries on after one fails; exits 1 and names the failures. |
| `php artisan migrate --database=central --path=database/migrations/central --force` | Registry migrations. |

All run with `--no-interaction` in scripts.

A bare `php artisan migrate`, `migrate:fresh`, `db:seed` and the like **refuse to run** on a tenancy-enabled server while no tenant is set (they would target the non-existent `__no_tenant__` database). Use `tenants:migrate` for tenants and `--database=central` for the registry. `tenants:create` creates the tenant as `provisioning`, migrates it, then marks it `active`; if anything fails it removes what it created.

Operator how-to: create `php artisan tenants:create acme --name="Acme" --host=acme.example --no-interaction`; migrate all `php artisan tenants:migrate --no-interaction`; run a command for every tenant `php artisan tenants:each "<command>" --no-interaction`.

## 4. Deploy order _(28b)_

1. Central migrations. 2. `tenants:migrate`. 3. Reload workers (they hold a tenant only while a job runs). Per-tenant R213 checks replace the single-instance ones _(28b)_.

## 5. Adopting the v1 database as tenant one _(28b, R291)_

`tenants:adopt trustutor` registers the existing database as tenant `trustutor` without copying data. Gated on the owner's GO. Steps, rollback and the landlord-default rule are written when the command exists.

## 6. Connections and capacity

One tenant connection and one central connection per PHP process, regardless of tenant count. PostgreSQL `max_connections` (100 by default) therefore bounds PHP-FPM children plus Horizon processes, not tenants. If it is tight, put PgBouncer in transaction mode in front; the tenancy code uses no session-level state.

Measured (ADR-032): migrate-all with everything applied is 0.4 s for 2 tenants, 0.8 to 0.9 s for 20; a new tenant by migration is 0.8 s, by template clone 65 to 216 ms.

## 7. Queues, scheduler, cache _(28c)_

The queue driver must be redis: the `jobs` table is per tenant. `failed_jobs` and `job_batches` are pinned to the central database, so a job that fails (including one refused because its tenant is suspended) is recorded with no tenant set and can be retried with `queue:retry` once the tenant is back. A worker restores its previous tenant after each job. The file cache driver is refused at boot. Octane is unsupported.

One shared queue for all tenants; a payload without `tenant_id` is refused. Scheduled commands go through `tenants:each` (28c adds the unique per-tenant job). Cache keys carry the tenant in their prefix. Failed-job and batch tables and the scheduler's lock live in the central database _(28c)_.

## 8. Domains and Caddy _(28d)_

## 9. Signup and the super-admin _(28e)_

## 10. Metering and invoices _(28f)_

## 11. Suspending, closing, restoring a tenant _(28e)_

## 12. Backups and restore, per tenant _(28b)_

## 13. Known risks carried from the spike

Shared private disk path `tutor-documents/{profile_id}` and unscoped broadcast channels (`App.Models.User.{id}`) (28c); Horizon's gate reads tenant users; PgBouncer in transaction mode breaks server-side prepared statements (enable emulated prepares or use session mode).

Tenant-scoped Daily room names and Stripe idempotency keys (28c); landlord default connection after adoption (28b); super-admin host skips `IdentifyTenant` (28e); Redis-shared cache and locks (28c). Detail in ADR-032.
