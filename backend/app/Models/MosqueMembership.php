<?php

namespace App\Models;

use App\Enums\MosqueMembershipType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['mosque_id', 'user_id', 'membership_type', 'assigned_by'])]
class MosqueMembership extends Pivot
{
    protected $table = 'mosque_user';

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    protected function casts(): array
    {
        return ['membership_type' => MosqueMembershipType::class];
    }
}
