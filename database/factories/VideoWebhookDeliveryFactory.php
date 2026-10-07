<?php

namespace Database\Factories;

use App\Models\VideoWebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoWebhookDelivery>
 */
class VideoWebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_code' => 'daily',
            'received_at' => now(),
            'http_status' => 200,
            'outcome' => 'received',
            'event_type' => 'participant.joined',
            'event_id' => 'evt-'.fake()->unique()->numerify('########'),
            'body_length' => 412,
            'top_level_keys' => ['version', 'type', 'id', 'payload', 'event_ts'],
            'payload_keys' => ['room', 'user_id', 'session_id', 'joined_at'],
        ];
    }
}
