<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'reference_number', 'category', 'amount', 'currency', 'spent_at', 'purpose', 'supplier', 'invoice_number', 'supporting_document', 'status', 'rejection_reason', 'created_by', 'validated_by', 'validated_at'])]
class Expense extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'spent_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
