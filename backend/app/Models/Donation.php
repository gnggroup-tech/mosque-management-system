<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'mosque_id', 'faithful_id', 'receipt_number', 'contribution_type', 'amount',
    'currency', 'payment_method', 'payment_reference', 'received_at', 'status',
    'is_anonymous', 'donor_name', 'donor_phone', 'donor_email', 'notes',
    'created_by', 'validated_by', 'validated_at',
])]
class Donation extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
            'validated_at' => 'datetime',
            'is_anonymous' => 'boolean',
        ];
    }

    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function faithful(): BelongsTo { return $this->belongsTo(Faithful::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function validator(): BelongsTo { return $this->belongsTo(User::class, 'validated_by'); }
}
