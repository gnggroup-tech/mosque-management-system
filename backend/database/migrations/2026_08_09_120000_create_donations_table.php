<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('faithful_id')->nullable()->constrained('faithful')->nullOnDelete();
            $table->string('receipt_number')->unique();
            $table->string('contribution_type', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->string('payment_method', 30);
            $table->string('payment_reference')->nullable();
            $table->dateTime('received_at');
            $table->string('status', 20)->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->string('donor_name')->nullable();
            $table->string('donor_phone', 30)->nullable();
            $table->string('donor_email')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mosque_id', 'status', 'received_at']);
            $table->index(['mosque_id', 'contribution_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
