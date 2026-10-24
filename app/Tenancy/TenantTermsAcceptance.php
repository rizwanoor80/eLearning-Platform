<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tenant's acceptance of one version of the platform terms (28e records it at signup). */
class TenantTermsAcceptance extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('tenancy.central_connection');
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
