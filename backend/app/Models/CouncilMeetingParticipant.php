<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['council_meeting_id', 'council_member_id', 'attendance_status', 'responded_at'])]
class CouncilMeetingParticipant extends Model
{
    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(CouncilMeeting::class, 'council_meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(CouncilMember::class, 'council_member_id');
    }
}
