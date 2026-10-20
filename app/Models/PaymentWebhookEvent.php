<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One verified gateway webhook event, unique on (gateway_code, event_id): the row is what makes a
 * replay a no-op. Metadata only — `object_ref` is the PaymentIntent or charge id the event is about.
 *
 * @property int $id
 * @property string $gateway_code
 * @property string $event_id
 * @property string $type
 * @property string|null $object_ref
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 */
class PaymentWebhookEvent extends Model
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
