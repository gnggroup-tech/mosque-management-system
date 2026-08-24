<?php

namespace App\Models;

use App\Enums\MosqueMembershipType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'address', 'region', 'prefecture', 'commune', 'latitude', 'longitude', 'phone', 'email', 'status', 'infrastructures', 'admin_id'])]
class Mosque extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['infrastructures' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function scopeAdministrableBy(Builder $query, User $user): Builder
    {
        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('superadmin')) {
            return $query;
        }

        if (! $user->hasRole('admin')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('memberships', fn (Builder $memberships) => $memberships
            ->where('user_id', $user->getKey())
            ->where('membership_type', MosqueMembershipType::Administrator->value));
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(MosqueMembership::class)
            ->withPivot(['id', 'membership_type', 'assigned_by'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(MosqueMembership::class);
    }

    public function councils(): HasMany
    {
        return $this->hasMany(MosqueCouncil::class);
    }

    public function faithful(): HasMany
    {
        return $this->hasMany(Faithful::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function zakatCollections(): HasMany
    {
        return $this->hasMany(ZakatCollection::class);
    }

    public function zakatBeneficiaries(): HasMany
    {
        return $this->hasMany(ZakatBeneficiary::class);
    }

    public function zakatDistributions(): HasMany
    {
        return $this->hasMany(ZakatDistribution::class);
    }

    public function waqfAssets(): HasMany
    {
        return $this->hasMany(WaqfAsset::class);
    }

    public function subsidies(): HasMany
    {
        return $this->hasMany(Subsidy::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }
}
