<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['waqf_asset_id', 'receipt_number', 'source', 'amount', 'currency', 'received_at', 'payment_method', 'status', 'notes', 'created_by', 'validated_by', 'validated_at'])]
class WaqfRevenue extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WaqfAsset::class, 'waqf_asset_id');
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
