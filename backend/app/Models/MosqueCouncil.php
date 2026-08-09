<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'name', 'mandate_start', 'mandate_end', 'status', 'notes', 'created_by'])]
class MosqueCouncil extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['mandate_start' => 'date', 'mandate_end' => 'date'];
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CouncilMember::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(CouncilMeeting::class);
    }
}
