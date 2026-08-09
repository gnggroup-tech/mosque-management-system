<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_council_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('agenda');
            $table->dateTime('scheduled_at');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('quorum_required')->default(1);
            $table->string('status')->default('draft');
            $table->text('minutes')->nullable();
            $table->dateTime('notice_sent_at')->nullable();
            $table->dateTime('held_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_council_id', 'scheduled_at']);
        });

        Schema::create('council_meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('council_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('council_member_id')->constrained()->cascadeOnDelete();
            $table->string('attendance_status')->default('invited');
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['council_meeting_id', 'council_member_id']);
        });

        Schema::create('council_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('council_meeting_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('outcome');
            $table->unsignedSmallInteger('votes_for')->default(0);
            $table->unsignedSmallInteger('votes_against')->default(0);
            $table->unsignedSmallInteger('abstentions')->default(0);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('implementation_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_decisions');
        Schema::dropIfExists('council_meeting_participants');
        Schema::dropIfExists('council_meetings');
    }
};
