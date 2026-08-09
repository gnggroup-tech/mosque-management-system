<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'faithful_id', 'receipt_number', 'category', 'assessable_amount', 'rate', 'amount', 'currency', 'payment_method', 'collected_at', 'status', 'is_anonymous', 'payer_name', 'notes', 'created_by', 'validated_by', 'validated_at'])]
class ZakatCollection extends Model
{
    use SoftDeletes;
    protected function casts(): array { return ['assessable_amount' => 'decimal:2', 'rate' => 'decimal:4', 'amount' => 'decimal:2', 'collected_at' => 'datetime', 'validated_at' => 'datetime', 'is_anonymous' => 'boolean']; }
    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function faithful(): BelongsTo { return $this->belongsTo(Faithful::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function validator(): BelongsTo { return $this->belongsTo(User::class, 'validated_by'); }
}
