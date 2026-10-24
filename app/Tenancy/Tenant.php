<?php

namespace App\Tenancy;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One TrusTutor instance: a PostgreSQL database holding the v1 schema. Lives in the central database.
 *
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('tenancy.central_connection');
    }

    protected function casts(): array
    {
        return ['status' => TenantStatus::class];
    }

    /**
     * @return HasMany<TenantDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
