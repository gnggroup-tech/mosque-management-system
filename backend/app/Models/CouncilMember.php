<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'mosque_council_id', 'user_id', 'function', 'title', 'responsibilities',
    'started_at', 'ended_at', 'status', 'created_by',
])]
class CouncilMember extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['started_at' => 'date', 'ended_at' => 'date'];
    }

    public function council(): BelongsTo { return $this->belongsTo(MosqueCouncil::class, 'mosque_council_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
