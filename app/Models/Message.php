<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One message, stored already masked (R134): `body` is never the sender's original when
 * `body_masked` is true. Append-only — never edited or deleted; only `read_at` changes, and the
 * database trigger refuses anything else.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_user_id
 * @property string $body
 * @property bool $body_masked
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'sender_user_id', 'body', 'body_masked', 'read_at'];

    protected static function booted(): void
    {
        static::updating(function (Message $message): void {
            if (array_diff(array_keys($message->getDirty()), ['read_at']) !== []) {
                throw new LogicException('messages is append-only: only read_at may change.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('messages is append-only: a message cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_masked' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')->withTrashed();
    }
}
