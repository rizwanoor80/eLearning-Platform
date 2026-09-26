<?php

namespace App\Models;

use App\Enums\VideoProviderCode;
use Database\Factories\VideoProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One video-provider registry entry (PRD §12, invariant 16). Credentials are an encrypted array,
 * hidden from every serialisation so they can never reach an Inertia prop, a JSON response or a
 * log line built from `toArray()`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<string, string>|null $credentials
 * @property bool $supports_embed
 * @property bool $supports_attendance_webhooks
 * @property bool $is_active
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VideoProvider extends Model
{
    /** @use HasFactory<VideoProviderFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'credentials', 'supports_embed', 'supports_attendance_webhooks', 'is_active', 'updated_by'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        // Below the admin UI, so a seeder, tinker or a test hits the same rule: an active row
        // must be one that can run.
        static::saving(function (VideoProvider $provider): void {
            if ($provider->is_active && ($problem = $provider->activationProblem()) !== null) {
                throw new LogicException($problem);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'supports_embed' => 'boolean',
            'supports_attendance_webhooks' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<VideoProvider>  $query
     * @return Builder<VideoProvider>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function providerCode(): ?VideoProviderCode
    {
        return VideoProviderCode::tryFrom($this->code);
    }

    /**
     * Whether a credential key holds a non-empty value. The admin screen shows only this, never
     * the value.
     */
    public function hasCredential(string $key): bool
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '';
    }

    public function hasCompleteCredentials(): bool
    {
        $code = $this->providerCode();

        if ($code === null) {
            return false;
        }

        foreach ($code->requiredCredentialKeys() as $key) {
            if (! $this->hasCredential($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why this row may not be the active provider, or null when it may. The fake provider is
     * refused in production (R125); a code with no driver and a driver with missing credentials
     * are refused everywhere.
     */
    public function activationProblem(): ?string
    {
        $code = $this->providerCode();

        if ($code === null || ! $code->hasDriver()) {
            return "There is no driver for {$this->name} yet, so it cannot be made active.";
        }

        if ($code === VideoProviderCode::Fake && app()->environment('production')) {
            return 'The fake video provider cannot be active in production.';
        }

        if (! $this->hasCompleteCredentials()) {
            return "{$this->name} is missing credentials, so it cannot be made active.";
        }

        return null;
    }
}
