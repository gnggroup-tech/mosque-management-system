<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'registration_number', 'name', 'type', 'description', 'address', 'estimated_value', 'currency', 'dedicated_at', 'deed_reference', 'status', 'created_by'])]
class WaqfAsset extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['estimated_value' => 'decimal:2', 'dedicated_at' => 'date'];
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(WaqfRevenue::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(WaqfExpense::class);
    }
}
