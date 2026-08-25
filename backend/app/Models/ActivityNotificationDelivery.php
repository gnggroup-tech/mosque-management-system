<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'activity_id', 'user_id', 'type', 'version', 'queue_claimed_at', 'queued_at',
    'sent_at', 'failed_at', 'skipped_at', 'skip_reason', 'attempts',
])]
class ActivityNotificationDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'queue_claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
