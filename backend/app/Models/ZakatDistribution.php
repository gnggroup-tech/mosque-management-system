<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'zakat_beneficiary_id', 'reference_number', 'category', 'amount', 'currency', 'payment_method', 'distributed_at', 'status', 'purpose', 'supporting_document', 'created_by', 'validated_by', 'validated_at'])]
class ZakatDistribution extends Model
{
    use SoftDeletes;
    protected function casts(): array { return ['amount' => 'decimal:2', 'distributed_at' => 'datetime', 'validated_at' => 'datetime']; }
    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function beneficiary(): BelongsTo { return $this->belongsTo(ZakatBeneficiary::class, 'zakat_beneficiary_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function validator(): BelongsTo { return $this->belongsTo(User::class, 'validated_by'); }
}
