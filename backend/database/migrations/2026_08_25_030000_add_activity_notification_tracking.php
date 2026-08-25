<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->unsignedInteger('notification_version')->default(0)->after('published_at');
            $table->timestamp('reminder_queue_claimed_at')->nullable()->after('notification_version');
            $table->timestamp('reminder_queued_at')->nullable()->after('reminder_queue_claimed_at');
            $table->index(['status', 'starts_at', 'reminder_queued_at']);
        });

        Schema::create('activity_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('version');
            $table->timestamp('queue_claimed_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 40)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['activity_id', 'user_id', 'type', 'version'], 'activity_notification_delivery_unique');
            $table->index(['activity_id', 'type', 'version', 'failed_at'], 'activity_notification_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_notification_deliveries');
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['status', 'starts_at', 'reminder_queued_at']);
            $table->dropColumn(['notification_version', 'reminder_queue_claimed_at', 'reminder_queued_at']);
        });
    }
};
