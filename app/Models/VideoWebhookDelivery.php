<?php

namespace App\Models;

use Database\Factories\VideoWebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request to `POST webhooks/video/{code}`, recorded by `RecordWebhookDelivery` whatever its fate
 * (R180). Metadata only: the event type and id as the sender wrote them (length-capped), the
 * sizes, and the *names* of the top-level and `payload` keys — never a body, a header or a value.
 * Not the attendance record: that is `VideoWebhookEvent`, written only for a verified event.
 *
 * @property int $id
 * @property string $provider_code
 * @property Carbon $received_at
 * @property int|null $http_status
 * @property string|null $outcome
 * @property string|null $event_type
 * @property string|null $event_id
 * @property int $body_length
 * @property list<string>|null $top_level_keys
 * @property list<string>|null $payload_keys
 */
class VideoWebhookDelivery extends Model
{
    /** @use HasFactory<VideoWebhookDeliveryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'top_level_keys' => 'array',
            'payload_keys' => 'array',
        ];
    }
}
