<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'title', 'body', 'type', 'priority', 'audience', 'status', 'visible_from', 'visible_until', 'published_at', 'created_by'])]
class Announcement extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['visible_from' => 'datetime', 'visible_until' => 'datetime', 'published_at' => 'datetime'];
    }

    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function receipts(): HasMany { return $this->hasMany(AnnouncementReceipt::class); }
}
