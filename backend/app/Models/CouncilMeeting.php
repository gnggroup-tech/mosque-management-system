<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mosque_council_id', 'title', 'agenda', 'scheduled_at', 'location', 'quorum_required', 'status', 'minutes', 'notice_sent_at', 'held_at', 'created_by'])]
class CouncilMeeting extends Model
{
    use SoftDeletes;
    protected function casts(): array { return ['scheduled_at' => 'datetime', 'notice_sent_at' => 'datetime', 'held_at' => 'datetime']; }
    public function council(): BelongsTo { return $this->belongsTo(MosqueCouncil::class, 'mosque_council_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function participants(): HasMany { return $this->hasMany(CouncilMeetingParticipant::class); }
    public function decisions(): HasMany { return $this->hasMany(CouncilDecision::class); }
}
