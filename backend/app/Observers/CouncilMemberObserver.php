<?php

namespace App\Observers;

use App\Models\CouncilMember;
use App\Services\AuditLogger;

class CouncilMemberObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}
    public function created(CouncilMember $member): void { $this->auditLogger->log('council-member.created', $member, ['council_id' => $member->mosque_council_id, 'user_id' => $member->user_id, 'function' => $member->function]); }
    public function updated(CouncilMember $member): void
    {
        $changes = collect($member->getChanges())->except(['updated_at'])->all();
        if ($changes !== []) { $this->auditLogger->log('council-member.updated', $member, ['changes' => $changes]); }
    }
    public function deleted(CouncilMember $member): void { $this->auditLogger->log('council-member.deleted', $member, ['council_id' => $member->mosque_council_id, 'user_id' => $member->user_id]); }
}
