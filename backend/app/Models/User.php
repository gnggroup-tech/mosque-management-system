<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $attributes = [
        'status' => AccountStatus::Active->value,
    ];

    public function isPendingEmail(): bool
    {
        return $this->status === AccountStatus::PendingEmail;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === AccountStatus::PendingApproval;
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === AccountStatus::Suspended;
    }

    public function isArchived(): bool
    {
        return $this->status === AccountStatus::Archived;
    }

    public function canAuthenticate(): bool
    {
        return $this->isActive();
    }

    public function administeredMosques(): HasMany
    {
        return $this->hasMany(Mosque::class, 'admin_id');
    }

    public function mosques(): BelongsToMany
    {
        return $this->belongsToMany(Mosque::class)
            ->using(MosqueMembership::class)
            ->withPivot(['id', 'membership_type', 'assigned_by'])
            ->withTimestamps();
    }

    public function mosqueMemberships(): HasMany
    {
        return $this->hasMany(MosqueMembership::class);
    }

    public function canAdministerMosque(Mosque|int $mosque): bool
    {
        $mosqueId = $mosque instanceof Mosque ? $mosque->getKey() : $mosque;

        return $this->isActive()
            && $this->hasRole('admin')
            && $this->mosqueMemberships()
                ->where('mosque_id', $mosqueId)
                ->where('membership_type', MosqueMembershipType::Administrator->value)
                ->exists();
    }

    public function invitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class);
    }

    public function sentInvitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class, 'invited_by');
    }

    public function createdCouncils(): HasMany
    {
        return $this->hasMany(MosqueCouncil::class, 'created_by');
    }

    public function councilMemberships(): HasMany
    {
        return $this->hasMany(CouncilMember::class);
    }

    public function faithfulRecords(): HasMany
    {
        return $this->hasMany(Faithful::class);
    }

    public function createdActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'created_by');
    }

    public function activityRegistrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function createdAnnouncements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }

    public function announcementReceipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }

    public function createdDonations(): HasMany
    {
        return $this->hasMany(Donation::class, 'created_by');
    }

    public function validatedDonations(): HasMany
    {
        return $this->hasMany(Donation::class, 'validated_by');
    }

    public function createdZakatCollections(): HasMany
    {
        return $this->hasMany(ZakatCollection::class, 'created_by');
    }

    public function validatedZakatCollections(): HasMany
    {
        return $this->hasMany(ZakatCollection::class, 'validated_by');
    }

    public function createdZakatDistributions(): HasMany
    {
        return $this->hasMany(ZakatDistribution::class, 'created_by');
    }

    public function validatedZakatDistributions(): HasMany
    {
        return $this->hasMany(ZakatDistribution::class, 'validated_by');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'verification_required_at' => 'datetime',
            'verification_exempt_until' => 'datetime',
        ];
    }
}
