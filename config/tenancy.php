<?php

/*
| Tenancy (cycle 28, ADR-032). One tenant = one PostgreSQL database holding the unchanged v1 schema; the
| registry (tenants, domains) lives in the `central` database. While `enabled` is false the application
| behaves exactly as v1: one database, no host resolution, no guard.
*/
return [

    'enabled' => (bool) env('TENANCY_ENABLED', false),

    // The connection the application's models use. It is repointed at the tenant's database on every switch and
    // purged, so a process holds one tenant connection at a time. It keeps this one name on purpose: queued models
    // serialise the connection name (SerializesModels), and a per-tenant name would not survive the queue.
    'tenant_connection' => 'pgsql',

    // The registry connection (config/database.php). Tenant-agnostic tables — queue failures, scheduler locks —
    // are pinned here too (28c), never to the tenant connection.
    'central_connection' => 'central',

    // With no tenant set the tenant connection points here, a database that does not exist: code that runs before
    // a tenant is initialised, or after tenancy ended, fails instead of reading some tenant's data.
    'no_tenant_database' => '__no_tenant__',

    // Database name for a new tenant: prefix + slug.
    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'tenant_'),

    // Optional template database a new tenant is cloned from (CREATE DATABASE ... TEMPLATE). Null = run the migrations.
    'template_database' => env('TENANCY_TEMPLATE_DATABASE'),

    // Payload key carrying the tenant id on every queued job.
    'payload_key' => 'tenant_id',
];
