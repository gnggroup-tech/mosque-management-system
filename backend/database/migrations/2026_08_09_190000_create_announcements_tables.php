<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('body');
            $table->enum('type', ['official', 'prayer', 'meeting', 'event', 'general'])->default('general');
            $table->enum('priority', ['normal', 'important', 'urgent'])->default('normal');
            $table->enum('audience', ['all', 'administrators', 'faithful'])->default('all');
            $table->enum('status', ['draft', 'published', 'cancelled', 'expired'])->default('draft');
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'status', 'published_at']);
        });

        Schema::create('announcement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_receipts');
        Schema::dropIfExists('announcements');
    }
};
