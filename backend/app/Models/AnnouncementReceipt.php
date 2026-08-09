<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['announcement_id', 'user_id', 'delivered_at', 'read_at'])]
class AnnouncementReceipt extends Model
{
    protected function casts(): array { return ['delivered_at' => 'datetime', 'read_at' => 'datetime']; }
    public function announcement(): BelongsTo { return $this->belongsTo(Announcement::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
