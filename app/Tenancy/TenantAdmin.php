<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The registry's record of a tenant's administrator contact; the login lives in the tenant's own database. */
class TenantAdmin extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('tenancy.central_connection');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
