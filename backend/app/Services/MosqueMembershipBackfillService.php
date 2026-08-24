<?php

namespace App\Services;

use App\Enums\MosqueMembershipType;
use App\Models\MosqueMembership;
use Illuminate\Support\Facades\DB;

class MosqueMembershipBackfillService
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('mosques')
                ->whereNotNull('admin_id')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'admin_id'])
                ->each(fn (object $mosque) => $this->record(
                    (int) $mosque->id,
                    (int) $mosque->admin_id,
                    MosqueMembershipType::Administrator,
                ));

            DB::table('faithful')
                ->whereNotNull('user_id')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['mosque_id', 'user_id'])
                ->each(fn (object $faithful) => $this->record(
                    (int) $faithful->mosque_id,
                    (int) $faithful->user_id,
                    MosqueMembershipType::Member,
                ));

            DB::table('council_members')
                ->join('mosque_councils', 'mosque_councils.id', '=', 'council_members.mosque_council_id')
                ->where('council_members.status', 'active')
                ->where('mosque_councils.status', 'active')
                ->whereNull('council_members.deleted_at')
                ->whereNull('mosque_councils.deleted_at')
                ->orderBy('council_members.id')
                ->get(['mosque_councils.mosque_id', 'council_members.user_id'])
                ->each(fn (object $member) => $this->record(
                    (int) $member->mosque_id,
                    (int) $member->user_id,
                    MosqueMembershipType::Member,
                ));
        });
    }

    private function record(int $mosqueId, int $userId, MosqueMembershipType $type): void
    {
        $membership = MosqueMembership::query()
            ->where('mosque_id', $mosqueId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            MosqueMembership::query()->create([
                'mosque_id' => $mosqueId,
                'user_id' => $userId,
                'membership_type' => $type,
                'assigned_by' => null,
            ]);

            return;
        }

        if ($type === MosqueMembershipType::Administrator
            && $membership->membership_type !== MosqueMembershipType::Administrator) {
            $membership->update(['membership_type' => MosqueMembershipType::Administrator]);
        }
    }
}
