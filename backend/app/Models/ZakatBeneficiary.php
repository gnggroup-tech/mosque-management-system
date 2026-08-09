<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_id', 'faithful_id', 'beneficiary_number', 'name', 'phone', 'category', 'eligibility_reason', 'status', 'verified_at', 'verified_by'])]
class ZakatBeneficiary extends Model
{
    use SoftDeletes;
    protected function casts(): array { return ['verified_at' => 'date']; }
    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function faithful(): BelongsTo { return $this->belongsTo(Faithful::class); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function distributions(): HasMany { return $this->hasMany(ZakatDistribution::class); }
}
