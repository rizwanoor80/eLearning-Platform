<?php

namespace App\Models;

use Database\Factories\OpsStatusTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The bearer token for `GET /ops/status` (R193). Holds a SHA-256 hash only — the plaintext is
 * generated and shown once by `IssueOpsStatusToken` and exists nowhere else. At most one row is
 * active (`revoked_at` null); the others are history. `allowed_ips` is an optional list of exact
 * addresses and CIDR ranges; empty or null means any address.
 *
 * @property int $id
 * @property string $token_hash
 * @property list<string>|null $allowed_ips
 * @property int|null $created_by
 * @property int|null $revoked_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OpsStatusToken extends Model
{
    /** @use HasFactory<OpsStatusTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected $guarded = [];

    public static function hashOf(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param  Builder<OpsStatusToken>  $query
     * @return Builder<OpsStatusToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** The one active token, if there is one. */
    public static function current(): ?self
    {
        return static::query()->active()->first();
    }

    /** Whether the caller's address passes this token's allow-list (no list means any address). */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips;

        if ($allowed === null || $allowed === []) {
            return true;
        }

        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, $allowed);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
