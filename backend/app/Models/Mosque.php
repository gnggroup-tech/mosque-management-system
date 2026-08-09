<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'address', 'region', 'prefecture', 'commune', 'latitude', 'longitude', 'phone', 'email', 'status', 'infrastructures', 'admin_id'])]
class Mosque extends Model
{
    use HasFactory, SoftDeletes;
    protected function casts(): array { return ['infrastructures' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7']; }
    public function administrator(): BelongsTo { return $this->belongsTo(User::class, 'admin_id'); }
    public function councils(): HasMany { return $this->hasMany(MosqueCouncil::class); }
    public function faithful(): HasMany { return $this->hasMany(Faithful::class); }
    public function donations(): HasMany { return $this->hasMany(Donation::class); }
    public function zakatCollections(): HasMany { return $this->hasMany(ZakatCollection::class); }
    public function zakatBeneficiaries(): HasMany { return $this->hasMany(ZakatBeneficiary::class); }
    public function zakatDistributions(): HasMany { return $this->hasMany(ZakatDistribution::class); }
}
