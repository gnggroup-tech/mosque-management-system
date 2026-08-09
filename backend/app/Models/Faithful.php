<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'mosque_id', 'user_id', 'registration_number', 'first_name', 'last_name',
    'gender', 'birth_date', 'phone', 'email', 'address', 'occupation',
    'emergency_contact_name', 'emergency_contact_phone', 'joined_at',
    'status', 'notes', 'consent_at', 'created_by',
])]
class Faithful extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'faithful';

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'joined_at' => 'date', 'consent_at' => 'datetime'];
    }

    public function mosque(): BelongsTo { return $this->belongsTo(Mosque::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
