<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'mosque_id', 'title', 'type', 'description', 'location', 'starts_at', 'ends_at',
    'capacity', 'status', 'registration_required', 'created_by', 'published_at',
    'notification_version', 'reminder_queue_claimed_at', 'reminder_queued_at',
])]
class Activity extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'registration_required' => 'boolean',
            'notification_version' => 'integer',
            'reminder_queue_claimed_at' => 'datetime',
            'reminder_queued_at' => 'datetime',
        ];
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(ActivityNotificationDelivery::class);
    }
}
