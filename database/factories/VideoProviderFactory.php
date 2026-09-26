<?php

namespace Database\Factories;

use App\Enums\VideoProviderCode;
use App\Models\VideoProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProvider>
 */
class VideoProviderFactory extends Factory
{
    /**
     * A complete, inactive Daily row with obviously fake credentials.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => VideoProviderCode::Daily->value,
            'name' => VideoProviderCode::Daily->label(),
            'credentials' => ['api_key' => 'test-daily-api-key', 'webhook_secret' => base64_encode('test-daily-webhook-secret')],
            'supports_embed' => true,
            'supports_attendance_webhooks' => true,
            'is_active' => false,
        ];
    }

    public function fake(): static
    {
        return $this->state(fn () => [
            'code' => VideoProviderCode::Fake->value,
            'name' => VideoProviderCode::Fake->label(),
            'credentials' => ['webhook_secret' => 'test-fake-webhook-secret'],
            'supports_embed' => false,
            'supports_attendance_webhooks' => false,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
