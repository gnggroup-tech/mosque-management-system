<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'council_meeting_id', 'council_member_id', 'attendance_status', 'responded_at',
    'notice_delivery_version', 'notice_queue_claimed_at', 'notice_queued_at',
    'notice_sent_at', 'notice_failed_at', 'notice_attempts',
])]
class CouncilMeetingParticipant extends Model
{
    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'notice_delivery_version' => 'integer',
            'notice_queue_claimed_at' => 'datetime',
            'notice_queued_at' => 'datetime',
            'notice_sent_at' => 'datetime',
            'notice_failed_at' => 'datetime',
            'notice_attempts' => 'integer',
        ];
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
