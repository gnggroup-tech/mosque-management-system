<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('council_meetings', function (Blueprint $table): void {
            $table->unsignedInteger('notice_version')->default(0)->after('notice_sent_at');
            $table->timestamp('notice_queue_claimed_at')->nullable()->after('notice_version');
            $table->timestamp('notice_queued_at')->nullable()->after('notice_queue_claimed_at');
            $table->timestamp('legacy_notice_recorded_at')->nullable()->after('notice_queued_at');
        });

        DB::table('council_meetings')
            ->whereNotNull('notice_sent_at')
            ->update([
                'legacy_notice_recorded_at' => DB::raw('notice_sent_at'),
                'notice_sent_at' => null,
            ]);

        Schema::table('council_meeting_participants', function (Blueprint $table): void {
            $table->unsignedInteger('notice_delivery_version')->default(0)->after('responded_at');
            $table->timestamp('notice_queue_claimed_at')->nullable()->after('notice_delivery_version');
            $table->timestamp('notice_queued_at')->nullable()->after('notice_queue_claimed_at');
            $table->timestamp('notice_sent_at')->nullable()->after('notice_queued_at');
            $table->timestamp('notice_failed_at')->nullable()->after('notice_sent_at');
            $table->unsignedTinyInteger('notice_attempts')->default(0)->after('notice_failed_at');
            $table->index(['council_meeting_id', 'notice_failed_at']);
        });
    }

    public function down(): void
    {
        DB::table('council_meetings')
            ->whereNull('notice_sent_at')
            ->whereNotNull('legacy_notice_recorded_at')
            ->update(['notice_sent_at' => DB::raw('legacy_notice_recorded_at')]);

        Schema::table('council_meeting_participants', function (Blueprint $table): void {
            $table->dropIndex(['council_meeting_id', 'notice_failed_at']);
            $table->dropColumn([
                'notice_delivery_version',
                'notice_queue_claimed_at',
                'notice_queued_at',
                'notice_sent_at',
                'notice_failed_at',
                'notice_attempts',
            ]);
        });

        Schema::table('council_meetings', function (Blueprint $table): void {
            $table->dropColumn([
                'notice_version',
                'notice_queue_claimed_at',
                'notice_queued_at',
                'legacy_notice_recorded_at',
            ]);
        });
    }
};
