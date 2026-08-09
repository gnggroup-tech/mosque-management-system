<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mosque_councils', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->date('mandate_start');
            $table->date('mandate_end');
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mosque_id', 'status']);
            $table->index(['mandate_start', 'mandate_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mosque_councils');
    }
};
