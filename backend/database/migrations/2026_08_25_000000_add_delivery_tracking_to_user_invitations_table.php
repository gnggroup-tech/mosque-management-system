<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->unsignedInteger('delivery_version')->default(0)->after('accepted_at');
            $table->timestamp('queue_claimed_at')->nullable()->after('delivery_version');
            $table->timestamp('queued_at')->nullable()->after('queue_claimed_at');
            $table->timestamp('sent_at')->nullable()->after('queued_at');
            $table->timestamp('failed_at')->nullable()->after('sent_at');
            $table->unsignedTinyInteger('delivery_attempts')->default(0)->after('failed_at');
            $table->index(['delivery_version', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropIndex(['delivery_version', 'queued_at']);
            $table->dropColumn([
                'delivery_version',
                'queue_claimed_at',
                'queued_at',
                'sent_at',
                'failed_at',
                'delivery_attempts',
            ]);
        });
    }
};
