<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['council_meeting_id', 'reference', 'title', 'description', 'outcome', 'votes_for', 'votes_against', 'abstentions', 'responsible_user_id', 'due_date', 'implementation_status'])]
class CouncilDecision extends Model
{
    protected function casts(): array { return ['due_date' => 'date']; }
    public function meeting(): BelongsTo { return $this->belongsTo(CouncilMeeting::class, 'council_meeting_id'); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
