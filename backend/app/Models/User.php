<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;
    public function administeredMosques(): HasMany { return $this->hasMany(Mosque::class, 'admin_id'); }
    public function createdCouncils(): HasMany { return $this->hasMany(MosqueCouncil::class, 'created_by'); }
    public function councilMemberships(): HasMany { return $this->hasMany(CouncilMember::class); }
    public function faithfulRecords(): HasMany { return $this->hasMany(Faithful::class); }
    public function createdDonations(): HasMany { return $this->hasMany(Donation::class, 'created_by'); }
    public function validatedDonations(): HasMany { return $this->hasMany(Donation::class, 'validated_by'); }
    protected function casts(): array { return ['email_verified_at' => 'datetime', 'password' => 'hashed']; }
}
