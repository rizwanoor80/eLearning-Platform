<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One verified provider webhook delivery, unique on (provider_code, event_id): the row is what
 * makes a replay a no-op. 7c hangs attendance timestamps on it.
 *
 * @property int $id
 * @property string $provider_code
 * @property string $event_id
 * @property string $type
 * @property string $room_name
 * @property string|null $participant
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 */
class VideoWebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
