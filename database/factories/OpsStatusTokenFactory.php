<?php

namespace Database\Factories;

use App\Models\OpsStatusToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OpsStatusToken>
 */
class OpsStatusTokenFactory extends Factory
{
    /**
     * A row for a token whose plaintext the test generated itself: `OpsStatusToken::factory()->create(['token_hash' => OpsStatusToken::hashOf($plain)])`.
     * Left alone it hashes a throwaway random string nobody holds.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => OpsStatusToken::hashOf(Str::random(64)),
            'allowed_ips' => null,
            'created_by' => null,
            'revoked_by' => null,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
