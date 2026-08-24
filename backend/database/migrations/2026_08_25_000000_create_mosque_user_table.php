<?php

use App\Services\MosqueMembershipBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mosque_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('membership_type', ['administrator', 'member']);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mosque_id', 'user_id']);
            $table->index(['user_id', 'membership_type']);
            $table->index(['mosque_id', 'membership_type']);
        });

        app(MosqueMembershipBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('mosque_user');
    }
};
