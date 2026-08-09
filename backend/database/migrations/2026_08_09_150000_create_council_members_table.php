<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_council_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('function');
            $table->string('title')->nullable();
            $table->text('responsibilities')->nullable();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mosque_council_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_members');
    }
};
